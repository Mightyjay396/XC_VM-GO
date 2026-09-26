<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Cluster\ClusterBus;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\NonceStore;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\BusServer;

/**
 * The replay cache on the cluster bus, against a real redis-server on a unix
 * socket (as ClusterBusTest), with MySQL (cluster_nonces) as the fallback.
 * Replay protection never gets weaker across a switch between the two, nor
 * across a bus restart that lost its keys.
 */
final class ClusterNonceStoreTest extends TestCase {
	private const T0 = 1800000000000;

	private static ?BusServer $rBus = null;

	private TestDb $rDb;

	public static function setUpBeforeClass(): void {
		self::$rBus = BusServer::start('nonces');
	}

	public static function tearDownAfterClass(): void {
		self::$rBus?->stop();
		self::$rBus = null;
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
		if (self::$rBus !== null && file_exists($this->path('cluster.sock.live'))) {
			@unlink($this->path('cluster.sock'));
			rename($this->path('cluster.sock.live'), $this->path('cluster.sock'));
		}
	}

	/** A file beside the bus socket (the marks live there). */
	private function path(string $rName): string {
		return (self::$rBus?->rDir ?? sys_get_temp_dir()) . '/' . $rName;
	}

	/** The bus, emptied, with no marks from an earlier test. */
	private function bus(): \Redis {
		if (self::$rBus === null) {
			$this->markTestSkipped('redis-server or phpredis not available');
		}
		foreach ([NonceStore::BUS_MARK, NonceStore::SQL_MARK] as $rMark) {
			@unlink($this->path($rMark));
		}
		ClusterBus::useSocket(self::$rBus->socket());
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
		rename($this->path('cluster.sock'), $this->path('cluster.sock.live'));
		touch($this->path('cluster.sock'));
		ClusterBus::useSocket($this->path('cluster.sock'));
		$this->assertNull(ClusterBus::client());
	}

	private function backInReach(): void {
		unlink($this->path('cluster.sock'));
		rename($this->path('cluster.sock.live'), $this->path('cluster.sock'));
		ClusterBus::useSocket($this->path('cluster.sock'));
		$this->assertInstanceOf(\Redis::class, ClusterBus::client());
	}

	private function inMySql(string $rNode, string $rNonce): bool {
		$this->rDb->query('SELECT `exp` FROM `cluster_nonces` WHERE `node` = ? AND `nonce` = ?;', $rNode, $rNonce);
		return $this->rDb->num_rows() > 0;
	}

	private function at(int $rMs): void {
		ClusterClock::fix(self::T0 + $rMs);
	}

	/** The second a mark holds, or null without one. */
	private function markedAt(string $rMark): ?int {
		clearstatcache(true, $this->path($rMark));
		$rAt = @filemtime($this->path($rMark));
		return $rAt === false ? null : $rAt;
	}

	public function testWithoutTheBusNoncesStayInMySql(): void {
		$rNonce = random_bytes(16);
		$this->assertTrue(NonceStore::claim('node-a', $rNonce, self::T0));
		$this->assertTrue($this->inMySql('node-a', $rNonce));
		$this->assertFalse(NonceStore::claim('node-a', $rNonce, self::T0, $rRetry), 'a replay');
		$this->assertNull($rRetry, 'a replay: no retry would pass');
		$this->assertTrue(NonceStore::claim('node-b', $rNonce, self::T0), 'nonces are per node');
		$this->assertDirectoryDoesNotExist(dirname((string) ClusterBus::socket()), 'no bus: nothing is marked');
	}

	public function testOnASettledBusAClaimIsOneAtomicWriteThere(): void {
		$rRedis = $this->settledBus();
		$rNonce = random_bytes(16);
		$this->assertTrue(NonceStore::claim('node-a', $rNonce, self::T0 - 40));
		$this->assertFalse($this->inMySql('node-a', $rNonce), 'no MySQL write');
		$this->assertFalse(NonceStore::claim('node-a', $rNonce, self::T0 - 40, $rRetry), 'a replay, refused by the bus');
		$this->assertNull($rRetry);
		$this->assertTrue(NonceStore::claim('node-b', $rNonce, self::T0 - 40), 'nonces are per node');
		$this->assertEquals(self::T0 + NonceStore::TTL * 1000, $rRedis->zScore('nonce:node-a', bin2hex($rNonce)));
		// Under the bus's volatile-ttl policy only keys with a TTL are evicted.
		$this->assertSame(-1, $rRedis->pttl('nonce:node-a'), 'never evicted');
		$this->assertSame(-1, $rRedis->pttl('nonces_since'));
		$this->assertSame(intdiv(self::T0, 1000), $this->markedAt(NonceStore::BUS_MARK), 'the second the bus last took a claim');

		// Its lifetime: live until TTL, then pruned by the next claim or purge().
		$this->at(NonceStore::TTL * 1000);
		$this->assertFalse(NonceStore::claim('node-a', $rNonce, self::T0 + NonceStore::TTL * 1000 - 90000));
		$this->at(NonceStore::TTL * 1000 + 1);
		NonceStore::purge();
		$this->assertSame(0, $rRedis->exists('nonce:node-a'), 'pruned, and the empty key is gone');
		$this->assertSame((string) (self::T0 - 3600000), $rRedis->get('nonces_since'), 'purge keeps the history start');
	}

	public function testARequestStampedMoreThanTheLeadAheadIsAlsoKeptInMySql(): void {
		$this->settledBus();
		$rAtLead = random_bytes(16);
		$this->assertTrue(NonceStore::claim('node-a', $rAtLead, self::T0 + NonceStore::LEAD_MS));
		$this->assertFalse($this->inMySql('node-a', $rAtLead), 'up to the lead ahead: the bus alone');
		$rPast = random_bytes(16);
		$this->assertTrue(NonceStore::claim('node-a', $rPast, self::T0 + NonceStore::LEAD_MS + 1));
		$this->assertTrue($this->inMySql('node-a', $rPast), 'past it: MySQL too');
	}

	public function testAFreshBusRefusesWhatItCannotVouchForAndWritesMySqlForItsFirstTtl(): void {
		$rRedis = $this->bus();
		$this->assertFalse(NonceStore::claim('node-a', random_bytes(16), self::T0 - 30, $rRetry), 'stamped before the bus first took a claim');
		$this->assertSame((string) self::T0, $rRedis->get('nonces_since'));
		$this->assertSame(NonceStore::LEAD_MS + 1 + NonceStore::RETRY_MARGIN_MS, $rRetry, 'not a replay: a request stamped anew past the floor passes');
		$this->assertFalse(NonceStore::claim('node-a', random_bytes(16), self::T0 + NonceStore::LEAD_MS, $rRetry), 'nor within the lead after it');
		$this->assertSame(NonceStore::LEAD_MS + 1 + NonceStore::RETRY_MARGIN_MS, $rRetry);

		$this->at(1000);
		$rNonce = random_bytes(16);
		$this->assertTrue(NonceStore::claim('node-a', $rNonce, self::T0 + 990, $rRetry));
		$this->assertNull($rRetry);
		$this->assertTrue($this->inMySql('node-a', $rNonce), 'young: MySQL too, where claims from before it are');

		$this->at(NonceStore::TTL * 1000 - 1);
		$rNonce = random_bytes(16);
		$this->assertTrue(NonceStore::claim('node-a', $rNonce, self::T0 + NonceStore::TTL * 1000 - 11));
		$this->assertTrue($this->inMySql('node-a', $rNonce), 'young for all of its first TTL');

		$this->at(NonceStore::TTL * 1000);
		$rNonce = random_bytes(16);
		$this->assertTrue(NonceStore::claim('node-a', $rNonce, self::T0 + NonceStore::TTL * 1000 - 10));
		$this->assertFalse($this->inMySql('node-a', $rNonce), 'settled after TTL');
	}

	public function testAClockStepBackRestartsTheHistory(): void {
		$rRedis = $this->settledBus();
		$rRedis->set('nonces_since', (string) (self::T0 + 1000));
		$this->assertFalse(NonceStore::claim('node-a', random_bytes(16), self::T0 + 1000 + NonceStore::LEAD_MS), 'a second ahead is not a step: its floor holds');
		$this->assertSame((string) (self::T0 + 1000), $rRedis->get('nonces_since'));

		$rRedis->set('nonces_since', (string) (self::T0 + 1001));
		$rNonce = random_bytes(16);
		$this->assertTrue(NonceStore::claim('node-a', $rNonce, self::T0 + 300), 'more than a second ahead: a new history, not refusals until the clock catches up');
		$this->assertSame((string) self::T0, $rRedis->get('nonces_since'));
		$this->assertTrue($this->inMySql('node-a', $rNonce), 'young again');

		$this->at(1000);
		$this->assertFalse(NonceStore::claim('node-a', random_bytes(16), self::T0 + 100, $rRetry), 'the new floor');
		$this->assertSame(NonceStore::RETRY_MARGIN_MS, $rRetry, 'already past it');
		$rNonce = random_bytes(16);
		$this->assertTrue(NonceStore::claim('node-a', $rNonce, self::T0 + 990));
		$this->assertTrue($this->inMySql('node-a', $rNonce));
	}

	public function testANonceMySqlTookWhileTheBusWasOutOfReachIsRefusedWhenItIsBack(): void {
		$this->settledBus();
		$this->outOfReach();
		$rNonce = random_bytes(16);
		$this->assertTrue(NonceStore::claim('node-a', $rNonce, self::T0 - 20));
		$this->assertTrue($this->inMySql('node-a', $rNonce));
		$this->assertSame(intdiv(self::T0, 1000), $this->markedAt(NonceStore::SQL_MARK), 'MySQL took a claim the bus does not hold');

		$this->backInReach();
		$this->assertFalse(NonceStore::claim('node-a', $rNonce, self::T0 - 20, $rRetry), 'the same bus, and the replay is still refused');
		$this->assertNull($rRetry);
		$rNew = random_bytes(16);
		$this->assertTrue(NonceStore::claim('node-a', $rNew, self::T0 - 10));
		$this->assertTrue($this->inMySql('node-a', $rNew), 'MySQL too while its claims can be replayed');

		// The mark counts for TTL + 1 s: its second began up to 1 s before the claim.
		$this->at((NonceStore::TTL + 1) * 1000 + 999);
		$rNew = random_bytes(16);
		$this->assertTrue(NonceStore::claim('node-a', $rNew, self::T0 + (NonceStore::TTL + 1) * 1000 + 989));
		$this->assertTrue($this->inMySql('node-a', $rNew), 'still within the window');

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
		$this->assertFalse(NonceStore::claim('node-a', $rNonce, self::T0 - 20, $rRetry), 'MySQL does not hold it: refused by the bus mark');
		$this->assertSame(1000 + NonceStore::LEAD_MS + NonceStore::RETRY_MARGIN_MS, $rRetry, 'until the end of the marked second and the lead');
		$this->at(500);
		$this->assertFalse(NonceStore::claim('node-a', random_bytes(16), self::T0 + 480), 'the bus took claims this second: nothing stamped before the next one passes');
		$this->assertFalse($this->inMySql('node-a', $rNonce));

		// Marked the second before: the bus may be taking claims right now,
		// some not yet marked, so the refusal runs to the end of this second.
		$this->at(1900);
		$this->assertFalse(NonceStore::claim('node-a', random_bytes(16), self::T0 + 1890, $rRetry));
		$this->assertSame(2000 + NonceStore::LEAD_MS - 1900 + NonceStore::RETRY_MARGIN_MS, $rRetry);

		$this->at(2100);
		$this->assertFalse(NonceStore::claim('node-a', random_bytes(16), self::T0 + 1000 + NonceStore::LEAD_MS - 1, $rRetry), 'within the lead past the marked second');
		$this->assertSame(NonceStore::RETRY_MARGIN_MS, $rRetry);
		$rNew = random_bytes(16);
		$this->assertTrue(NonceStore::claim('node-a', $rNew, self::T0 + 1000 + NonceStore::LEAD_MS), 'the bus has been silent past a second and the lead: MySQL alone');
		$this->assertTrue($this->inMySql('node-a', $rNew));
		$this->assertFalse(NonceStore::claim('node-a', $rNonce, self::T0 - 20), 'the replay stays refused');

		// Another worker still reaches the bus: it marks every second, and this
		// worker refuses until it can see the bus itself.
		touch($this->path(NonceStore::BUS_MARK), intdiv(self::T0, 1000) + 2);
		$this->assertFalse(NonceStore::claim('node-a', random_bytes(16), self::T0 + 2095));
		$this->backInReach();
	}

	public function testABusMarkAheadOfTheClockRefusesNoLongerThanThisSecond(): void {
		$this->settledBus();
		$this->outOfReach();
		touch($this->path(NonceStore::BUS_MARK), intdiv(self::T0, 1000) + 100);
		$this->assertFalse(NonceStore::claim('node-a', random_bytes(16), self::T0 + 1000 + NonceStore::LEAD_MS - 1));
		$this->assertTrue(NonceStore::claim('node-a', random_bytes(16), self::T0 + 1000 + NonceStore::LEAD_MS), 'not until a mark a hundred seconds ahead');
		$this->backInReach();
	}

	public function testTheBusMarkIsWrittenBeforeTheClaimRuns(): void {
		$this->settledBus();
		$this->at(5000);
		$this->assertNull($this->markedAt(NonceStore::BUS_MARK));
		// The bus dies under a connected worker: the claim that finds out fails
		// over to MySQL, and the second it marked before trying still counts.
		$this->assertInstanceOf(\Redis::class, ClusterBus::client());
		self::$rBus->kill();
		try {
			$this->assertFalse(NonceStore::claim('node-a', random_bytes(16), self::T0 + 4990, $rRetry));
			$this->assertSame(intdiv(self::T0, 1000) + 5, $this->markedAt(NonceStore::BUS_MARK));
			$this->assertSame(1000 + NonceStore::LEAD_MS + NonceStore::RETRY_MARGIN_MS, $rRetry);
		} finally {
			self::$rBus->restart();
		}
	}

	public function testAClaimWhoseBusMarkCannotBeWrittenIsRefused(): void {
		$rRedis = $this->settledBus();
		symlink($this->path('no-such-dir/mark'), $this->path(NonceStore::BUS_MARK));
		$this->assertFalse(NonceStore::claim('node-a', random_bytes(16), self::T0 - 20, $rRetry));
		$this->assertNull($rRetry);
		$this->assertSame(1, $rRedis->dbSize(), 'refused before the bus took anything');
		unlink($this->path(NonceStore::BUS_MARK));
		$this->assertTrue(NonceStore::claim('node-a', random_bytes(16), self::T0 - 20));
	}

	public function testAMarkOnlyMovesForwardUnlessTheClockSteppedBack(): void {
		$this->settledBus();
		$rSec = intdiv(self::T0, 1000);
		touch($this->path(NonceStore::BUS_MARK), $rSec + 1);
		$this->assertTrue(NonceStore::claim('node-a', random_bytes(16), self::T0 - 20));
		$this->assertSame($rSec + 1, $this->markedAt(NonceStore::BUS_MARK), 'a worker whose clock read came late does not move it back');
		$this->at(3000);
		$this->assertTrue(NonceStore::claim('node-a', random_bytes(16), self::T0 + 2980));
		$this->assertSame($rSec + 3, $this->markedAt(NonceStore::BUS_MARK), 'forward');
		touch($this->path(NonceStore::BUS_MARK), $rSec + 5);
		$this->assertTrue(NonceStore::claim('node-a', random_bytes(16), self::T0 + 2980));
		$this->assertSame($rSec + 3, $this->markedAt(NonceStore::BUS_MARK), 'more than a second ahead: the clock stepped back');
	}

	public function testABusRestartThatLostItsKeysOpensNoReplayWindow(): void {
		$this->settledBus();
		$rLagging = random_bytes(16);
		$this->assertTrue(NonceStore::claim('node-a', $rLagging, self::T0 - 30));
		$rAhead = random_bytes(16);
		$this->assertTrue(NonceStore::claim('node-a', $rAhead, self::T0 + 60000), 'stamped a minute ahead of MAIN');
		$this->assertTrue($this->inMySql('node-a', $rAhead), 'a request stamped ahead is kept in MySQL too');
		$this->assertFalse($this->inMySql('node-a', $rLagging));

		self::$rBus->restart();
		ClusterBus::useSocket(self::$rBus->socket());
		$this->assertSame(0, (int) ClusterBus::client()?->dbSize(), 'the restarted bus holds nothing');

		$this->at(5000);
		$this->assertFalse(NonceStore::claim('node-a', $rLagging, self::T0 - 30), 'stamped before the new bus began: refused');
		$this->assertFalse(NonceStore::claim('node-a', $rAhead, self::T0 + 60000, $rRetry), 'stamped after it, but MySQL holds it');
		$this->assertNull($rRetry, 'a replay');
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

	public function testChallengesAreIssuedInMySqlAndConsumedOnce(): void {
		$rRedis = $this->settledBus();
		$rValue = random_bytes(16);
		NonceStore::issue('chal:node-a', $rValue);
		$this->assertTrue($this->inMySql('chal:node-a', $rValue), 'in MySQL even with the bus: anyone may ask for one');
		$this->assertSame(1, $rRedis->dbSize(), 'nothing on the bus');
		$this->assertNull($this->markedAt(NonceStore::SQL_MARK), 'nor a mark that would send claims to MySQL');
		$this->assertTrue(NonceStore::consume('chal:node-a', $rValue));
		$this->assertFalse(NonceStore::consume('chal:node-a', $rValue), 'once');
		$this->assertFalse(NonceStore::consume('chal:node-a', random_bytes(16)), 'never issued');

		// Issued without the bus: marked, so its use on the bus is also
		// recorded where a worker without the bus looks.
		$this->outOfReach();
		$rSql = random_bytes(16);
		NonceStore::issue('chal:node-a', $rSql);
		$this->assertTrue($this->inMySql('chal:node-a', $rSql));
		$this->assertSame(intdiv(self::T0, 1000), $this->markedAt(NonceStore::SQL_MARK));
		$this->backInReach();
		$this->assertFalse(NonceStore::consume('chal:node-b', $rSql), 'issued to another node');
		$this->assertTrue(NonceStore::consume('chal:node-a', $rSql));
		$this->outOfReach();
		$this->at(3000);
		$this->assertFalse(NonceStore::consume('chal:node-a', $rSql), 'nor again without the bus');
		$this->backInReach();

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
