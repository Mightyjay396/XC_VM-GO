<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Infrastructure\Redis\RedisManager;
use XcVm\Streaming\Protection\ConnectionLimiter;
use XcVm\Tests\Support\BusServer;

if (!defined('SERVER_ID')) {
	define('SERVER_ID', 1);
}
if (!defined('CONS_TMP_PATH')) {
	define('CONS_TMP_PATH', sys_get_temp_dir() . '/xcvm-no-cons/');
}
if (!defined('LOGS_TMP_PATH')) {
	define('LOGS_TMP_PATH', sys_get_temp_dir() . '/xcvm-logs-' . getmypid() . '/');
}

/**
 * Closing a connection in Redis mode, against a real redis-server. A
 * connection that has ended (hls_end = 1) names a PHP worker that PHP-FPM has
 * since given to another request (pm = ondemand, max_requests 40000): the
 * close never kills it. An RTMP play that ended (play_done) leaves nothing
 * behind in Redis, and another server's client with the same id is left alone.
 * The limiter closes each victim once, and an HLS viewer it ends gets one
 * activity row (from its removal), as an HMAC viewer gets one too. An HLS
 * viewer that moves to another server leaves the old server's sets.
 */
final class RedisConnectionCloseTest extends TestCase {
	private ?BusServer $rBus = null;

	private \Redis $rRedis;

	/** @var array{0: mixed, 1: mixed} */
	private array $rGlobals = [null, null];

	/** @var list<resource> */
	private array $rProcs = [];

	protected function setUp(): void {
		$this->rBus = BusServer::start('conns');
		if ($this->rBus === null) {
			$this->markTestSkipped('needs redis-server and phpredis');
		}
		$this->rRedis = new \Redis();
		// Explicit timeouts: other suites set default_socket_timeout to 0, which
		// phpredis would take as its read timeout.
		$this->rRedis->connect($this->rBus->socket(), 0, 2.0, null, 0, 2.0);
		$this->manager($this->rRedis);
		$this->rGlobals = [$GLOBALS['rSettings'] ?? null, $GLOBALS['rServers'] ?? null];
		$GLOBALS['rSettings'] = ['redis_handler' => 1, 'save_closed_connection' => 0];
		$GLOBALS['rServers'] = [SERVER_ID => ['rtmp_mport_url' => 'http://127.0.0.1:9/']];
		DatabaseFactory::set(new TestDb());
	}

	protected function tearDown(): void {
		foreach ($this->rProcs as $rProc) {
			$rStatus = proc_get_status($rProc);
			if ($rStatus['running']) {
				posix_kill($rStatus['pid'], 9);
			}
			proc_close($rProc);
		}
		$this->manager(null);
		[$GLOBALS['rSettings'], $GLOBALS['rServers']] = $this->rGlobals;
		DatabaseFactory::reset();
		$this->rBus?->stop();
	}

	/** Point RedisManager's shared client at the test server (null: none). */
	private function manager(?\Redis $rRedis): void {
		(new \ReflectionProperty(RedisManager::class, 'instance'))->setValue(null, $rRedis);
		(new \ReflectionProperty(RedisManager::class, 'lastPingCheck'))->setValue(null, time());
	}

	/** A process standing in for a PHP-FPM worker. @return array{0: resource, 1: int} */
	private function worker(): array {
		$rNull = ['file', '/dev/null', 'w'];
		$rProc = proc_open(['sleep', '30'], [0 => ['file', '/dev/null', 'r'], 1 => $rNull, 2 => $rNull], $rPipes);
		$this->assertIsResource($rProc);
		$this->rProcs[] = $rProc;
		return [$rProc, (int) proc_get_status($rProc)['pid']];
	}

	/** @param resource $rProc */
	private function running($rProc): bool {
		for ($i = 0; $i < 50; $i++) {
			if (!proc_get_status($rProc)['running']) {
				return false;
			}
			usleep(20000);
		}
		return true;
	}

	/** @return array<string, mixed> the record, as stored */
	private function connection(string $rContainer, int $rPID, int $rEnded, string $rUUID, int $rServerID = SERVER_ID): array {
		$rRecord = ['uuid' => $rUUID, 'identity' => 7, 'user_id' => 7, 'stream_id' => 11, 'server_id' => $rServerID, 'proxy_id' => 0, 'container' => $rContainer, 'pid' => $rPID, 'hls_end' => $rEnded, 'date_start' => 1800000000, 'hls_last_read' => 1800000000, 'user_ip' => '198.51.100.7', 'user_agent' => 'test'];
		$this->assertNotFalse(ConnectionTracker::createConnection($rRecord));
		return $rRecord;
	}

	public function testAnEndedConnectionsWorkerIsNeverKilled(): void {
		[$rProc, $rPID] = $this->worker();
		$rRecord = $this->connection('ts', $rPID, 1, 'ended-ts');
		$this->assertTrue(ConnectionTracker::closeConnection($rRecord));
		$this->assertTrue($this->running($rProc), 'the worker serves someone else now: it lives');
		$this->assertFalse($this->rRedis->get('ended-ts'), 'the record is gone');
		$this->assertFalse($this->rRedis->zScore('LINE#7', 'ended-ts'));
	}

	public function testALiveConnectionsWorkerIsStillKilled(): void {
		[$rProc, $rPID] = $this->worker();
		$this->assertTrue(ConnectionTracker::closeConnection($this->connection('ts', $rPID, 0, 'live-ts')));
		$this->assertFalse($this->running($rProc), 'the worker serving it is killed, as before');
	}

	public function testARecordWithoutHlsEndHasNotEnded(): void {
		$this->assertFalse(ConnectionTracker::ended(['pid' => 5]));
		$this->assertFalse(ConnectionTracker::ended(['hls_end' => 0]));
		$this->assertTrue(ConnectionTracker::ended(['hls_end' => '1']));
	}

	public function testAnRtmpPlayThatEndedLeavesNothingBehind(): void {
		// nosemgrep: php.lang.security.weak-crypto.weak-crypto
		$rUUID = md5('4242');
		$this->connection('rtmp', 4242, 0, $rUUID);
		$this->assertTrue(ConnectionLimiter::closeRTMP('4242'));
		$this->assertFalse($this->rRedis->get($rUUID));
		foreach (['LIVE', 'LINE#7', 'STREAM#11', 'SERVER#' . SERVER_ID, 'SERVER_LINES#' . SERVER_ID] as $rSet) {
			$this->assertFalse($this->rRedis->zScore($rSet, $rUUID), $rSet);
		}
		$this->assertFalse(ConnectionLimiter::closeRTMP('4242'), 'closed once');
	}

	public function testAnotherServersRtmpClientWithTheSameIdIsLeftAlone(): void {
		// nosemgrep: php.lang.security.weak-crypto.weak-crypto
		$rUUID = md5('4243');
		$this->connection('rtmp', 4243, 0, $rUUID, SERVER_ID + 1);
		$this->assertFalse(ConnectionLimiter::closeRTMP('4243'));
		$this->assertNotFalse($this->rRedis->get($rUUID), 'still there');
	}

	/** An HLS viewer of line 7: $rUUID, from $rIP with $rAgent, opened at $rStart. */
	private function viewer(string $rUUID, string $rIP, string $rAgent, int $rStart, int $rServerID = SERVER_ID): void {
		$this->assertNotFalse(ConnectionTracker::createConnection(['uuid' => $rUUID, 'identity' => 7, 'user_id' => 7, 'stream_id' => 11, 'server_id' => $rServerID, 'proxy_id' => 0, 'container' => 'hls', 'pid' => 0, 'hls_end' => 0, 'date_start' => $rStart, 'hls_last_read' => $rStart, 'user_ip' => $rIP, 'user_agent' => $rAgent, 'geoip_country_code' => '', 'isp' => '', 'on_demand' => 0]));
	}

	public function testTheLimiterClosesEachVictimOnce(): void {
		// Limit 1, three open: the requester's own device first, then anyone.
		// The own device's old viewer used to be closed and counted again by
		// the later passes, so the other viewer was never closed.
		$this->viewer('own-old', '198.51.100.7', 'tv', 1800000001);
		$this->viewer('other', '203.0.113.9', 'phone', 1800000002);
		$this->viewer('own-new', '198.51.100.7', 'tv', 1800000003);
		$rWas = $_SERVER['REMOTE_ADDR'] ?? null;
		$_SERVER['REMOTE_ADDR'] = '198.51.100.7'; // the requester, as a stream request has it
		try {
			$this->assertSame(2, ConnectionLimiter::closeConnections(7, 1, null, '', '198.51.100.7', 'tv', 'own-new'));
		} finally {
			$_SERVER['REMOTE_ADDR'] = $rWas;
		}
		$this->assertSame(['own-new'], $this->rRedis->zRange('LINE#7', 0, -1), 'the limit holds');
		$this->assertEqualsCanonicalizing(['own-old', 'other'], $this->rRedis->sMembers('ENDED'));
	}

	/** Activity rows written so far (writeOfflineActivity's spool). */
	private function activityRows(): int {
		return is_file(LOGS_TMP_PATH . 'activity') ? count(file(LOGS_TMP_PATH . 'activity')) : 0;
	}

	public function testAnHlsViewerTheLimiterEndsGetsOneActivityRow(): void {
		// The limiter only ends an HLS viewer (its record stays); what removes
		// the record writes its row. Written by both, it was counted twice.
		@mkdir(LOGS_TMP_PATH, 0777, true);
		$GLOBALS['rSettings']['save_closed_connection'] = 1;
		$this->viewer('kicked', '203.0.113.9', 'phone', 1800000001);
		$this->viewer('stays', '198.51.100.7', 'tv', 1800000002);
		$rBefore = $this->activityRows();

		$this->assertSame(1, ConnectionLimiter::closeConnections(7, 1, null, '', '198.51.100.7', 'tv', 'stays'));
		$this->assertSame($rBefore, $this->activityRows(), 'ended, not written');

		ConnectionTracker::closeConnection(ConnectionTracker::getConnection('kicked'), false, false); // as the sweep removes it
		$this->assertSame($rBefore + 1, $this->activityRows(), 'one row, from its removal');
	}

	/**
	 * cron:streams stops an idle on-demand stream when it has no viewers. A
	 * Redis it cannot ask used to count as none, and stopped the stream under
	 * its viewers: unknown is now null (the cron keeps the stream).
	 */
	public function testAnOnDemandStreamsViewersAreUnknownWithoutRedis(): void {
		$rCount = new \ReflectionMethod(\XcVm\Cli\CronJobs\StreamsCronJob::class, 'redisViewers');
		$this->viewer('here-1', '198.51.100.7', 'tv', 1800000001);
		$this->viewer('here-2', '198.51.100.8', 'tv', 1800000002);
		$this->viewer('elsewhere', '198.51.100.9', 'tv', 1800000003, SERVER_ID + 1);
		$this->assertSame(2, $rCount->invoke(null, 11), 'this server\'s viewers of stream 11');
		$this->assertSame(0, $rCount->invoke(null, 12));

		$this->manager(null);
		RedisManager::useConnector(static fn() => false);
		try {
			$this->assertNull($rCount->invoke(null, 11), 'Redis down: unknown, never none');
		} finally {
			RedisManager::useConnector(null);
		}
	}

	/** heartbeat() closes the shared connection when it is done: a fresh one for what follows. */
	private function reconnect(): void {
		$this->rRedis = new \Redis();
		$this->rRedis->connect($this->rBus->socket(), 0, 2.0, null, 0, 2.0);
		$this->manager($this->rRedis);
	}

	/**
	 * A long-running viewer's check-in (and a timeshift segment's) refreshes
	 * an open connection, and reads an ended one back as ended: it used to
	 * open it again (hls_end 0, back in LIVE), so a worker whose viewer the
	 * limiter or an admin had just ended went on streaming.
	 */
	public function testAHeartbeatNeverBringsAnEndedConnectionBack(): void {
		$this->viewer('open', '198.51.100.7', 'tv', 1800000001);
		$rHeard = ConnectionTracker::heartbeat($GLOBALS['rSettings'], 'open', 1800000500);
		$this->assertSame(0, (int) $rHeard['hls_end']);
		$this->assertSame(1800000500, (int) $rHeard['hls_last_read']);
		$this->reconnect();
		$this->assertNotFalse($this->rRedis->zScore('LIVE', 'open'));

		$this->viewer('ended', '198.51.100.8', 'tv', 1800000002);
		$this->assertNotNull(ConnectionTracker::updateConnection(ConnectionTracker::getConnection('ended'), [], 'close'));
		$rHeard = ConnectionTracker::heartbeat($GLOBALS['rSettings'], 'ended', 1800000600);
		$this->assertSame(1, (int) $rHeard['hls_end'], 'read back as ended: the caller stops');
		$this->reconnect();
		$this->assertFalse($this->rRedis->zScore('LIVE', 'ended'), 'not back among the live ones');
		$this->assertTrue((bool) $this->rRedis->sIsMember('ENDED', 'ended'));
	}

	public function testAnHmacViewersActivityIsWritten(): void {
		// An HMAC identity has no line (user_id 0): its hmac_id names it.
		@mkdir(LOGS_TMP_PATH, 0777, true);
		$rBefore = $this->activityRows();
		ConnectionTracker::writeOfflineActivity(['save_closed_connection' => 1], SERVER_ID, 0, 0, 11, 1800000000, 'tv', '198.51.100.7', 'ts', 'NL', '', '', 0, 3, 'box-1');
		$this->assertSame($rBefore + 1, $this->activityRows());
		ConnectionTracker::writeOfflineActivity(['save_closed_connection' => 1], SERVER_ID, 0, 0, 11, 1800000000, 'tv', '198.51.100.7', 'ts', 'NL', '');
		$this->assertSame($rBefore + 1, $this->activityRows(), 'neither a line nor an HMAC identity: nothing');
	}

	public function testAnHlsViewerThatMovesLeavesTheOldServersSets(): void {
		$rOther = SERVER_ID + 1;
		$this->viewer('mover', '198.51.100.7', 'tv', 1800000001, $rOther);
		$rRecord = ConnectionTracker::getConnection('mover');
		$this->assertNotNull(ConnectionTracker::updateConnection($rRecord, ['server_id' => SERVER_ID], 'open'));
		$this->assertFalse($this->rRedis->zScore('SERVER#' . $rOther, 'mover'));
		$this->assertFalse($this->rRedis->zScore('SERVER_LINES#' . $rOther, 'mover'));
		$this->assertNotFalse($this->rRedis->zScore('SERVER#' . SERVER_ID, 'mover'));
		$this->assertNotFalse($this->rRedis->zScore('SERVER_LINES#' . SERVER_ID, 'mover'));
	}
}
