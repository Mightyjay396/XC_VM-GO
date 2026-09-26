<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\CronJobs\UsersCronJob;
use XcVm\Core\Cluster\HlsReaping;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * MAIN leaves idle HLS viewers to a node whose agent reaps them, until that
 * node has been silent for the orphan TTL while MAIN itself was watching.
 */
final class HlsReapingTest extends TestCase {
	private const T = 1800000000;

	private TestDb $rDb;

	private string $rPath;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec("CREATE TABLE `cluster_nodes` (`server_id` int, `state` varchar(16), `mode` int, `flows` int, `features` varchar(255), `last_seen_at` bigint)");
		DatabaseFactory::set($this->rDb);
		$this->rPath = sys_get_temp_dir() . '/orphans_' . bin2hex(random_bytes(4)) . '.json';
		HlsReaping::usePath($this->rPath);
	}

	protected function tearDown(): void {
		DatabaseFactory::reset();
		@unlink($this->rPath);
		HlsReaping::usePath(null);
	}

	private function node(int $rID, string $rState, int $rMode, int $rFlows, ?string $rFeatures, ?int $rSeenSec): void {
		$this->rDb->query('INSERT INTO `cluster_nodes` VALUES (?, ?, ?, ?, ?, ?)', $rID, $rState, $rMode, $rFlows, $rFeatures, $rSeenSec === null ? null : $rSeenSec * 1000);
	}

	public function testOnlyActiveNodesWithTheFeatureAndConnectionsReapForThemselves(): void {
		$this->node(2, 'active', 1, 64 | 8 | 2, 'fanout_events,hls_reaper', self::T - 1);
		$this->node(3, 'active', 1, 64 | 8 | 2, null, self::T - 1);           // an older agent
		$this->node(4, 'active', 1, 8 | 2, 'hls_reaper', self::T - 1);         // CONNECTIONS off
		$this->node(5, 'quarantined', 1, 64 | 8 | 2, 'hls_reaper', self::T - 1);
		$this->node(6, 'active', 0, 64 | 8 | 2, 'hls_reaper', self::T - 1);    // mode 0
		HlsReaping::begin(self::T, 120);
		$this->assertTrue(HlsReaping::nodeReaps(2));
		foreach ([3, 4, 5, 6, 99] as $rID) {
			$this->assertFalse(HlsReaping::nodeReaps($rID), (string) $rID);
		}
	}

	public function testASilentNodeIsOrphanedOnlyAfterTheTtlWatchedByMain(): void {
		// Silent for 10 minutes already at the first pass: MAIN may just have come back.
		$this->node(2, 'active', 1, 74, 'hls_reaper', self::T - 600);
		HlsReaping::begin(self::T, 120);
		$this->assertTrue(HlsReaping::nodeReaps(2), 'first pass: the watch only starts');
		HlsReaping::begin(self::T + 60, 120);
		$this->assertTrue(HlsReaping::nodeReaps(2));
		HlsReaping::begin(self::T + 120, 120);
		$this->assertFalse(HlsReaping::nodeReaps(2), 'watched silent for the TTL: orphaned');

		// Heard again: back to reaping for itself, and the watch is forgotten.
		$this->rDb->query('UPDATE `cluster_nodes` SET `last_seen_at` = ?', (self::T + 179) * 1000);
		HlsReaping::begin(self::T + 180, 120);
		$this->assertTrue(HlsReaping::nodeReaps(2));
		$this->assertSame([], json_decode((string) file_get_contents($this->rPath), true)['since']);
	}

	public function testAGapInTheReaperRestartsTheWatch(): void {
		$this->node(2, 'active', 1, 74, 'hls_reaper', self::T - 600);
		HlsReaping::begin(self::T, 120);
		// MAIN (or its cron) was down for 10 minutes: its own downtime never counts.
		HlsReaping::begin(self::T + 600, 120);
		$this->assertTrue(HlsReaping::nodeReaps(2));
		HlsReaping::begin(self::T + 660, 120);
		$this->assertTrue(HlsReaping::nodeReaps(2));
		HlsReaping::begin(self::T + 720, 120);
		$this->assertFalse(HlsReaping::nodeReaps(2));
	}

	public function testANodeThatNeverSpokeIsWatchedToo(): void {
		$this->node(2, 'active', 1, 74, 'hls_reaper', null);
		HlsReaping::begin(self::T, 60);
		$this->assertTrue(HlsReaping::nodeReaps(2));
		HlsReaping::begin(self::T + 60, 60);
		$this->assertFalse(HlsReaping::nodeReaps(2));
	}

	public function testTheUsersCronLeavesAReapingNodesIdleViewersToIt(): void {
		$this->node(2, 'active', 1, 74, 'hls_reaper', self::T - 1);
		$this->node(3, 'active', 1, 74, null, self::T - 1);
		HlsReaping::begin(self::T, 120);
		$rEnded = new ReflectionMethod(UsersCronJob::class, 'hlsEnded');
		$rCron = new UsersCronJob();
		$rIdle = ['hls_end' => 0, 'hls_last_read' => self::T - 45];
		$this->assertFalse($rEnded->invoke($rCron, $rIdle + ['server_id' => 2], self::T), 'its agent decides');
		$this->assertTrue($rEnded->invoke($rCron, $rIdle + ['server_id' => 3], self::T), 'an older agent: the 30 s rule');
		$this->assertTrue($rEnded->invoke($rCron, ['hls_end' => 1, 'hls_last_read' => self::T, 'server_id' => 2], self::T), 'ended by the node');
		$this->assertFalse($rEnded->invoke($rCron, ['hls_end' => 0, 'hls_last_read' => self::T - 5, 'server_id' => 3], self::T));
	}

	public function testWhenTheNodesCannotBeReadTheLastKnownReapersStand(): void {
		$this->node(2, 'active', 1, 74, 'hls_reaper', self::T - 1);
		$this->node(3, 'active', 1, 74, null, self::T - 1);
		HlsReaping::begin(self::T, 120);
		$this->rDb->exec('DROP TABLE `cluster_nodes`');
		HlsReaping::begin(self::T + 60, 120);
		// A reaping node's touches no longer refresh MAIN's store (conn.touch
		// stays on the bus), so the 30 s rule must not come back on a bad read.
		$this->assertTrue(HlsReaping::nodeReaps(2));
		$this->assertFalse(HlsReaping::nodeReaps(3));
		$this->assertSame([], HlsReaping::orphaned(), 'nothing is purged on a read that failed');

		// Nothing ever read: every node gets the 30 s rule, as before.
		HlsReaping::usePath($this->rPath . '.fresh');
		HlsReaping::begin(self::T + 120, 120);
		$this->assertFalse(HlsReaping::nodeReaps(2));
		@unlink($this->rPath . '.fresh');
	}

	public function testEveryOrphanedConnectionsNodeIsReportedForThePurge(): void {
		$this->node(2, 'active', 1, 74, 'hls_reaper', self::T - 600);
		$this->node(3, 'active', 1, 74, null, self::T - 600);        // an older agent: purged too
		$this->node(4, 'active', 1, 10, 'hls_reaper', self::T - 600); // CONNECTIONS off: MAIN's store is the node's own
		$this->node(5, 'active', 1, 74, 'hls_reaper', self::T - 1);
		HlsReaping::begin(self::T, 120);
		$this->assertSame([], HlsReaping::orphaned(), 'the watch only starts');
		HlsReaping::begin(self::T + 120, 120);
		$this->assertSame([2, 3], HlsReaping::orphaned());
	}

	public function testThePurgeDropsOnlyThatNodesRowsFromTheStore(): void {
		\XcVm\Core\Config\SettingsManager::set(['redis_handler' => 0]);
		$this->rDb->exec('CREATE TABLE `lines_live` (`activity_id` INTEGER PRIMARY KEY AUTOINCREMENT, `uuid` text, `server_id` int, `container` text)');
		$this->rDb->exec("INSERT INTO `lines_live` (`uuid`, `server_id`, `container`) VALUES ('a', 2, 'ts'), ('b', 2, 'hls'), ('c', 3, 'ts')");
		$this->assertSame(2, \XcVm\Domain\Cluster\ConnectionIngest::purgeNode(2));
		$this->assertSame(0, \XcVm\Domain\Cluster\ConnectionIngest::purgeNode(2));
		$this->rDb->query('SELECT `uuid` FROM `lines_live`');
		$this->assertSame([['uuid' => 'c']], $this->rDb->get_rows());
		\XcVm\Core\Config\SettingsManager::set([]);
	}
}
