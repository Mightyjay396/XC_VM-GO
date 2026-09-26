<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ConnectionAdmission;
use XcVm\Domain\Cluster\ConnectionIngest;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Infrastructure\Redis\RedisManager;

/**
 * Admission at token mint (cluster plan, Phase 6): for a viewer bound for a
 * CONNECTIONS node, MAIN reserves it and cuts the line's open connections to
 * leave room for it and the viewers still in flight, before the node sees it.
 */
final class ConnectionAdmissionTest extends TestCase {
	private const T = 1800000000;

	private TestDb $rDb;

	private int $rNow = self::T;

	/** @var list<array{0: ?int, 1: int, 2: ?int, 3: string}> */
	private array $rCuts = [];

	private static ?int $rRedisPort = null;

	/** @var resource|null */
	private static $rRedisProc = null;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec('CREATE TABLE `cluster_nodes` (`server_id` int, `state` varchar(16), `mode` int, `flows` int)');
		$this->rDb->exec('CREATE TABLE `cluster_reservations` (`id` char(32) PRIMARY KEY, `identity` varchar(96) NOT NULL, `server_id` int NOT NULL, `stream_id` int, `created_at` int NOT NULL, `exp` int NOT NULL)');
		$this->rDb->exec("INSERT INTO `cluster_nodes` VALUES (5, 'active', 1, 74), (6, 'active', 1, 10), (7, 'quarantined', 1, 74), (8, 'active', 0, 74)");
		DatabaseFactory::set($this->rDb);
		ConnectionAdmission::useEnforcer(function (?int $rLine, int $rRoom, ?int $rHMAC, string $rIdentifier): void {
			$this->rCuts[] = [$rLine, $rRoom, $rHMAC, $rIdentifier];
		}, fn(): int => $this->rNow);
	}

	protected function tearDown(): void {
		ConnectionAdmission::useEnforcer(null);
		SettingsManager::set([]);
		DatabaseFactory::reset();
		(new \ReflectionProperty(RedisManager::class, 'instance'))->setValue(null, null);
	}

	public static function tearDownAfterClass(): void {
		if (self::$rRedisProc !== null) {
			proc_terminate(self::$rRedisProc);
			proc_close(self::$rRedisProc);
		}
	}

	/** @param array<string, mixed> $rExtra */
	private function token(string $rUUID, int $rNode, int $rMax = 2, array $rExtra = []): array {
		return $rExtra + ['stream_id' => 100, 'uuid' => $rUUID, 'channel_info' => ['redirect_id' => $rNode, 'originator_id' => null], 'user_info' => ['id' => 42, 'max_connections' => $rMax, 'pair_id' => null]];
	}

	private function admit(array $rToken, array $rSettings = []): bool {
		return ConnectionAdmission::admit($rSettings + ['cluster_api_enabled' => 1, 'create_expiration' => 5, 'redis_handler' => 0], $rToken, '10.0.0.1', 'VLC');
	}

	public function testOnlyLimitedViewersBoundForACONNECTIONSNode(): void {
		$this->assertTrue($this->admit($this->token(str_repeat('a', 32), 5)));
		$this->assertFalse($this->admit($this->token(str_repeat('b', 32), 6)), 'CONNECTIONS off: the node limits at open');
		$this->assertFalse($this->admit($this->token(str_repeat('c', 32), 7)), 'not active');
		$this->assertFalse($this->admit($this->token(str_repeat('d', 32), 8)), 'mode 0');
		$this->assertFalse($this->admit($this->token(str_repeat('e', 32), 5, 0)), 'unlimited line');
		$this->assertFalse($this->admit($this->token(str_repeat('f', 32), 5), ['cluster_api_enabled' => 0]));
		$this->assertFalse($this->admit($this->token('not a uuid!', 5)));
		// Behind a proxy the originator records the viewer; timeshift keeps them at the top.
		$this->assertTrue($this->admit($this->token(str_repeat('g', 32), 99, 2, ['channel_info' => ['redirect_id' => 99, 'originator_id' => 5]])));
		$this->assertSame(5, ConnectionAdmission::nodeOf(['stream' => 1, 'redirect_id' => 5, 'originator_id' => null]));
		$this->assertCount(2, $this->rCuts);
	}

	public function testRoomLeavesSpaceForThisViewerAndTheOnesInFlight(): void {
		$this->admit($this->token(str_repeat('a', 32), 5, 2));
		$this->admit($this->token(str_repeat('b', 32), 5, 2));
		$this->admit($this->token(str_repeat('b', 32), 5, 2)); // the same viewer again (an adaptive variant)
		$this->admit($this->token(str_repeat('c', 32), 5, 2));
		$this->assertSame([1, 0, 0, 0], array_column($this->rCuts, 1), 'room for open connections: max − this − the others in flight');

		// The node reported one of them: it is open now, counted by its store.
		ConnectionAdmission::release(false, '42', str_repeat('a', 32));
		// And the rest expired: the token's life plus 10 s.
		$this->rNow += 5 + ConnectionAdmission::PAD_SEC + 1;
		$this->admit($this->token(str_repeat('d', 32), 5, 2));
		$this->assertSame(1, end($this->rCuts)[1]);
		$this->rDb->query('SELECT `id`, `server_id`, `stream_id` FROM `cluster_reservations`');
		$this->assertSame([['id' => str_repeat('d', 32), 'server_id' => 5, 'stream_id' => 100]], array_map(static fn($r) => array_map(static fn($v) => is_numeric($v) ? (int) $v : $v, $r), $this->rDb->get_rows()));
	}

	public function testPairsAndHmacIdentities(): void {
		$this->admit($this->token(str_repeat('a', 32), 5, 1, ['user_info' => ['id' => 42, 'max_connections' => 1, 'pair_id' => 43]]));
		$this->assertSame([[43, 0, null, ''], [42, 0, null, '']], $this->rCuts, 'the pair too, as the node does at open');
		$this->rCuts = [];
		$this->admit($this->token(str_repeat('b', 32), 5, 3, ['hmac_id' => 3, 'identifier' => 'dev', 'user_info' => ['id' => null, 'max_connections' => 3]]));
		$this->assertSame([[null, 2, 3, 'dev']], $this->rCuts);
		$this->rDb->query("SELECT `identity` FROM `cluster_reservations` WHERE `id` = ?", str_repeat('b', 32));
		$this->assertSame('3_dev', $this->rDb->get_row()['identity'], 'the store\'s own identity for an HMAC viewer');
	}

	public function testIngestReleasesTheReservation(): void {
		$rSql = (string) file_get_contents(dirname(__DIR__, 2) . '/src/bin/install/database.sql');
		preg_match('/CREATE TABLE IF NOT EXISTS `lines_live` \(.*?\) ENGINE=[^;]*;/s', $rSql, $rM);
		$this->rDb->exec((string) preg_replace(['/`activity_id` int\(11\) NOT NULL AUTO_INCREMENT/', '/,\s*PRIMARY KEY \(`activity_id`\)/', '/,\s*(UNIQUE )?KEY `\w+` \([^)]*\)( USING BTREE)?/', '/ COLLATE \w+/', '/\) ENGINE=[^;]*;/'], ['`activity_id` INTEGER PRIMARY KEY AUTOINCREMENT', '', '', '', ');'], $rM[0]));
		SettingsManager::set(['redis_handler' => 0]);
		$rUUID = str_repeat('a', 32);
		$this->admit($this->token($rUUID, 5));
		$this->assertTrue(ConnectionIngest::upsert(5, ['uuid' => $rUUID, 'user_id' => 42, 'stream_id' => 100, 'container' => 'ts', 'date_start' => self::T]));
		$this->rDb->query('SELECT COUNT(*) AS `n` FROM `cluster_reservations`');
		$this->assertSame(0, (int) $this->rDb->get_row()['n']);
	}

	public function testNothingHappensWhenTheStoreIsDown(): void {
		$this->assertFalse($this->admit($this->token(str_repeat('a', 32), 5), ['redis_handler' => 1]));
		$this->assertSame([], $this->rCuts);
	}

	public function testTheRedisScript(): void {
		$rRedis = $this->redis();
		$this->assertSame(0, ConnectionAdmission::reserve(true, '42', 'u1', 15));
		$this->assertSame(1, ConnectionAdmission::reserve(true, '42', 'u2', 15));
		$this->assertSame(1, ConnectionAdmission::reserve(true, '42', 'u2', 15), 'the same viewer is not counted twice');
		$this->assertSame(0, ConnectionAdmission::reserve(true, '43', 'u3', 15), 'another line');
		ConnectionAdmission::release(true, '42', 'u1');
		$this->assertSame(0, ConnectionAdmission::reserve(true, '42', 'u2', 15));
		$this->rNow += 16;
		$this->assertSame(0, ConnectionAdmission::reserve(true, '42', 'u4', 15), 'expired reservations are dropped');
		$this->assertSame(['u4'], $rRedis->zRange('RESV#42', 0, -1));
		$this->assertGreaterThan(0, $rRedis->ttl('RESV#42'));
	}

	private function redis(): \Redis {
		if (!class_exists(\Redis::class) || trim((string) shell_exec('command -v redis-server')) === '') {
			$this->markTestSkipped('redis-server or phpredis not available');
		}
		if (self::$rRedisProc === null) {
			self::$rRedisPort = random_int(20000, 40000);
			$rNull = ['file', '/dev/null', 'w'];
			self::$rRedisProc = proc_open(['redis-server', '--port', (string) self::$rRedisPort, '--bind', '127.0.0.1', '--save', '', '--appendonly', 'no'], [0 => ['file', '/dev/null', 'r'], 1 => $rNull, 2 => $rNull], $rPipes) ?: null;
			for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', self::$rRedisPort); $i++) {
				usleep(50000);
			}
		}
		$rRedis = new \Redis();
		$rRedis->connect('127.0.0.1', (int) self::$rRedisPort, 2.0, null, 0, 2.0);
		$rRedis->flushAll();
		(new \ReflectionProperty(RedisManager::class, 'instance'))->setValue(null, $rRedis);
		(new \ReflectionProperty(RedisManager::class, 'lastPingCheck'))->setValue(null, time());
		return $rRedis;
	}
}
