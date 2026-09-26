<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Cluster\ClusterBus;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\NonceStore;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * The replay cache on the cluster bus, against a real redis-server on a unix
 * socket (as ClusterBusTest), with MySQL (cluster_nonces) as the fallback.
 * Replay protection never gets weaker across a switch between the two, nor
 * across a bus restart that lost its keys.
 */
final class ClusterNonceStoreTest extends TestCase {
	private const T0 = 1800000000000;

	private static ?string $rDir = null;

	/** @var resource|null */
	private static $rProc = null;

	private TestDb $rDb;

	public static function setUpBeforeClass(): void {
		if (!class_exists(\Redis::class) || trim((string) shell_exec('command -v redis-server')) === '') {
			return;
		}
		self::$rDir = sys_get_temp_dir() . '/xcvm-nonces-' . bin2hex(random_bytes(4));
		mkdir(self::$rDir);
		self::startBus();
	}

	public static function tearDownAfterClass(): void {
		self::stopBus();
		if (self::$rDir !== null) {
			exec('rm -rf ' . escapeshellarg(self::$rDir));
		}
	}

	private static function startBus(): void {
		// A killed server leaves its socket behind; wait for the new one.
		@unlink(self::$rDir . '/cluster.sock');
		$rNull = ['file', '/dev/null', 'w'];
		self::$rProc = proc_open(['redis-server', '--port', '0', '--unixsocket', self::$rDir . '/cluster.sock', '--unixsocketperm', '700', '--save', '', '--appendonly', 'no', '--dir', self::$rDir], [0 => ['file', '/dev/null', 'r'], 1 => $rNull, 2 => $rNull], $rPipes) ?: null;
		for ($i = 0; $i < 100 && !file_exists(self::$rDir . '/cluster.sock'); $i++) {
			usleep(20000);
		}
	}

	/** Kill the bus: nothing it held survives (it is never persisted). */
	private static function stopBus(): void {
		if (self::$rProc !== null) {
			proc_terminate(self::$rProc, 9);
			proc_close(self::$rProc);
			self::$rProc = null;
		}
	}

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec('CREATE TABLE `cluster_nonces` (`node` varchar(48) NOT NULL, `nonce` binary(16) NOT NULL, `exp` int NOT NULL, PRIMARY KEY (`node`, `nonce`))');
		DatabaseFactory::set($this->rDb);
		ClusterClock::fix(self::T0);
		ClusterBus::useSocket(sys_get_temp_dir() . '/no-such-bus-' . bin2hex(random_bytes(4)) . '/cluster.sock');
	}

	protected function tearDown(): void {
		ClusterClock::fix(null);
		DatabaseFactory::reset();
		ClusterBus::useSocket(null);
		// A test that failed out of reach leaves the socket moved aside.
		if (self::$rDir !== null && file_exists(self::$rDir . '/cluster.sock.live')) {
			@unlink(self::$rDir . '/cluster.sock');
			rename(self::$rDir . '/cluster.sock.live', self::$rDir . '/cluster.sock');
		}
	}

	/** The bus, emptied, with no marks from an earlier test. */
	private function bus(): \Redis {
		if (self::$rProc === null) {
			$this->markTestSkipped('redis-server or phpredis not available');
		}
		foreach ([NonceStore::BUS_MARK, NonceStore::SQL_MARK] as $rMark) {
			@unlink(self::$rDir . '/' . $rMark);
		}
		ClusterBus::useSocket(self::$rDir . '/cluster.sock');
		$rRedis = ClusterBus::client();
		$this->assertInstanceOf(\Redis::class, $rRedis);
		$rRedis->flushAll();
		return $rRedis;
	}

	/** A bus that has been taking claims for an hour. */
	private function settledBus(): \Redis {
		$rRedis = $this->bus();
		$rRedis->set('nonces_since', (string) (self::T0 - 3600000));
		return $rRedis;
	}

	/**
	 * The bus keeps running, but this worker cannot reach it: its socket is
	 * moved aside and a plain file stands in its place.
	 */
	private function outOfReach(): void {
		rename(self::$rDir . '/cluster.sock', self::$rDir . '/cluster.sock.live');
		touch(self::$rDir . '/cluster.sock');
		ClusterBus::useSocket(self::$rDir . '/cluster.sock');
		$this->assertNull(ClusterBus::client());
	}

	private function backInReach(): void {
		unlink(self::$rDir . '/cluster.sock');
		rename(self::$rDir . '/cluster.sock.live', self::$rDir . '/cluster.sock');
		ClusterBus::useSocket(self::$rDir . '/cluster.sock');
		$this->assertInstanceOf(\Redis::class, ClusterBus::client());
	}

	private function inMySql(string $rNode, string $rNonce): bool {
		$this->rDb->query('SELECT `exp` FROM `cluster_nonces` WHERE `node` = ? AND `nonce` = ?;', $rNode, $rNonce);
		return $this->rDb->num_rows() > 0;
	}

	private function at(int $rMs): void {
		ClusterClock::fix(self::T0 + $rMs);
	}

	public function testWithoutTheBusNoncesStayInMySql(): void {
		$rNonce = random_bytes(16);
		$this->assertTrue(NonceStore::claim('node-a', $rNonce, self::T0));
		$this->assertTrue($this->inMySql('node-a', $rNonce));
		$this->assertFalse(NonceStore::claim('node-a', $rNonce, self::T0), 'a replay');
		$this->assertTrue(NonceStore::claim('node-b', $rNonce, self::T0), 'nonces are per node');
		$this->assertDirectoryDoesNotExist(dirname((string) ClusterBus::socket()), 'no bus: nothing is marked');
	}

	public function testOnASettledBusAClaimIsOneAtomicWriteThere(): void {
		$rRedis = $this->settledBus();
		$rNonce = random_bytes(16);
		$this->assertTrue(NonceStore::claim('node-a', $rNonce, self::T0 - 40));
		$this->assertFalse($this->inMySql('node-a', $rNonce), 'no MySQL write');
		$this->assertFalse(NonceStore::claim('node-a', $rNonce, self::T0 - 40), 'a replay, refused by the bus');
		$this->assertTrue(NonceStore::claim('node-b', $rNonce, self::T0 - 40), 'nonces are per node');
		$this->assertEquals(self::T0 + NonceStore::TTL * 1000, $rRedis->zScore('nonce:node-a', bin2hex($rNonce)));
		// Under the bus's volatile-ttl policy only keys with a TTL are evicted.
		$this->assertSame(-1, $rRedis->pttl('nonce:node-a'), 'never evicted');
		$this->assertSame(-1, $rRedis->pttl('nonces_since'));
		$this->assertSame(intdiv(self::T0, 1000), filemtime(self::$rDir . '/' . NonceStore::BUS_MARK), 'the second the bus last took a claim');

		// Its lifetime: live until TTL, then pruned by the next claim or purge().
		$this->at(NonceStore::TTL * 1000);
		$this->assertFalse(NonceStore::claim('node-a', $rNonce, self::T0 + NonceStore::TTL * 1000 - 90000));
		$this->at(NonceStore::TTL * 1000 + 1);
		NonceStore::purge();
		$this->assertSame(0, $rRedis->exists('nonce:node-a'), 'pruned, and the empty key is gone');
		$this->assertSame((string) (self::T0 - 3600000), $rRedis->get('nonces_since'), 'purge keeps the history start');
	}

	public function testAFreshBusRefusesWhatItCannotVouchForAndWritesMySqlForItsFirstTtl(): void {
		$rRedis = $this->bus();
		$this->assertFalse(NonceStore::claim('node-a', random_bytes(16), self::T0 - 30), 'stamped before the bus first took a claim');
		$this->assertSame((string) self::T0, $rRedis->get('nonces_since'));
		$this->assertFalse(NonceStore::claim('node-a', random_bytes(16), self::T0 + NonceStore::LEAD_MS), 'nor within the lead after it');

		$this->at(1000);
		$rNonce = random_bytes(16);
		$this->assertTrue(NonceStore::claim('node-a', $rNonce, self::T0 + 990));
		$this->assertTrue($this->inMySql('node-a', $rNonce), 'young: MySQL too, where claims from before it are');

		$this->at(NonceStore::TTL * 1000);
		$rNonce = random_bytes(16);
		$this->assertTrue(NonceStore::claim('node-a', $rNonce, self::T0 + NonceStore::TTL * 1000 - 10));
		$this->assertFalse($this->inMySql('node-a', $rNonce), 'settled after TTL');
	}

	public function testANonceMySqlTookWhileTheBusWasOutOfReachIsRefusedWhenItIsBack(): void {
		$this->settledBus();
		$this->outOfReach();
		$rNonce = random_bytes(16);
		$this->assertTrue(NonceStore::claim('node-a', $rNonce, self::T0 - 20));
		$this->assertTrue($this->inMySql('node-a', $rNonce));
		$this->assertSame(intdiv(self::T0, 1000), filemtime(self::$rDir . '/' . NonceStore::SQL_MARK), 'MySQL took a claim the bus does not hold');

		$this->backInReach();
		$this->assertFalse(NonceStore::claim('node-a', $rNonce, self::T0 - 20), 'the same bus, and the replay is still refused');
		$rNew = random_bytes(16);
		$this->assertTrue(NonceStore::claim('node-a', $rNew, self::T0 - 10));
		$this->assertTrue($this->inMySql('node-a', $rNew), 'MySQL too while its claims can be replayed');

		$this->at((NonceStore::TTL + 2) * 1000);
		$rNew = random_bytes(16);
		$this->assertTrue(NonceStore::claim('node-a', $rNew, self::T0 + (NonceStore::TTL + 2) * 1000 - 10));
		$this->assertFalse($this->inMySql('node-a', $rNew), 'the bus alone once nothing MySQL took can pass the window');
	}

	public function testANonceTheBusTookIsRefusedWhileTheBusIsOutOfReach(): void {
		$this->settledBus();
		$rNonce = random_bytes(16);
		$this->assertTrue(NonceStore::claim('node-a', $rNonce, self::T0 - 20));
		$this->outOfReach();
		$this->assertFalse(NonceStore::claim('node-a', $rNonce, self::T0 - 20), 'MySQL does not hold it: refused by the bus mark');
		$this->at(500);
		$this->assertFalse(NonceStore::claim('node-a', random_bytes(16), self::T0 + 480), 'the bus took claims this second: nothing stamped before the next one passes');
		$this->assertFalse($this->inMySql('node-a', $rNonce));

		$this->at(2000);
		$this->assertTrue(NonceStore::claim('node-a', random_bytes(16), self::T0 + 1990), 'the bus has been silent past a second and the lead: MySQL alone');
		$this->assertFalse(NonceStore::claim('node-a', $rNonce, self::T0 - 20), 'the replay stays refused');

		// Another worker still reaches the bus: it marks every second, and this
		// worker refuses until it can see the bus itself.
		touch(self::$rDir . '/' . NonceStore::BUS_MARK, intdiv(self::T0, 1000) + 2);
		$this->assertFalse(NonceStore::claim('node-a', random_bytes(16), self::T0 + 1995));
		$this->backInReach();
	}

	public function testABusRestartThatLostItsKeysOpensNoReplayWindow(): void {
		$this->settledBus();
		$rLagging = random_bytes(16);
		$this->assertTrue(NonceStore::claim('node-a', $rLagging, self::T0 - 30));
		$rAhead = random_bytes(16);
		$this->assertTrue(NonceStore::claim('node-a', $rAhead, self::T0 + 60000), 'stamped a minute ahead of MAIN');
		$this->assertTrue($this->inMySql('node-a', $rAhead), 'a request stamped ahead is kept in MySQL too');
		$this->assertFalse($this->inMySql('node-a', $rLagging));

		self::stopBus();
		self::startBus();
		ClusterBus::useSocket(self::$rDir . '/cluster.sock');
		$this->assertSame(0, (int) ClusterBus::client()?->dbSize(), 'the restarted bus holds nothing');

		$this->at(5000);
		$this->assertFalse(NonceStore::claim('node-a', $rLagging, self::T0 - 30), 'stamped before the new bus began: refused');
		$this->assertFalse(NonceStore::claim('node-a', $rAhead, self::T0 + 60000), 'stamped after it, but MySQL holds it');
		$this->at(6000);
		$rNew = random_bytes(16);
		$this->assertTrue(NonceStore::claim('node-a', $rNew, self::T0 + 5990), 'new requests pass');
		$this->assertTrue($this->inMySql('node-a', $rNew), 'young: MySQL too');

		// A bus emptied while running (FLUSHALL) is the same case.
		$this->at(200000);
		$rRedis = ClusterBus::client();
		$this->assertInstanceOf(\Redis::class, $rRedis);
		$rNonce = random_bytes(16);
		$this->assertTrue(NonceStore::claim('node-a', $rNonce, self::T0 + 199990));
		$rRedis->flushAll();
		$this->assertFalse(NonceStore::claim('node-a', $rNonce, self::T0 + 199990));
	}

	public function testChallengesIssuedInEitherStoreAreConsumedOnce(): void {
		$this->settledBus();
		$this->outOfReach();
		$rSql = random_bytes(16);
		NonceStore::issue('chal:node-a', $rSql);
		$this->assertTrue($this->inMySql('chal:node-a', $rSql), 'without the bus: MySQL');
		$this->backInReach();
		$this->assertTrue(NonceStore::consume('chal:node-a', $rSql), 'found in MySQL with the bus back');
		$this->assertFalse(NonceStore::consume('chal:node-a', $rSql), 'once');
		$this->outOfReach();
		$this->at(3000);
		$this->assertFalse(NonceStore::consume('chal:node-a', $rSql), 'nor again without the bus');
		$this->backInReach();

		$rBus = random_bytes(16);
		NonceStore::issue('chal:node-a', $rBus);
		$this->assertFalse($this->inMySql('chal:node-a', $rBus), 'with the bus: the bus');
		$this->assertTrue(NonceStore::consume('chal:node-a', $rBus));
		$this->assertFalse(NonceStore::consume('chal:node-a', $rBus), 'once');
		$this->assertFalse(NonceStore::consume('chal:node-a', random_bytes(16)), 'never issued');
		$this->assertFalse(NonceStore::consume('chal:node-b', $rBus), 'issued to another node');

		$rLate = random_bytes(16);
		NonceStore::issue('chal:node-a', $rLate);
		$this->at(3000 + NonceStore::TTL * 1000 + 1000);
		$this->assertFalse(NonceStore::consume('chal:node-a', $rLate), 'expired');
	}

	public function testWithoutTheBusMySqlIsPurged(): void {
		NonceStore::claim('node-a', random_bytes(16), self::T0);
		$this->at((NonceStore::TTL + 1) * 1000);
		NonceStore::purge();
		$this->rDb->query('SELECT COUNT(*) AS `n` FROM `cluster_nonces`;');
		$this->assertSame(0, (int) $this->rDb->get_row()['n']);
	}
}
