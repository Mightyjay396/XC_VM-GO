<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\CronJobs\UsersCronJob;

/**
 * UsersCronJob — in Redis mode MAIN's cron:users decides whether a remote
 * node's PHP-served connection is still alive by looking its worker pid up in
 * that node's servers.php_pids.
 *
 * loadPHPPIDs: ServerRepository::getAll() no longer carries the column (it is
 * kept out of the per-request servers cache), so the reaper reads it with its
 * own query. "[]" means no worker is running; a node with no usable list maps
 * to null, which the reaper treats as "cannot tell" and keeps the connection.
 *
 * isRemoteWorkerRunning: the list is a snapshot taken just before the node's
 * heartbeat, so it can only rule on a pid assigned before it. A reused
 * connection (VOD range/seek, TS or timeshift reconnect) gets a new worker pid
 * but keeps its original date_start; the pid write stamps hls_last_read, so the
 * later of the two dates the pid.
 */
final class UsersCronPhpPidsTest extends TestCase {

	private const HEARTBEAT = 1700000000;

	private $rDbBackup;

	protected function setUp(): void {
		$this->rDbBackup = $GLOBALS['db'] ?? null;

		$rDb = new TestDb();
		$rDb->exec('CREATE TABLE servers (id INTEGER PRIMARY KEY, php_pids TEXT);');
		$rDb->exec('INSERT INTO servers (id, php_pids) VALUES (1, \'[11,12]\'), (2, NULL), (3, \'not json\'), (4, \'["13"]\'), (5, \'[]\'), (6, \'null\');');
		$GLOBALS['db'] = $rDb;
	}

	protected function tearDown(): void {
		$GLOBALS['db'] = $this->rDbBackup;
	}

	private function invoke(string $rName, array $rArgs = []) {
		$rMethod = new ReflectionMethod(UsersCronJob::class, $rName);
		$rMethod->setAccessible(true);

		return $rMethod->invokeArgs($rMethod->isStatic() ? null : new UsersCronJob(), $rArgs);
	}

	private function running(int $rDateStart, int $rLastRead, ?array $rPIDs, ?array $rServer = ['last_check_ago' => self::HEARTBEAT]): bool {
		$rConnection = ['pid' => 12, 'date_start' => $rDateStart, 'hls_last_read' => $rLastRead];

		return $this->invoke('isRemoteWorkerRunning', [$rConnection, $rServer, $rPIDs]);
	}

	public function testReadsEveryNodesWorkerPidsFromTheServersTable(): void {
		$this->assertSame(
			[1 => [11, 12], 2 => null, 3 => null, 4 => [13], 5 => [], 6 => null],
			$this->invoke('loadPHPPIDs')
		);
	}

	public function testAPidAssignedBeforeTheHeartbeatIsLookedUp(): void {
		$rBefore = self::HEARTBEAT - 60;

		$this->assertTrue($this->running($rBefore, $rBefore, [11, 12]), 'listed worker');
		$this->assertFalse($this->running($rBefore, $rBefore, [11, 13]), 'worker missing from the list');
		$this->assertFalse($this->running($rBefore, $rBefore, []), 'no worker running on the node');
	}

	public function testAnUnknownListOrNodeKeepsTheConnection(): void {
		$rBefore = self::HEARTBEAT - 60;

		$this->assertTrue($this->running($rBefore, $rBefore, null), 'no usable php_pids');
		$this->assertTrue($this->running($rBefore, $rBefore, [11, 13], null), 'server row missing');
	}

	public function testAPidAssignedAfterTheHeartbeatIsNotJudged(): void {
		$this->assertTrue($this->running(self::HEARTBEAT, self::HEARTBEAT, [11, 13]), 'new connection');
		$this->assertTrue($this->running(self::HEARTBEAT - 3600, self::HEARTBEAT, [11, 13]), 'reused connection, new worker pid');
		$this->assertFalse($this->running(self::HEARTBEAT - 3600, self::HEARTBEAT - 1, [11, 13]), 'reused connection, pid assigned before the heartbeat');
	}

	public function testTheReaperIsWiredToTheHelpers(): void {
		$rPath = MAIN_HOME . 'Cli/CronJobs/UsersCronJob.php';
		$this->assertFileExists($rPath);
		$rSource = (string) file_get_contents($rPath);

		$this->assertSame(1, substr_count($rSource, '$this->rPHPPIDs = $this->loadPHPPIDs();'), 'cron:users must read php_pids itself');
		$this->assertSame(1, substr_count($rSource, '$this->isRemoteWorkerRunning('), 'the reaper must judge remote workers through the helper');
		$this->assertStringNotContainsString("\$rServer['php_pids']", $rSource, 'getAll() rows carry no php_pids');
	}
}
