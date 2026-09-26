<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\Crypto\Enc;
use XcVm\Core\Cluster\Crypto\PanelSig;
use XcVm\Domain\Cluster\ClusterBus;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\ClusterSemaphore;
use XcVm\Tests\Support\BusServer;
use XcVm\Tests\Support\FakeClusterCrypto;

/**
 * The per-op semaphores on the cluster bus (plan, section 8, "MAIN
 * capacity"): 4 permits each for hello, conn_snapshot, token_rekey and
 * config; and the ingest permits (section 8, "Ordering and backpressure"):
 * cluster_ingest_concurrency in all, half of them kept for P0 events. Against
 * a real redis-server on a unix socket (as ClusterBusTest).
 */
final class ClusterSemaphoreTest extends TestCase {
	private const T0 = 1800000000000;

	private static ?BusServer $rBus = null;

	private FakeClusterCrypto $rCrypto;

	/** @var array{node: string, nonce: string} */
	private array $rH;

	public static function setUpBeforeClass(): void {
		self::$rBus = BusServer::start('sem');
	}

	public static function tearDownAfterClass(): void {
		self::$rBus?->stop();
		self::$rBus = null;
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
		if (self::$rBus === null) {
			$this->markTestSkipped('redis-server or phpredis not available');
		}
		ClusterBus::useSocket(self::$rBus->socket());
		$rRedis = ClusterBus::client();
		$this->assertInstanceOf(\Redis::class, $rRedis);
		$rRedis->flushAll();
		return $rRedis;
	}

	/**
	 * Move every permit of an op to expire $rMs from the bus's now: how a
	 * permit ages without waiting (a negative $rMs has expired).
	 */
	private function expireIn(\Redis $rRedis, string $rOp, int $rMs): void {
		$rAt = BusServer::nowMs($rRedis) + $rMs;
		foreach ($rRedis->zRange('sem:' . $rOp, 0, -1) as $rID) {
			$rRedis->zAdd('sem:' . $rOp, ['XX'], $rAt, $rID);
		}
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
		$this->assertSame(['hello' => 60, 'token_rekey' => 60, 'config' => 90, 'conn_snapshot' => 90], ClusterSemaphore::OPS, 'the ops, and each one\'s worst case: its lane\'s pool timeout');
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
		$rRedis = $this->bus();
		$rBefore = BusServer::nowMs($rRedis);
		for ($i = 0; $i < 4; $i++) {
			ClusterSemaphore::acquire('hello');
			ClusterSemaphore::acquire('config');
		}
		$rAfter = BusServer::nowMs($rRedis);
		foreach (['hello', 'config'] as $rOp) {
			foreach ($rRedis->zRange('sem:' . $rOp, 0, -1, true) as $rExp) {
				$this->assertGreaterThanOrEqual($rBefore + ClusterSemaphore::OPS[$rOp] * 1000, (int) $rExp, $rOp . ': expires after its worst case, by the bus\'s clock');
				$this->assertLessThanOrEqual($rAfter + ClusterSemaphore::OPS[$rOp] * 1000, (int) $rExp);
			}
		}
		$this->expireIn($rRedis, 'hello', 1000);
		$this->assertFalse(ClusterSemaphore::acquire('hello'), 'still held until then');
		$this->expireIn($rRedis, 'hello', -1);
		$this->assertIsString(ClusterSemaphore::acquire('hello'), 'a crashed holder\'s permit expired');
		$this->assertSame(1, $rRedis->zCard('sem:hello'), 'the expired ones are gone');
		$this->assertFalse(ClusterSemaphore::acquire('config'), 'config\'s own permits are untouched');
	}

	public function testPermitsFollowTheBusClockNotTheWorkers(): void {
		$rRedis = $this->bus();
		// Workers read their clocks a few ms apart, and their scripts reach the
		// bus in another order: no permit is dropped as one from the future.
		ClusterClock::fix(self::T0 + 5);
		for ($i = 0; $i < 4; $i++) {
			$this->assertIsString(ClusterSemaphore::acquire('hello'));
		}
		ClusterClock::fix(self::T0);
		$this->assertFalse(ClusterSemaphore::acquire('hello'));
		ClusterClock::fix(self::T0 - 3600000);
		$this->assertFalse(ClusterSemaphore::acquire('hello'), 'even an hour apart');
		$this->assertSame(4, $rRedis->zCard('sem:hello'));
	}

	public function testAPermitFromBeforeTheClockSteppedBackDoesNotHoldForever(): void {
		$rRedis = $this->bus();
		for ($i = 0; $i < 4; $i++) {
			ClusterSemaphore::acquire('hello');
		}
		$this->expireIn($rRedis, 'hello', ClusterSemaphore::OPS['hello'] * 1000 + ClusterSemaphore::STEP_MS - 100);
		$this->assertFalse(ClusterSemaphore::acquire('hello'), 'a step back of under STEP_MS keeps them');
		$this->expireIn($rRedis, 'hello', 3600000);
		$this->assertIsString(ClusterSemaphore::acquire('hello'), 'an expiry past now + the op\'s lifetime + STEP_MS is impossible: dropped');
		$this->assertSame(1, $rRedis->zCard('sem:hello'));
	}

	// ── Ingest permits ───────────────────────────────────────────────────

	public function testTheIngestPermitsSplitTheConcurrencyWithHalfKeptForP0(): void {
		$this->assertSame(['p0' => 3, 'bulk' => 3, 'total' => 6], ClusterSemaphore::ingestPermits(6), 'ceil(n / 2) kept for P0, the rest for anyone');
		$this->assertSame(['p0' => 3, 'bulk' => 2, 'total' => 5], ClusterSemaphore::ingestPermits(5));
		$this->assertSame(['p0' => 1, 'bulk' => 1, 'total' => 2], ClusterSemaphore::ingestPermits(1), 'bulk is never shut out: one P0 and one bulk, as the pool\'s floor');
		$this->assertSame(['p0' => 32, 'bulk' => 32, 'total' => 64], ClusterSemaphore::ingestPermits(1000), 'clamped to the setting\'s range');
		$this->assertSame(ClusterSemaphore::ingestPermits(6), ClusterSemaphore::ingestPermits(null), 'unset: the setting\'s default');
		$this->assertSame(ClusterSemaphore::ingestPermits(6), ClusterSemaphore::ingestPermits('lots'));
		$this->assertSame(ClusterSemaphore::ingestPermits(2), ClusterSemaphore::ingestPermits('2'), 'as the settings row holds it');
	}

	public function testOnlyP0EventsBatchesUseTheReserve(): void {
		$this->assertSame('p0', ClusterSemaphore::ingestLane('events', ['lane' => 'p0']));
		$this->assertSame('bulk', ClusterSemaphore::ingestLane('events', ['lane' => 'p1']));
		$this->assertSame('bulk', ClusterSemaphore::ingestLane('events', ['lane' => 'p2']));
		$this->assertSame('bulk', ClusterSemaphore::ingestLane('events', []), 'a batch that names no lane is not P0');
		foreach (['config', 'conn_snapshot', 'recording_complete', 'rpc_result', 'streams'] as $rOp) {
			$this->assertSame('bulk', ClusterSemaphore::ingestLane($rOp, ['lane' => 'p0']), $rOp . ' is bulk, whatever its payload says');
		}
		foreach (['hello', 'heartbeat', 'commands', 'ack', 'conn_admit', 'token_refresh'] as $rOp) {
			$this->assertNull(ClusterSemaphore::ingestLane($rOp, ['lane' => 'p0']), $rOp . ' is a control op: no ingest permit');
		}
	}

	public function testWithoutTheBusNoIngestPermitIsTaken(): void {
		$this->assertNull(ClusterSemaphore::acquireIngest('bulk', 1));
		$rRan = 0;
		for ($i = 0; $i < 4; $i++) {
			$rRes = ClusterSemaphore::runIngest($this->rCrypto, 'events', 'bulk', 1, $this->rH, function () use (&$rRan): array {
				$rRan++;
				return $this->ok();
			});
			$this->assertSame('handled', $rRes['body']);
		}
		$this->assertSame(4, $rRan, 'as before the bus: no limit');
	}

	public function testP0AlwaysGetsAReservedPermitWhileBulkIsSaturated(): void {
		$rRedis = $this->bus();
		for ($i = 0; $i < 3; $i++) {
			$this->assertIsString(ClusterSemaphore::acquireIngest('bulk', 6));
		}
		$this->assertFalse(ClusterSemaphore::acquireIngest('bulk', 6), 'bulk is refused once only the reserved permits are free');
		for ($i = 0; $i < 3; $i++) {
			$this->assertIsString(ClusterSemaphore::acquireIngest('p0', 6), 'P0 gets the reserve');
		}
		$this->assertFalse(ClusterSemaphore::acquireIngest('p0', 6), 'all 6 held');
		$this->assertSame([3, 3], [$rRedis->zCard('sem:ingest:p0'), $rRedis->zCard('sem:ingest:bulk')]);
		$this->assertSame([-1, -1], [$rRedis->pttl('sem:ingest:p0'), $rRedis->pttl('sem:ingest:bulk')], 'no TTL: never evicted (volatile-ttl)');
		$this->assertIsString(ClusterSemaphore::acquire('config'), 'the per-op semaphores are apart');
	}

	public function testP0MayTakeTheSharedPermitsButBulkNeverTheReserve(): void {
		$this->bus();
		$rHeld = [];
		for ($i = 0; $i < 6; $i++) {
			$rHeld[] = ClusterSemaphore::acquireIngest('p0', 6);
		}
		$this->assertContainsOnly('string', $rHeld, 'P0 may use every permit');
		$this->assertFalse(ClusterSemaphore::acquireIngest('p0', 6));
		$this->assertFalse(ClusterSemaphore::acquireIngest('bulk', 6), 'none free');
		ClusterSemaphore::releaseIngest('p0', (string) $rHeld[0]);
		ClusterSemaphore::releaseIngest('p0', (string) $rHeld[1]);
		$this->assertIsString(ClusterSemaphore::acquireIngest('bulk', 6), 'a shared permit P0 gave back');
		$this->assertIsString(ClusterSemaphore::acquireIngest('bulk', 6));
		$this->assertFalse(ClusterSemaphore::acquireIngest('bulk', 6), 'all 6 held');
	}

	public function testP0KeepsItsReserveWhenTheConcurrencyIsLowered(): void {
		$rRedis = $this->bus();
		for ($i = 0; $i < 3; $i++) {
			ClusterSemaphore::acquireIngest('bulk', 6);
		}
		// Lowered to 2 (1 kept for P0, 1 for anyone) while bulk still holds 3.
		$this->assertFalse(ClusterSemaphore::acquireIngest('bulk', 2));
		$this->assertIsString(ClusterSemaphore::acquireIngest('p0', 2), 'P0 still gets its reserve');
		$this->assertFalse(ClusterSemaphore::acquireIngest('p0', 2), 'past its reserve, with every permit held');
		$this->assertSame([1, 3], [$rRedis->zCard('sem:ingest:p0'), $rRedis->zCard('sem:ingest:bulk')]);
	}

	public function testNoFreeIngestPermitIsASigned503NamingTheOpAndLane(): void {
		$this->bus();
		ClusterSemaphore::acquireIngest('bulk', 2);
		ClusterSemaphore::acquireIngest('p0', 2);
		foreach (['bulk' => [ClusterSemaphore::RETRY_MIN_MS, ClusterSemaphore::RETRY_MAX_MS, 'config'], 'p0' => [ClusterSemaphore::P0_RETRY_MIN_MS, ClusterSemaphore::P0_RETRY_MAX_MS, 'events']] as $rLane => [$rMin, $rMax, $rOp]) {
			$rRes = ClusterSemaphore::runIngest($this->rCrypto, $rOp, $rLane, 2, $this->rH, function (): array {
				$this->fail('the handler does not run without a permit');
			});
			$this->assertSame(503, $rRes['status']);
			$this->assertTrue(PanelSig::verify($this->rCrypto->info()['panel_sign_pub'], 'den', $rRes['body'], (string) Enc::b64urlDecode($rRes['headers']['X-XCVM-Panel-Sig'])), 'panel-signed denial');
			$rDoc = json_decode($rRes['body'], true);
			$this->assertSame('RATE_LIMITED', $rDoc['reason']);
			$this->assertSame($this->rH['node'], $rDoc['node']);
			$this->assertSame(bin2hex($this->rH['nonce']), $rDoc['req_nonce'], 'bound to the request');
			$this->assertSame([$rOp, $rLane], [$rDoc['op'], $rDoc['lane']]);
			$this->assertIsInt($rDoc['retry_after_ms']);
			$this->assertGreaterThanOrEqual($rMin, $rDoc['retry_after_ms'], $rLane);
			$this->assertLessThanOrEqual($rMax, $rDoc['retry_after_ms'], $rLane);
		}
		$this->assertLessThan(ClusterSemaphore::RETRY_MIN_MS, ClusterSemaphore::P0_RETRY_MAX_MS, 'P0 is asked back sooner than bulk');
	}

	public function testTheIngestPermitIsReleasedWhenTheHandlerEndsOrThrows(): void {
		$rRedis = $this->bus();
		foreach (['p0', 'bulk'] as $rLane) {
			$rRes = ClusterSemaphore::runIngest($this->rCrypto, 'events', $rLane, 6, $this->rH, function () use ($rRedis, $rLane): array {
				$this->assertSame(1, $rRedis->zCard('sem:ingest:' . $rLane), 'held while the handler runs');
				return $this->ok();
			});
			$this->assertSame(200, $rRes['status']);
			$this->assertSame(0, $rRedis->zCard('sem:ingest:' . $rLane), 'released');
			try {
				ClusterSemaphore::runIngest($this->rCrypto, 'events', $rLane, 6, $this->rH, static function (): array {
					throw new \RuntimeException('boom');
				});
				$this->fail('the exception propagates');
			} catch (\RuntimeException $rE) {
				$this->assertSame('boom', $rE->getMessage());
			}
			$this->assertSame(0, $rRedis->zCard('sem:ingest:' . $rLane), 'released in finally');
		}
	}

	public function testAnIngestPermitNeverReleasedExpiresAfterTheIngestPoolsTimeout(): void {
		$rRedis = $this->bus();
		$rBefore = BusServer::nowMs($rRedis);
		for ($i = 0; $i < 3; $i++) {
			ClusterSemaphore::acquireIngest('p0', 6);
			ClusterSemaphore::acquireIngest('bulk', 6);
		}
		$rAfter = BusServer::nowMs($rRedis);
		$this->assertSame(90, ClusterSemaphore::INGEST_LIFE, 'the cluster_ingest pool\'s timeout');
		foreach (['ingest:p0', 'ingest:bulk'] as $rKey) {
			foreach ($rRedis->zRange('sem:' . $rKey, 0, -1, true) as $rExp) {
				$this->assertGreaterThanOrEqual($rBefore + 90000, (int) $rExp, $rKey . ': by the bus\'s clock');
				$this->assertLessThanOrEqual($rAfter + 90000, (int) $rExp);
			}
		}
		$this->expireIn($rRedis, 'ingest:bulk', 1000);
		$this->assertFalse(ClusterSemaphore::acquireIngest('bulk', 6), 'still held until then');
		$this->expireIn($rRedis, 'ingest:bulk', -1);
		$this->assertIsString(ClusterSemaphore::acquireIngest('bulk', 6), 'a crashed holder\'s permit expired');
		$this->assertSame(1, $rRedis->zCard('sem:ingest:bulk'), 'the expired ones are gone');

		// P0's expired permits free the shared ones for bulk too: the script prunes both sets.
		$this->assertIsString(ClusterSemaphore::acquireIngest('p0', 6));
		$this->assertIsString(ClusterSemaphore::acquireIngest('p0', 6));
		$this->assertFalse(ClusterSemaphore::acquireIngest('bulk', 6), '5 P0 and 1 bulk: all 6 held');
		$this->expireIn($rRedis, 'ingest:p0', -1);
		$this->assertIsString(ClusterSemaphore::acquireIngest('bulk', 6));
		$this->assertSame(0, $rRedis->zCard('sem:ingest:p0'));

		// One from before the clock stepped back holds no longer than its lifetime + STEP_MS.
		$this->expireIn($rRedis, 'ingest:bulk', 3600000);
		$this->assertIsString(ClusterSemaphore::acquireIngest('p0', 6));
		$this->assertSame([1, 0], [$rRedis->zCard('sem:ingest:p0'), $rRedis->zCard('sem:ingest:bulk')], 'dropped by the other lane\'s script too');
	}
}
