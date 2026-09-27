<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\StreamVersions;
use XcVm\Domain\Cluster\StreamReplica;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;

/**
 * MAIN's side of the R2 `streams` section (cluster plan, section 9): which
 * streams a node holds, read by id or range and in pages that miss none,
 * and the pruning of versions no node holds any more, each node's floor
 * raised first so a node that may have missed a removal checks everything.
 */
final class StreamReplicaTest extends TestCase {
	private TestDb $rDb;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach (['streams', 'streams_servers', 'recordings'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec(InstallSchema::migration('034_create_cluster_changes'));
		$this->rDb->exec('CREATE TABLE `cluster_meta` (`name` varchar(64) PRIMARY KEY, `value` mediumtext, `updated_at` int NOT NULL)');
		DatabaseFactory::set($this->rDb);
		// Node 5 holds 1 to 6: assigned the odd ones, the TV archive of 2, the thumbnails of 4, a recording of 6.
		$this->rDb->exec("INSERT INTO `streams` (`id`, `type`, `stream_source`, `tv_archive_server_id`, `vframes_server_id`) VALUES (1, 1, '[]', 0, 0), (2, 1, '[]', 5, 0), (3, 1, '[]', 0, 0), (4, 1, '[]', 0, 5), (5, 1, '[]', 0, 0), (6, 1, '[]', 0, 0), (7, 1, '[]', 6, 6)");
		$this->rDb->exec('INSERT INTO `streams_servers` (`stream_id`, `server_id`) VALUES (1, 5), (3, 5), (5, 5), (7, 6)');
		$this->rDb->exec('INSERT INTO `recordings` (`id`, `stream_id`, `source_id`) VALUES (1, 6, 5), (2, 7, 6)');
	}

	protected function tearDown(): void {
		DatabaseFactory::reset();
	}

	public function testTheStreamsANodeHoldsByIdAndByRange(): void {
		$this->assertSame([1, 2, 3, 4, 5, 6], StreamReplica::held(5, null));
		$this->assertSame([2, 3, 6], StreamReplica::held(5, [2, 3, 6, 7, 99]));
		$this->assertSame([], StreamReplica::held(5, []));
		$this->assertSame([3, 4, 5], StreamReplica::held(5, null, 3, 5));
		$this->assertSame([7], StreamReplica::held(6, null));
	}

	public function testALimitedReadTakesTheLowestIdsAcrossEveryWayOfHoldingOne(): void {
		// Each source is cut at the limit too; what is returned is still every held id up to the last one.
		foreach ([1 => [1], 2 => [1, 2], 3 => [1, 2, 3], 4 => [1, 2, 3, 4], 5 => [1, 2, 3, 4, 5], 9 => [1, 2, 3, 4, 5, 6]] as $rLimit => $rExpect) {
			$this->assertSame($rExpect, StreamReplica::held(5, null, 0, StreamReplica::MAX_ID, $rLimit), 'limit ' . $rLimit);
		}
		$this->assertSame([4, 5], StreamReplica::held(5, null, 4, StreamReplica::MAX_ID, 2));
	}

	public function testVersionsNoNodeHoldsArePrunedAfterAWeekFloorFirst(): void {
		$rNow = 1800000000;
		$rOld = $rNow - StreamReplica::KEEP_DAYS * 86400 - 1;
		// Node 5 held 8 (gone) at version 40, 9 at 41 (recently); 1 is still held at 30.
		$this->rDb->exec("INSERT INTO `cluster_stream_ver` VALUES (5, 8, 40, $rOld), (5, 9, 41, " . ($rNow - 60) . "), (5, 1, 30, $rOld), (6, 8, 42, $rOld), (6, 7, 43, $rOld)");
		$this->assertSame(2, StreamReplica::prune($rNow));
		$this->rDb->query('SELECT `server_id`, `stream_id` FROM `cluster_stream_ver` ORDER BY `server_id`, `stream_id`');
		$this->assertSame(['5:1', '5:9', '6:7'], array_map(static fn(array $rRow): string => $rRow['server_id'] . ':' . $rRow['stream_id'], $this->rDb->get_rows()));
		$this->assertSame([40, 42, 0], [StreamVersions::floor(5), StreamVersions::floor(6), StreamVersions::floor(7)], 'each node\'s own floor, at the newest version it lost');
		$this->assertSame(0, StreamReplica::prune($rNow), 'nothing left to prune');
	}
}
