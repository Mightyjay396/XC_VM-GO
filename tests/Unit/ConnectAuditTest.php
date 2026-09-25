<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\ConnectAudit;
use XcVm\Core\Cluster\NodeRole;

/**
 * ConnectAudit — the trace behind the cluster plan's cutover gate (seven days
 * without a MySQL/Redis connect before a node leaves the legacy link). It is
 * off unless NodeRole says so, and never throws.
 */
final class ConnectAuditTest extends TestCase {

	protected function setUp(): void {
		if (!defined('STORAGE_PATH')) {
			define('STORAGE_PATH', sys_get_temp_dir() . '/xcvm_storage_' . getmypid() . '/');
		}
		$this->wipe();
	}

	protected function tearDown(): void {
		NodeRole::resetAudit();
		$this->wipe();
	}

	private function wipe(): void {
		foreach (glob(ConnectAudit::dir() . '*') ?: [] as $rFile) {
			@unlink($rFile);
		}
	}

	public function testOffByDefaultWritesNothing(): void {
		NodeRole::resetAudit(false);
		ConnectAudit::record(ConnectAudit::SQL);
		$this->assertSame([], glob(ConnectAudit::dir() . '*.ndjson') ?: []);
	}

	public function testRecordsKindAndCallSiteWhenOn(): void {
		NodeRole::resetAudit(true);
		ConnectAudit::record(ConnectAudit::SQL); $rLine = __LINE__;
		ConnectAudit::record(ConnectAudit::REDIS);
		ConnectAudit::record(ConnectAudit::SQL);

		$rSummary = ConnectAudit::summary(7);
		$this->assertSame(2, $rSummary['sql']);
		$this->assertSame(1, $rSummary['redis']);
		$rSite = 'sql ' . __FILE__ . ':' . $rLine;
		$this->assertArrayHasKey($rSite, $rSummary['sites'], 'the caller, not ConnectAudit itself');
	}

	public function testSiteSkipsTheConnectMachinery(): void {
		$rTrace = [
			['file' => '/x/Core/Cluster/ConnectAudit.php', 'line' => 1, 'class' => ConnectAudit::class, 'function' => 'record'],
			['file' => '/x/Core/Database/Database.php', 'line' => 140, 'class' => 'XcVm\\Core\\Database\\Database', 'function' => 'db_connect'],
			['file' => '/x/Streaming/Foo.php', 'line' => 42, 'class' => 'XcVm\\Core\\Database\\DatabaseHandler', 'function' => '__construct'],
			['file' => '/x/Public/stream/live.php', 'line' => 7, 'class' => 'XcVm\\Streaming\\Foo', 'function' => 'boot'],
		];
		$this->assertSame('/x/Streaming/Foo.php:42', ConnectAudit::site($rTrace));
	}

	public function testSummaryWindowAndPrune(): void {
		@mkdir(ConnectAudit::dir(), 0750, true);
		$rNow = gmmktime(12, 0, 0, 9, 25, 2026);
		file_put_contents(ConnectAudit::dir() . '20260925.ndjson', json_encode(['t' => $rNow, 'k' => 'sql', 's' => 'a:1', 'p' => 1]) . "\n{broken\n");
		file_put_contents(ConnectAudit::dir() . '20260918.ndjson', json_encode(['t' => $rNow, 'k' => 'redis', 's' => 'b:2', 'p' => 1]) . "\n");
		$rSummary = ConnectAudit::summary(7, $rNow);
		$this->assertSame(['sql' => 1, 'redis' => 0], ['sql' => $rSummary['sql'], 'redis' => $rSummary['redis']], 'eight days back is outside a seven-day window');
		$this->assertSame(1, ConnectAudit::summary(8, $rNow)['redis']);

		$this->assertSame(1, ConnectAudit::prune(5, $rNow));
		$this->assertFileExists(ConnectAudit::dir() . '20260925.ndjson');
		$this->assertFileDoesNotExist(ConnectAudit::dir() . '20260918.ndjson');
	}

	public function testHooksSitInBothConnectPaths(): void {
		$rRoot = dirname(__DIR__, 2) . '/src/';
		$this->assertStringContainsString('ConnectAudit::record(ConnectAudit::SQL);', (string) file_get_contents($rRoot . 'Core/Database/Database.php'));
		$this->assertStringContainsString('ConnectAudit::record(ConnectAudit::REDIS);', (string) file_get_contents($rRoot . 'Infrastructure/Redis/RedisManager.php'));
	}
}
