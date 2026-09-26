<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ConnectionAdmission;
use XcVm\Domain\Cluster\ConnectionIngest;
use XcVm\Domain\Cluster\ConnectionLimits;
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

	/** @var list<?string> the uuid each cut spares */
	private array $rCutUUIDs = [];

	private static ?int $rRedisPort = null;

	/** @var resource|null */
	private static $rRedisProc = null;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec('CREATE TABLE `cluster_nodes` (`server_id` int, `state` varchar(16), `mode` int, `flows` int)');
		$this->rDb->exec('CREATE TABLE `cluster_reservations` (`id` char(32) PRIMARY KEY, `identity` varchar(96) NOT NULL, `server_id` int NOT NULL, `stream_id` int, `created_at` int NOT NULL, `exp` int NOT NULL)');
		$this->rDb->exec("INSERT INTO `cluster_nodes` VALUES (5, 'active', 1, 74), (6, 'active', 1, 10), (7, 'quarantined', 1, 74), (8, 'active', 0, 74)");
		DatabaseFactory::set($this->rDb);
		ConnectionAdmission::useEnforcer(function (?int $rLine, int $rRoom, ?int $rHMAC, string $rIdentifier, ?string $rIP = null, ?string $rUA = null, ?string $rUUID = null): void {
			$this->rCuts[] = [$rLine, $rRoom, $rHMAC, $rIdentifier];
			$this->rCutUUIDs[] = $rUUID;
		}, fn(): int => $this->rNow);
	}

	protected function tearDown(): void {
		ConnectionAdmission::useEnforcer(null);
		\XcVm\Domain\Cluster\ClusterBus::useSocket(null);
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

	public function testTheMintedTokenCarriesTheAdmClaimWhenAdmissionApplied(): void {
		$rSettings = ['cluster_api_enabled' => 1, 'create_expiration' => 5, 'redis_handler' => 0];
		$rToken = ConnectionAdmission::admitToken($rSettings, $this->token(str_repeat('a', 32), 5), '10.0.0.1', 'VLC');
		$this->assertSame(['exp' => self::T + 5 + ConnectionAdmission::PAD_SEC, 'sid' => 5], $rToken['adm'], 'the reservation\'s expiry and the node it was made for');
		$this->assertSame(str_repeat('a', 32), $rToken['uuid'], 'the reservation\'s id is the token\'s own uuid');
		// Behind a proxy the claim names the originator, which records the viewer.
		$rProxied = ConnectionAdmission::admitToken($rSettings, $this->token(str_repeat('b', 32), 99, 2, ['channel_info' => ['redirect_id' => 99, 'originator_id' => 5]]), '10.0.0.1', 'VLC');
		$this->assertSame(5, $rProxied['adm']['sid']);
		foreach ([$this->token(str_repeat('c', 32), 6), $this->token(str_repeat('d', 32), 5, 0)] as $rNot) {
			$this->assertArrayNotHasKey('adm', ConnectionAdmission::admitToken($rSettings, $rNot, '10.0.0.1', 'VLC'), 'no admission, no claim: the node asks MAIN (conn_admit)');
		}
		$this->assertArrayNotHasKey('adm', ConnectionAdmission::admitToken(['redis_handler' => 1] + $rSettings, $this->token(str_repeat('e', 32), 5), '10.0.0.1', 'VLC'), 'the store is down: nothing reserved, nothing claimed');
	}

	public function testEveryViewerMintSiteMintsTheClaim(): void {
		$rAuth = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Public/stream/auth.php');
		$this->assertSame(6, substr_count($rAuth, '$rTokenData = ConnectionAdmission::admitToken($rSettings, $rTokenData, $rIP, $rUserAgent);'));
		$this->assertStringNotContainsString('ConnectionAdmission::admit(', $rAuth, 'a token minted after admission carries its claim');
	}

	/** MAIN's side of conn_admit: `lines`, `hmac_keys`, the table store and the limits queue. */
	private function mainTables(): string {
		$this->rDb->exec('CREATE TABLE `lines` (`id` INTEGER PRIMARY KEY, `max_connections` int, `pair_id` int, `enabled` int, `admin_enabled` int, `exp_date` int)');
		$this->rDb->exec('CREATE TABLE `hmac_keys` (`id` INTEGER PRIMARY KEY, `enabled` int)');
		$this->rDb->exec('CREATE TABLE `lines_live` (`activity_id` INTEGER PRIMARY KEY AUTOINCREMENT, `uuid` text, `server_id` int, `user_id` int, `hmac_id` int, `hmac_identifier` text, `hls_end` int DEFAULT 0)');
		$this->rDb->query('INSERT INTO `lines` VALUES (42, 2, 43, 1, 1, NULL), (44, 0, NULL, 1, 1, NULL), (50, 1, NULL, 1, 0, NULL), (51, 1, NULL, 0, 1, NULL), (52, 1, NULL, 1, 1, ?)', self::T);
		$this->rDb->query('INSERT INTO `hmac_keys` VALUES (3, 1), (4, 0)');
		SettingsManager::set(['redis_handler' => 0]);
		$rDir = sys_get_temp_dir() . '/xcvm-admit-' . bin2hex(random_bytes(4)) . '/';
		ConnectionLimits::useQueue($rDir);
		return $rDir;
	}

	private function forNode(array $rRequest, int $rServerID = 5): ?array {
		return ConnectionAdmission::forNode(['cluster_api_enabled' => 1, 'create_expiration' => 5, 'redis_handler' => 0], $rServerID, $rRequest);
	}

	private function reservations(): int {
		$this->rDb->query('SELECT COUNT(*) AS `n` FROM `cluster_reservations`');
		return (int) $this->rDb->get_row()['n'];
	}

	public function testConnAdmitReadsTheLineOnMainNeverTheNodesLimit(): void {
		$rDir = $this->mainTables();
		try {
			$rUUID = str_repeat('a', 32);
			$rOut = $this->forNode(['uuid' => $rUUID, 'line_id' => 42, 'stream_id' => 100, 'ip' => '10.0.0.1', 'ua' => 'VLC', 'max_connections' => 99]);
			$this->assertSame(['admit' => true, 'exp' => self::T + 5 + ConnectionAdmission::PAD_SEC], $rOut);
			$this->rDb->query('SELECT `identity`, `server_id`, `stream_id`, `exp` FROM `cluster_reservations` WHERE `id` = ?', $rUUID);
			$rRow = $this->rDb->get_row();
			$this->assertSame(['42', 5, 100, self::T + 15], [(string) $rRow['identity'], (int) $rRow['server_id'], (int) $rRow['stream_id'], (int) $rRow['exp']], 'reserved for the authenticated node');
			$this->assertSame([], $this->rCuts, 'no cut on the ctl lane: it is queued for MAIN\'s 1 s loop');

			// The loop cuts the line (and its pair) to leave room for this viewer
			// and the ones in flight, from `lines`' limit, never the viewer itself.
			$this->forNode(['uuid' => str_repeat('b', 32), 'line_id' => 42, 'stream_id' => 100, 'ip' => '10.0.0.2', 'ua' => 'Kodi']);
			$this->assertSame(2, ConnectionLimits::drain());
			$this->assertSame([[43, 1, null, ''], [42, 1, null, ''], [43, 0, null, ''], [42, 0, null, '']], $this->rCuts);
			$this->assertSame([$rUUID, $rUUID, str_repeat('b', 32), str_repeat('b', 32)], $this->rCutUUIDs, 'the viewer asking is never cut');
		} finally {
			ConnectionLimits::useQueue(null);
			exec('rm -rf ' . escapeshellarg($rDir));
		}
	}

	public function testAQueuedCutCountsTheViewerOnceItOpened(): void {
		$rDir = $this->mainTables();
		try {
			$rUUID = str_repeat('a', 32);
			$this->forNode(['uuid' => $rUUID, 'line_id' => 42, 'stream_id' => 100, 'ip' => '10.0.0.1', 'ua' => 'VLC']);
			// The node recorded it before the loop ran: open, and so already counted.
			$this->rDb->query('INSERT INTO `lines_live` (`uuid`, `server_id`, `user_id`) VALUES (?, 5, 42)', $rUUID);
			ConnectionLimits::drain();
			$this->assertSame([[43, 2, null, ''], [42, 2, null, '']], $this->rCuts);

			// Another node's viewer under that uuid: not this node's to admit.
			$this->rCuts = [];
			$this->forNode(['uuid' => $rUUID, 'line_id' => 42, 'stream_id' => 100, 'ip' => '10.0.0.1', 'ua' => 'VLC'], 6);
			$this->assertSame(0, ConnectionLimits::drain());
			$this->assertSame([], $this->rCuts);
		} finally {
			ConnectionLimits::useQueue(null);
			exec('rm -rf ' . escapeshellarg($rDir));
		}
	}

	public function testConnAdmitRefusesALineAuthWouldRefuse(): void {
		$rDir = $this->mainTables();
		try {
			$rAsk = static fn(int $rLine) => ['uuid' => str_repeat('c', 32), 'line_id' => $rLine, 'stream_id' => 1, 'ip' => '10.0.0.1', 'ua' => 'VLC'];
			$this->assertSame(['admit' => false, 'exp' => 0, 'reason' => 'UNKNOWN_LINE'], $this->forNode($rAsk(99)));
			$this->assertSame('BANNED', $this->forNode($rAsk(50))['reason']);
			$this->assertSame('DISABLED', $this->forNode($rAsk(51))['reason']);
			$this->assertSame('EXPIRED', $this->forNode($rAsk(52))['reason']);
			$this->assertSame(0, $this->reservations(), 'a refused viewer reserves nothing');
			$this->assertSame(['admit' => true, 'exp' => self::T + 15], $this->forNode($rAsk(44)), 'an unlimited line is admitted');
			$this->assertSame(0, $this->reservations(), 'and needs no reservation');
			$this->assertSame(0, ConnectionLimits::drain());
		} finally {
			ConnectionLimits::useQueue(null);
			exec('rm -rf ' . escapeshellarg($rDir));
		}
	}

	public function testConnAdmitForAnHmacIdentity(): void {
		$rDir = $this->mainTables();
		try {
			$rAsk = static fn(int $rKey) => ['uuid' => str_repeat('h', 32), 'hmac_id' => $rKey, 'identifier' => 'dev', 'stream_id' => 1, 'ip' => '10.0.0.1', 'ua' => 'VLC'];
			$this->assertSame(['admit' => true, 'exp' => self::T + 15], $this->forNode($rAsk(3)));
			$this->rDb->query('SELECT `identity` FROM `cluster_reservations`');
			$this->assertSame('3_dev', $this->rDb->get_row()['identity'], 'reserved, so other admissions count it');
			$this->assertSame(0, ConnectionLimits::drain(), 'MAIN holds no limit for it: the node\'s conn.limit carries the signed one');
			$this->assertSame('UNKNOWN_HMAC', $this->forNode($rAsk(4))['reason'], 'a disabled key');
			$this->assertSame('UNKNOWN_HMAC', $this->forNode($rAsk(9))['reason']);
		} finally {
			ConnectionLimits::useQueue(null);
			exec('rm -rf ' . escapeshellarg($rDir));
		}
	}

	public function testConnAdmitIsIdempotentForARepeatedUuid(): void {
		$rDir = $this->mainTables();
		try {
			$rAsk = ['uuid' => str_repeat('a', 32), 'line_id' => 42, 'stream_id' => 100, 'ip' => '10.0.0.1', 'ua' => 'VLC'];
			$rFirst = $this->forNode($rAsk);
			$this->assertSame($rFirst, $this->forNode($rAsk));
			$this->assertSame(1, $this->reservations(), 'one reservation, refreshed');
			ConnectionLimits::drain();
			$this->assertSame([1, 1, 1, 1], array_column($this->rCuts, 1), 'the viewer never counts itself as in flight');
		} finally {
			ConnectionLimits::useQueue(null);
			exec('rm -rf ' . escapeshellarg($rDir));
		}
	}

	public function testConnAdmitRefusesAMalformedRequest(): void {
		$rDir = $this->mainTables();
		try {
			$rOk = ['uuid' => str_repeat('a', 32), 'line_id' => 42, 'stream_id' => 100, 'ip' => '10.0.0.1', 'ua' => 'VLC'];
			$this->assertNotNull($this->forNode($rOk));
			foreach ([
				['uuid' => 'bad uuid'] + $rOk,
				['hmac_id' => 3, 'identifier' => 'dev'] + $rOk, // both identities
				['line_id' => null] + $rOk,                     // neither
				['line_id' => '42'] + $rOk,
				['stream_id' => -1] + $rOk,
				['ip' => ['x']] + $rOk,
				['uuid' => str_repeat('a', 32), 'hmac_id' => 3, 'stream_id' => 1], // no identifier
			] as $rBad) {
				$this->assertNull($this->forNode($rBad), (string) json_encode($rBad));
			}
		} finally {
			ConnectionLimits::useQueue(null);
			exec('rm -rf ' . escapeshellarg($rDir));
		}
	}

	public function testIngestReleasesTheTokensReservationUnderAnHlsKey(): void {
		$rDir = $this->mainTables();
		ConnectionLimits::useQueue(null);
		exec('rm -rf ' . escapeshellarg($rDir));
		$rSql = (string) file_get_contents(dirname(__DIR__, 2) . '/src/bin/install/database.sql');
		preg_match('/CREATE TABLE IF NOT EXISTS `lines_live` \(.*?\) ENGINE=[^;]*;/s', $rSql, $rM);
		$this->rDb->exec('DROP TABLE `lines_live`');
		$this->rDb->exec((string) preg_replace(['/`activity_id` int\(11\) NOT NULL AUTO_INCREMENT/', '/,\s*PRIMARY KEY \(`activity_id`\)/', '/,\s*(UNIQUE )?KEY `\w+` \([^)]*\)( USING BTREE)?/', '/ COLLATE \w+/', '/\) ENGINE=[^;]*;/'], ['`activity_id` INTEGER PRIMARY KEY AUTOINCREMENT', '', '', '', ');'], $rM[0]));
		$rToken = str_repeat('a', 32);
		$this->admit($this->token($rToken, 5));
		$this->assertSame(1, $this->reservations());
		// live.php records an HLS viewer under its playlist key, not the token's uuid.
		$this->assertTrue(ConnectionIngest::upsert(5, ['uuid' => str_repeat('f', 32), 'adm_uuid' => $rToken, 'user_id' => 42, 'stream_id' => 100, 'container' => 'hls', 'date_start' => self::T]));
		$this->assertSame(0, $this->reservations(), 'the token\'s reservation is released with it');
		$this->rDb->query('SELECT COUNT(*) AS `n` FROM `lines_live` WHERE `uuid` = ?', str_repeat('f', 32));
		$this->assertSame(1, (int) $this->rDb->get_row()['n'], 'adm_uuid is not a store column');
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
			self::$rRedisProc = proc_open(['redis-server', '--port', (string) self::$rRedisPort, '--bind', '127.0.0.1', '--unixsocket', sys_get_temp_dir() . '/xcvm-adm-' . self::$rRedisPort . '.sock', '--save', '', '--appendonly', 'no'], [0 => ['file', '/dev/null', 'r'], 1 => $rNull, 2 => $rNull], $rPipes) ?: null;
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

	public function testReservationsGoToTheClusterBusWhenItRuns(): void {
		$rRedis = $this->redis();
		\XcVm\Domain\Cluster\ClusterBus::useSocket(sys_get_temp_dir() . '/xcvm-adm-' . self::$rRedisPort . '.sock');
		// MySQL mode, yet the bus holds them: the table stays empty.
		$this->assertSame(0, ConnectionAdmission::reserve(false, '42', str_repeat('a', 32), 15));
		$this->assertSame(1, ConnectionAdmission::reserve(false, '42', str_repeat('b', 32), 15));
		$this->rDb->query('SELECT COUNT(*) AS `n` FROM `cluster_reservations`');
		$this->assertSame(0, (int) $this->rDb->get_row()['n']);
		$this->assertSame(2, $rRedis->zCard('RESV#42'));
		ConnectionAdmission::release(false, '42', str_repeat('a', 32));
		$this->assertSame([str_repeat('b', 32)], $rRedis->zRange('RESV#42', 0, -1));
	}
}
