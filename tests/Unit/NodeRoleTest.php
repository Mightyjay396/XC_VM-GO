<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\NodeRole;

/**
 * NodeRole — "is this node MAIN?". The crontab is copied verbatim to every
 * load balancer, so cluster-wide jobs (table rotation, the TMDb crawl, the
 * signals purge, the update and module-update checks) gate on this and do
 * nothing on an LB. An unknown answer must read as "not MAIN".
 */
final class NodeRoleTest extends TestCase {

	protected function setUp(): void {
		if (!defined('SERVER_ID')) {
			define('SERVER_ID', 1);
		}
	}

	protected function tearDown(): void {
		NodeRole::useServers(null);
	}

	public function testMainNodeIsMain(): void {
		NodeRole::useServers(fn () => [SERVER_ID => ['is_main' => 1]]);
		$this->assertTrue(NodeRole::isMain());
	}

	public function testLoadBalancerIsNotMain(): void {
		NodeRole::useServers(fn () => [SERVER_ID => ['is_main' => 0], SERVER_ID + 1 => ['is_main' => 1]]);
		$this->assertFalse(NodeRole::isMain());
	}

	public function testUnknownServersReadAsNotMain(): void {
		NodeRole::useServers(fn () => []);
		$this->assertFalse(NodeRole::isMain(), 'no servers row: skip cluster-wide work');
		NodeRole::useServers(fn () => [SERVER_ID + 7 => ['is_main' => 1]]);
		$this->assertFalse(NodeRole::isMain(), 'this node missing from the list');
	}

	/**
	 * Every cron that changes cluster-wide state asks NodeRole before doing it.
	 *
	 * @dataProvider mainOnlyCrons
	 */
	public function testClusterWideCronsAreGated(string $rFile, string $rGuarded): void {
		$rSource = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Cli/CronJobs/' . $rFile);
		$rGate = strpos($rSource, 'NodeRole::isMain()');
		$this->assertNotFalse($rGate, "$rFile must ask NodeRole::isMain()");
		$this->assertLessThan(strpos($rSource, $rGuarded), $rGate, "$rFile must gate before: $rGuarded");
	}

	/** @return array<string, array{string, string}> */
	public static function mainOnlyCrons(): array {
		return [
			'table rotation'  => ['CleanupCronJob.php', "\$rTables = ['lines_activity'"],
			'tmdb'            => ['TmdbCronJob.php', 'TmdbCron::run()'],
			'tmdb popular'    => ['TmdbPopularCronJob.php', 'TmdbPopularCron::run()'],
			'signals purge'   => ['RootSignalsCronJob.php', 'UNIX_TIMESTAMP() - `time` >= 86400'],
			'update check'    => ['UpdateCronJob.php', 'getUpdate(XC_VM_VERSION)'],
			'module updates'  => ['ModuleUpdatesCronJob.php', 'new ModuleUpdateChecker()'],
		];
	}
}
