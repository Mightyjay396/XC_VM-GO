<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Cluster\ClusterBus;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\NodeAuthCache;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Domain\Cluster\TokenService;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\BusServer;
use XcVm\Tests\Support\FakeClusterCrypto;
use XcVm\Tests\Support\QueryLogDb;

/**
 * What a request's authentication reads, kept on the cluster bus
 * (NodeAuthCache; plan, section 8, "MAIN capacity": heartbeats and hot ops
 * do not hit MySQL): the node's row and the epoch record it names, valid for
 * the node's version, which every writer raises after its MySQL write. A
 * fill that read MySQL before a write goes nowhere, and a writer that cannot
 * reach the bus marks the second, after which nothing the bus held counts.
 * Without the bus every request reads MySQL, as before. Against a real
 * redis-server on a unix socket (as ClusterBusTest).
 */
final class ClusterAuthCacheTest extends TestCase {
	private const T0 = 1800000000000;

	private const SID = 5;

	private static ?BusServer $rBus = null;

	private TestDb $rDb;

	private QueryLogDb $rLog;

	/** @var array<int, string> server id => epoch 1's record (binary, as the extension's) */
	private array $rRecord = [];

	public static function tearDownAfterClass(): void {
		self::$rBus?->stop();
		self::$rBus = null;
	}

	protected function setUp(): void {
		ClusterClock::fix(self::T0);
		ClusterBus::useSocket(sys_get_temp_dir() . '/no-such-bus-' . bin2hex(random_bytes(4)) . '.sock');
		$this->rDb = new TestDb();
		$this->rDb->exec((string) preg_replace(['/^--.*$/m', '/,\s*(UNIQUE )?KEY `\w+` \([^)]*\)/', '/ unsigned| COLLATE \w+/', '/\) ENGINE=[^;]*;/'], ['', '', '', ');'], (string) file_get_contents(dirname(__DIR__, 2) . '/src/migrations/database/up/029_create_cluster_nodes.sql')));
		$this->rDb->exec('ALTER TABLE `cluster_node_epochs` ADD COLUMN `agent_eph_pub` binary(32) DEFAULT NULL');
		$this->rDb->exec('ALTER TABLE `cluster_nodes` ADD COLUMN `root_ready` tinyint(1) NOT NULL DEFAULT 0');
		$this->rDb->exec('ALTER TABLE `cluster_nodes` ADD COLUMN `features` varchar(255) DEFAULT NULL');
		$this->rDb->exec('ALTER TABLE `cluster_nodes` ADD COLUMN `main_port` smallint(5) DEFAULT NULL');
		$this->rDb->exec('CREATE TABLE `cluster_audit` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `time` int, `server_id` int, `actor` varchar(64), `event` varchar(64), `detail` text, `ip` varchar(64))');
		DatabaseFactory::set($this->rDb);
		foreach ([5, 6] as $rID) {
			NodeRegistry::startEnrolment($rID, $this->uuid($rID), "\x00\xff" . random_bytes(30), "\xfe\x80" . random_bytes(30), 1);
			NodeRegistry::update($rID, ['state' => 'active', 'epoch' => 1, 'row_mac' => random_bytes(32)]);
			$this->rRecord[$rID] = "\x00\xc3\x28" . random_bytes(96); // not UTF-8
			$this->epoch($rID, 1, $this->rRecord[$rID]);
		}
		$this->rLog = new QueryLogDb($this->rDb);
		DatabaseFactory::set($this->rLog);
	}

	protected function tearDown(): void {
		ClusterClock::fix(null);
		ClusterBus::useSocket(null);
		DatabaseFactory::reset();
	}

	private function uuid(int $rServerID): string {
		return sprintf('00000000-0000-4000-a000-%012d', $rServerID);
	}

	private function epoch(int $rServerID, int $rEpoch, string $rRecord, ?int $rExp = null): void {
		$this->rDb->query(
			'INSERT INTO `cluster_node_epochs` (`server_id`, `epoch`, `record`, `token_sealed`, `nbf`, `exp`, `refresh_at`, `used`, `created_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
			$rServerID,
			$rEpoch,
			$rRecord,
			'sealed-token-' . $rEpoch,
			intdiv(self::T0, 1000) - 120,
			$rExp ?? intdiv(self::T0, 1000) + 3600,
			intdiv(self::T0, 1000) + 1800,
			1,
			intdiv(self::T0, 1000)
		);
	}

	/** The bus, emptied, with no marks from an earlier test. */
	private function bus(): \Redis {
		self::$rBus ??= BusServer::start('auth');
		if (self::$rBus === null) {
			$this->markTestSkipped('redis-server or phpredis not available');
		}
		@unlink(self::$rBus->rDir . '/' . NodeAuthCache::STALE_MARK);
		ClusterBus::useSocket(self::$rBus->socket());
		$rRedis = ClusterBus::client();
		$this->assertInstanceOf(\Redis::class, $rRedis);
		$rRedis->flushAll();
		return $rRedis;
	}

	/** load(), and the statements it sent MySQL. @return array{0: ?array, 1: ?array, 2: list<string>} */
	private function load(int $rServerID, int $rEpoch = 1): array {
		$this->rLog->rQueries = [];
		[$rNode, $rRow] = NodeAuthCache::load($this->uuid($rServerID), $rEpoch);
		return [$rNode, $rRow, $this->rLog->rQueries];
	}

	private function mysqlRow(int $rServerID): array {
		$this->rDb->query('SELECT * FROM `cluster_nodes` WHERE `server_id` = ?', $rServerID);
		return $this->rDb->get_row();
	}

	/** The bus keeps running, but this worker cannot reach it: its socket moved aside, a plain file in its place. */
	private function outOfReach(): void {
		rename(self::$rBus->socket(), self::$rBus->socket() . '.live');
		touch(self::$rBus->socket());
		ClusterBus::useSocket(self::$rBus->socket());
		$this->assertNull(ClusterBus::client());
	}

	private function backInReach(): void {
		unlink(self::$rBus->socket());
		rename(self::$rBus->socket() . '.live', self::$rBus->socket());
		ClusterBus::useSocket(self::$rBus->socket());
		$this->assertInstanceOf(\Redis::class, ClusterBus::client());
	}

	public function testWithoutTheBusEveryRequestReadsMySql(): void {
		foreach ([1, 2] as $rTry) {
			[$rNode, $rRow, $rQueries] = $this->load(self::SID);
			$this->assertSame('active', $rNode['state']);
			$this->assertSame($this->rRecord[self::SID], $rRow['record']);
			$this->assertCount(2, $rQueries, 'the node, then its epoch, as before (' . $rTry . ')');
		}
		[$rNode, $rRow, $rQueries] = $this->load(9);
		$this->assertSame([null, null], [$rNode, $rRow], 'an unknown node');
		$this->assertCount(1, $rQueries);
		NodeAuthCache::forget(self::SID); // no bus socket: nothing to drop, nothing marked
		$this->assertFileDoesNotExist(dirname((string) ClusterBus::socket()) . '/' . NodeAuthCache::STALE_MARK);
	}

	public function testOnTheBusARequestAfterTheFirstAsksMySqlNothing(): void {
		$rRedis = $this->bus();
		[$rNode, $rRow, $rQueries] = $this->load(self::SID);
		$this->assertCount(2, $rQueries, 'the first reads MySQL');
		[$rCached, $rCachedRow, $rQueries] = $this->load(self::SID);
		$this->assertSame([], $rQueries, 'the next asks MySQL nothing');
		$this->assertSame($rRow, $rCachedRow);
		$this->assertSame($this->rRecord[self::SID], $rCachedRow['record'], 'the record, byte for byte');
		$rMySql = $this->mysqlRow(self::SID);
		unset($rMySql['row_mac'], $rMySql['attest']);
		$this->assertSame($rMySql, $rCached, 'the row as MySQL has it, binary keys included');
		$this->assertSame($rNode['node_sign_pub'], $rCached['node_sign_pub']);

		// Keys named after the node, with a short TTL, and nothing the request does not use.
		$rKey = NodeAuthCache::PREFIX . $this->uuid(self::SID);
		foreach ([$rKey, $rKey . ':1'] as $rName) {
			$this->assertGreaterThan(0, $rRedis->pTtl($rName));
			$this->assertLessThanOrEqual(NodeAuthCache::TTL_MS, $rRedis->pTtl($rName));
		}
		$rAll = (string) $rRedis->get($rKey) . (string) $rRedis->get($rKey . ':1');
		$this->assertStringNotContainsString('row_mac', $rAll);
		$this->assertStringNotContainsString('sealed-token', $rAll, 'no token: only the record the extension opens');

		// An unknown node or epoch is never kept: each such request reads MySQL, as before.
		$this->load(9);
		[, $rNone, $rQueries] = $this->load(self::SID, 7);
		$this->assertNull($rNone);
		$this->assertCount(1, $rQueries, 'only the epoch: the node is held');
		$this->assertCount(1, $this->load(self::SID, 7)[2]);
		$this->assertSame(0, $rRedis->exists(NodeAuthCache::PREFIX . $this->uuid(9), $rKey . ':7'));
	}

	public function testANodeWrittenThroughTheRegistryIsReadAgainAndTheOthersStayHeld(): void {
		$this->bus();
		$this->load(self::SID);
		$this->load(6);
		NodeRegistry::update(self::SID, ['state' => 'quarantined', 'quarantine_reason' => 'test']);
		[$rNode, , $rQueries] = $this->load(self::SID);
		$this->assertSame('quarantined', $rNode['state'], 'the next request sees the write');
		$this->assertCount(2, $rQueries);
		$this->assertSame([], $this->load(self::SID)[2], 'and holds it');
		$this->assertSame([], $this->load(6)[2], 'another node stays held');

		// Columns written without the registry (the flush, event cursors, acks) keep the entry.
		NodeRegistry::update(self::SID, ['last_seen_at' => self::T0, 'clock_offset_ms' => 12, 'root_ready' => 1]);
		$this->assertSame([], $this->load(self::SID)[2], 'a write of lagging columns alone keeps it');
	}

	public function testEveryRegistryWriterDropsTheEntry(): void {
		$this->bus();
		$rCrypto = new FakeClusterCrypto();
		$rWriters = [
			'mode' => static fn() => NodeRegistry::update(self::SID, ['mode' => 2]),
			'flows' => static fn() => NodeRegistry::update(self::SID, ['flows' => NodeRegistry::FLOW_TELEMETRY]),
			'epoch' => static fn() => NodeRegistry::update(self::SID, ['epoch' => 1, 'token_exp' => 1]),
			'revoke' => static fn() => NodeRegistry::revoke(self::SID, $rCrypto),
			're-enrolment' => fn() => NodeRegistry::startEnrolment(self::SID, $this->uuid(self::SID), random_bytes(32), random_bytes(32), 1, $rCrypto),
		];
		foreach ($rWriters as $rName => $rWrite) {
			$this->load(self::SID);
			$this->assertSame([], $this->load(self::SID)[2], $rName . ': held');
			$rWrite();
			$this->assertNotSame([], $this->load(self::SID)[2], $rName . ': read again');
		}
		[$rNode, $rRow] = $this->load(self::SID);
		$this->assertSame('enrolling', $rNode['state']);
		$this->assertNull($rRow, 're-enrolment dropped its epochs');
	}

	public function testAnEpochIsReadAloneWhileTheNodeIsHeld(): void {
		$this->bus();
		$this->load(self::SID);
		$this->epoch(self::SID, 2, 'record-2');
		[, $rRow, $rQueries] = $this->load(self::SID, 2);
		$this->assertSame('record-2', $rRow['record']);
		$this->assertCount(1, $rQueries, 'the new epoch alone');
		$this->assertSame([], $this->load(self::SID, 2)[2]);
		$this->assertSame([], $this->load(self::SID, 1)[2], 'both epochs held');
	}

	public function testAnExpiredEpochIsNotServed(): void {
		$this->bus();
		$this->epoch(self::SID, 2, 'record-2', intdiv(self::T0, 1000) + 10);
		$this->assertSame('record-2', $this->load(self::SID, 2)[1]['record']);
		ClusterClock::fix(self::T0 + 10000);
		[$rNode, $rRow, $rQueries] = $this->load(self::SID, 2);
		$this->assertNotNull($rNode);
		$this->assertNull($rRow, 'past its exp, as MySQL answers');
		$this->assertCount(1, $rQueries);
	}

	public function testARevokedNodesEpochIsNeverRead(): void {
		$this->bus();
		NodeRegistry::update(self::SID, ['state' => 'revoked', 'gen' => 2]);
		[$rNode, $rRow, $rQueries] = $this->load(self::SID);
		$this->assertSame(['revoked', null], [$rNode['state'], $rRow]);
		$this->assertCount(1, $rQueries, 'the node alone, as before');
		[$rNode, , $rQueries] = $this->load(self::SID);
		$this->assertSame(['revoked', []], [$rNode['state'], $rQueries]);
	}

	public function testAFillOfARowReadBeforeAWriteGoesNowhere(): void {
		$this->bus();
		$rDb = $this->rDb;
		$rLog = $this->rLog;
		// Between the node's read and its epoch's, another request quarantines it.
		$this->rLog->rBefore = static function (string $rQuery) use ($rDb, $rLog): void {
			if (str_contains($rQuery, 'cluster_node_epochs')) {
				$rLog->rBefore = null;
				DatabaseFactory::set($rDb);
				NodeRegistry::update(self::SID, ['state' => 'quarantined']);
				DatabaseFactory::set($rLog);
			}
		};
		[$rNode] = $this->load(self::SID);
		$this->assertSame('active', $rNode['state'], 'this request read the row before the write, as any request in flight');
		[$rNode, , $rQueries] = $this->load(self::SID);
		$this->assertSame('quarantined', $rNode['state'], 'its fill went nowhere: the next request reads MySQL');
		$this->assertCount(2, $rQueries);

		// Also when the node was held and only its epoch was being filled.
		$this->epoch(self::SID, 2, 'record-2');
		$this->rLog->rBefore = static function () use ($rDb, $rLog): void {
			$rLog->rBefore = null;
			DatabaseFactory::set($rDb);
			NodeRegistry::update(self::SID, ['flows' => NodeRegistry::FLOW_LOGS]);
			DatabaseFactory::set($rLog);
		};
		$this->assertCount(1, $this->load(self::SID, 2)[2]);
		[$rNode, , $rQueries] = $this->load(self::SID, 2);
		$this->assertSame(NodeRegistry::FLOW_LOGS, (int) $rNode['flows']);
		$this->assertCount(2, $rQueries);
	}

	public function testARekeyDropsAnEpochFilledWhileItMinted(): void {
		$this->bus();
		$rLog = $this->rLog;
		// A request at epoch 1 arrives while the re-key mints epoch 2: it fills
		// epoch 1 from MySQL, which still holds it, before the re-key drops it.
		$this->rLog->rBefore = function (string $rQuery) use ($rLog): void {
			if (str_starts_with($rQuery, 'DELETE FROM `cluster_node_epochs`') && str_contains($rQuery, '<>')) {
				$rLog->rBefore = null;
				$this->assertSame($this->rRecord[self::SID], NodeAuthCache::load($this->uuid(self::SID), 1)[1]['record']);
			}
		};
		$rIssued = TokenService::rekey(new FakeClusterCrypto(), (array) NodeRegistry::byServer(self::SID), sodium_crypto_scalarmult_base(random_bytes(32)));
		$this->assertSame(2, (int) $rIssued['epoch']);
		[, $rRow, $rQueries] = $this->load(self::SID, 1);
		$this->assertNull($rRow, 'epoch 1 is gone: the bus does not serve it');
		$this->assertNotSame([], $rQueries);
		$this->assertNotNull($this->load(self::SID, 2)[1]);
	}

	public function testAFillAcrossABusFlushGoesNowhere(): void {
		$rRedis = $this->bus();
		$rLog = $this->rLog;
		// The bus lost what it held between the read and the fill: a request
		// that read MySQL before a write the lost bus was told of fills nothing.
		$this->rLog->rBefore = static function () use ($rRedis, $rLog): void {
			$rLog->rBefore = null;
			$rRedis->flushAll();
		};
		$this->load(self::SID);
		$this->assertSame(0, $rRedis->exists(NodeAuthCache::PREFIX . $this->uuid(self::SID)));
		$this->assertCount(2, $this->load(self::SID)[2]);
		$this->assertSame([], $this->load(self::SID)[2]);
	}

	public function testAWriterThatCannotReachTheBusMarksItAndNothingHeldBeforeCounts(): void {
		$this->bus();
		$this->load(self::SID);
		$this->load(6);
		$this->outOfReach();
		try {
			NodeRegistry::update(self::SID, ['state' => 'quarantined']);
			$this->assertSame(intdiv(self::T0, 1000), ClusterBus::markedAt(NodeAuthCache::STALE_MARK), 'the second the write could not be announced');
			[$rNode, , $rQueries] = $this->load(self::SID);
			$this->assertSame('quarantined', $rNode['state'], 'no bus here: MySQL');
			$this->assertCount(2, $rQueries);
		} finally {
			$this->backInReach();
		}
		foreach ([self::SID, 6] as $rID) {
			$this->assertCount(2, $this->load($rID)[2], 'what the bus held before the mark does not count');
		}
		$this->assertSame('quarantined', $this->load(self::SID)[0]['state']);
		// Nor what is filled within a second of it (a clock that stepped back by one).
		ClusterClock::fix(self::T0 + 1999);
		$this->assertCount(2, $this->load(self::SID)[2]);
		ClusterClock::fix(self::T0 + 2000);
		$this->load(self::SID);
		$this->assertSame([], $this->load(self::SID)[2], 'held again once filled past it');
	}

	public function testAKilledBusFallsBackToMySqlAndItsWritersMarkIt(): void {
		$this->bus();
		$this->load(self::SID);
		self::$rBus->kill(); // its socket is left behind
		try {
			ClusterBus::useSocket(self::$rBus->socket());
			[$rNode, $rRow, $rQueries] = $this->load(self::SID);
			$this->assertSame(['active', $this->rRecord[self::SID]], [$rNode['state'], $rRow['record']]);
			$this->assertCount(2, $rQueries);
			ClusterBus::useSocket(self::$rBus->socket());
			NodeRegistry::update(self::SID, ['mode' => 2]);
			$this->assertSame(intdiv(self::T0, 1000), ClusterBus::markedAt(NodeAuthCache::STALE_MARK));
		} finally {
			self::$rBus->restart();
		}
	}
}
