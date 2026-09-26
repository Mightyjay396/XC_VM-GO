<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\Crypto\Enc;
use XcVm\Core\Cluster\Crypto\PanelSig;
use XcVm\Domain\Cluster\ClusterBus;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\ClusterSemaphore;
use XcVm\Tests\Support\FakeClusterCrypto;

/**
 * The per-op semaphores on the cluster bus (plan, section 8, "MAIN
 * capacity"): 4 permits each for hello, conn_snapshot, token_rekey and
 * config, against a real redis-server on a unix socket (as ClusterBusTest).
 */
final class ClusterSemaphoreTest extends TestCase {
	private const T0 = 1800000000000;

	private static ?string $rDir = null;

	/** @var resource|null */
	private static $rProc = null;

	private FakeClusterCrypto $rCrypto;

	/** @var array{node: string, nonce: string} */
	private array $rH;

	public static function setUpBeforeClass(): void {
		if (!class_exists(\Redis::class) || trim((string) shell_exec('command -v redis-server')) === '') {
			return;
		}
		self::$rDir = sys_get_temp_dir() . '/xcvm-sem-' . bin2hex(random_bytes(4));
		mkdir(self::$rDir);
		$rNull = ['file', '/dev/null', 'w'];
		self::$rProc = proc_open(['redis-server', '--port', '0', '--unixsocket', self::$rDir . '/cluster.sock', '--unixsocketperm', '700', '--save', '', '--appendonly', 'no', '--dir', self::$rDir], [0 => ['file', '/dev/null', 'r'], 1 => $rNull, 2 => $rNull], $rPipes) ?: null;
		for ($i = 0; $i < 100 && !file_exists(self::$rDir . '/cluster.sock'); $i++) {
			usleep(20000);
		}
	}

	public static function tearDownAfterClass(): void {
		if (self::$rProc !== null) {
			proc_terminate(self::$rProc);
			proc_close(self::$rProc);
		}
		if (self::$rDir !== null) {
			exec('rm -rf ' . escapeshellarg(self::$rDir));
		}
	}

	protected function setUp(): void {
		ClusterClock::fix(self::T0);
		ClusterBus::useSocket(sys_get_temp_dir() . '/no-such-bus-' . bin2hex(random_bytes(4)) . '.sock');
		$this->rCrypto = new FakeClusterCrypto();
		$this->rH = ['node' => '11111111-2222-4333-a444-555555555555', 'nonce' => random_bytes(16)];
	}

	protected function tearDown(): void {
		ClusterClock::fix(null);
		ClusterBus::useSocket(null);
	}

	private function bus(): \Redis {
		if (self::$rProc === null) {
			$this->markTestSkipped('redis-server or phpredis not available');
		}
		ClusterBus::useSocket(self::$rDir . '/cluster.sock');
		$rRedis = ClusterBus::client();
		$this->assertInstanceOf(\Redis::class, $rRedis);
		$rRedis->flushAll();
		return $rRedis;
	}

	private function ok(): array {
		return ['status' => 200, 'headers' => [], 'body' => 'handled'];
	}

	public function testWithoutTheBusNoOpTakesAPermit(): void {
		$this->assertNull(ClusterSemaphore::acquire('hello'));
		$rRan = 0;
		for ($i = 0; $i < 6; $i++) {
			$rRes = ClusterSemaphore::run($this->rCrypto, 'hello', $this->rH, function () use (&$rRan): array {
				$rRan++;
				return $this->ok();
			});
			$this->assertSame('handled', $rRes['body']);
		}
		$this->assertSame(6, $rRan, 'as before the bus: no limit');
	}

	public function testEachLimitedOpHasFourPermitsOfItsOwn(): void {
		$rRedis = $this->bus();
		$this->assertSame(['hello', 'token_rekey', 'config', 'conn_snapshot'], array_keys(ClusterSemaphore::OPS));
		$this->assertSame(4, ClusterSemaphore::PERMITS);
		$rHeld = [];
		for ($i = 0; $i < 4; $i++) {
			$rHeld[] = ClusterSemaphore::acquire('hello');
		}
		$this->assertContainsOnly('string', $rHeld);
		$this->assertCount(4, array_unique($rHeld));
		$this->assertFalse(ClusterSemaphore::acquire('hello'), 'none free');
		$this->assertIsString(ClusterSemaphore::acquire('config'), 'another op has its own');
		$this->assertNull(ClusterSemaphore::acquire('heartbeat'), 'an op without a semaphore');
		$this->assertSame(-1, $rRedis->pttl('sem:hello'), 'no TTL: never evicted (volatile-ttl)');

		ClusterSemaphore::release('hello', (string) $rHeld[0]);
		$this->assertIsString(ClusterSemaphore::acquire('hello'), 'a released permit is free again');
		$this->assertFalse(ClusterSemaphore::acquire('hello'));
	}

	public function testNoFreePermitIsASigned503WithRetryAfter(): void {
		$this->bus();
		for ($i = 0; $i < 4; $i++) {
			ClusterSemaphore::acquire('conn_snapshot');
		}
		$rRes = ClusterSemaphore::run($this->rCrypto, 'conn_snapshot', $this->rH, function (): array {
			$this->fail('the handler does not run without a permit');
		});
		$this->assertSame(503, $rRes['status']);
		$this->assertTrue(PanelSig::verify($this->rCrypto->info()['panel_sign_pub'], 'den', $rRes['body'], (string) Enc::b64urlDecode($rRes['headers']['X-XCVM-Panel-Sig'])), 'panel-signed denial');
		$rDoc = json_decode($rRes['body'], true);
		$this->assertSame('RATE_LIMITED', $rDoc['reason']);
		$this->assertSame($this->rH['node'], $rDoc['node']);
		$this->assertSame(bin2hex($this->rH['nonce']), $rDoc['req_nonce'], 'bound to the request');
		$this->assertSame('conn_snapshot', $rDoc['op']);
		$this->assertIsInt($rDoc['retry_after_ms']);
		$this->assertGreaterThanOrEqual(ClusterSemaphore::RETRY_MIN_MS, $rDoc['retry_after_ms']);
		$this->assertLessThanOrEqual(ClusterSemaphore::RETRY_MAX_MS, $rDoc['retry_after_ms']);
	}

	public function testThePermitIsReleasedWhenTheHandlerEndsOrThrows(): void {
		$rRedis = $this->bus();
		$rRes = ClusterSemaphore::run($this->rCrypto, 'hello', $this->rH, function () use ($rRedis): array {
			$this->assertSame(1, $rRedis->zCard('sem:hello'), 'held while the handler runs');
			return $this->ok();
		});
		$this->assertSame(200, $rRes['status']);
		$this->assertSame(0, $rRedis->zCard('sem:hello'), 'released');
		try {
			ClusterSemaphore::run($this->rCrypto, 'hello', $this->rH, static function (): array {
				throw new \RuntimeException('boom');
			});
			$this->fail('the exception propagates');
		} catch (\RuntimeException $rE) {
			$this->assertSame('boom', $rE->getMessage());
		}
		$this->assertSame(0, $rRedis->zCard('sem:hello'), 'released in finally');
	}

	public function testAPermitNeverReleasedExpiresAfterTheOpsWorstCase(): void {
		$this->bus();
		for ($i = 0; $i < 4; $i++) {
			ClusterSemaphore::acquire('hello');
			ClusterSemaphore::acquire('config');
		}
		ClusterClock::fix(self::T0 + ClusterSemaphore::OPS['hello'] * 1000);
		$this->assertFalse(ClusterSemaphore::acquire('hello'), 'still held at the limit');
		ClusterClock::fix(self::T0 + ClusterSemaphore::OPS['hello'] * 1000 + 1);
		$this->assertIsString(ClusterSemaphore::acquire('hello'), 'a crashed holder\'s permit expired');
		$this->assertFalse(ClusterSemaphore::acquire('config'), 'config runs longer');
		ClusterClock::fix(self::T0 + ClusterSemaphore::OPS['config'] * 1000 + 1);
		$this->assertIsString(ClusterSemaphore::acquire('config'));
	}

	public function testAPermitFromBeforeTheClockSteppedBackDoesNotHoldForever(): void {
		$this->bus();
		for ($i = 0; $i < 4; $i++) {
			ClusterSemaphore::acquire('hello');
		}
		ClusterClock::fix(self::T0 - 3600000);
		$this->assertIsString(ClusterSemaphore::acquire('hello'), 'an expiry past now + the op\'s lifetime is impossible: dropped');
	}
}
