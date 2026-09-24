<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\CronJobs\UsersCronJob;

/**
 * UsersCronJob::loadPHPPIDs — in Redis mode MAIN's cron:users decides whether a
 * remote node's PHP-served connection is still alive by looking its worker pid
 * up in that node's servers.php_pids. ServerRepository::getAll() no longer
 * carries the column (it is kept out of the per-request servers cache), so the
 * reaper reads it with its own query. A node with no usable list maps to [],
 * which the reaper treats as "cannot tell" and keeps the connection.
 */
final class UsersCronPhpPidsTest extends TestCase {

	private $rDbBackup;

	protected function setUp(): void {
		$this->rDbBackup = $GLOBALS['db'] ?? null;

		$rDb = new TestDb();
		$rDb->exec('CREATE TABLE servers (id INTEGER PRIMARY KEY, php_pids TEXT);');
		$rDb->exec('INSERT INTO servers (id, php_pids) VALUES (1, \'[11,12]\'), (2, NULL), (3, \'not json\'), (4, \'["13"]\');');
		$GLOBALS['db'] = $rDb;
	}

	protected function tearDown(): void {
		$GLOBALS['db'] = $this->rDbBackup;
	}

	public function testReadsEveryNodesWorkerPidsFromTheServersTable(): void {
		$rMethod = new ReflectionMethod(UsersCronJob::class, 'loadPHPPIDs');
		$rMethod->setAccessible(true);

		$this->assertSame(
			[1 => [11, 12], 2 => [], 3 => [], 4 => [13]],
			$rMethod->invoke($rMethod->isStatic() ? null : new UsersCronJob())
		);
	}
}
