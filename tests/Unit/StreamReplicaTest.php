<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\StreamVersions;
use XcVm\Domain\Cluster\StreamReplica;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;
use XcVm\Tests\Support\QueryLogDb;

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
		// Node 5 held 8, 20 and 30 (gone) at 35, 40 and 38, 9 at 41 (recently); 1 is still held at 30.
		$this->rDb->exec("INSERT INTO `cluster_stream_ver` VALUES (5, 8, 35, $rOld), (5, 20, 40, $rOld), (5, 30, 38, $rOld), (5, 9, 41, " . ($rNow - 60) . "), (5, 1, 30, $rOld), (6, 8, 42, $rOld), (6, 7, 43, $rOld)");
		$this->assertSame(4, StreamReplica::prune($rNow));
		$this->assertSame(['5:1', '5:9', '6:7'], $this->keys());
		$this->assertSame([40, 42, 0], [StreamVersions::floor(5), StreamVersions::floor(6), StreamVersions::floor(7)], 'each node\'s own floor, at the newest version it lost');
		$this->assertSame(0, StreamReplica::prune($rNow), 'nothing left to prune');
		$this->assertSame('0:0', $this->pruneFrom(), 'the end reached: the next run starts over');
	}

	/** @return list<string> the version rows, "server:stream" */
	private function keys(): array {
		$this->rDb->query('SELECT `server_id`, `stream_id` FROM `cluster_stream_ver` ORDER BY `server_id`, `stream_id`');
		return array_map(static fn(array $rRow): string => $rRow['server_id'] . ':' . $rRow['stream_id'], $this->rDb->get_rows());
	}

	private function pruneFrom(): ?string {
		$this->rDb->query('SELECT `value` FROM `cluster_meta` WHERE `name` = ?', StreamReplica::META_PRUNE);
		return $this->rDb->get_row()['value'] ?? null;
	}

	public function testRowsStillHeldNeverKeepThePruningFromTheOthers(): void {
		$rNow = 1800000000;
		// Node 5 holds more streams than a run looks at, their rows as old as
		// migration 047 seeded them; 5:900000 and 6:8 were taken off since.
		$rValues = [];
		for ($i = 100; $i < 100 + StreamReplica::PRUNE_ROWS; $i++) {
			$rValues[] = "($i, 1, '[]')";
		}
		$this->rDb->exec('INSERT INTO `streams` (`id`, `type`, `stream_source`) VALUES ' . implode(', ', $rValues));
		$this->rDb->exec('INSERT INTO `streams_servers` (`stream_id`, `server_id`) SELECT `id`, 5 FROM `streams` WHERE `id` >= 100');
		$this->rDb->exec('INSERT INTO `cluster_stream_ver` (`server_id`, `stream_id`, `ver`, `updated_at`) SELECT `server_id`, `stream_id`, 0, ' . ($rNow - 30 * 86400) . ' FROM `streams_servers` WHERE `stream_id` >= 100');
		$this->rDb->exec('INSERT INTO `cluster_stream_ver` VALUES (5, 900000, 76, ' . ($rNow - 10 * 86400) . '), (6, 8, 77, ' . ($rNow - 10 * 86400) . ')');

		$this->assertSame(0, StreamReplica::prune($rNow), 'a run\'s worth of held rows');
		$this->assertSame('5:' . (99 + StreamReplica::PRUNE_ROWS), $this->pruneFrom());
		$this->assertSame(2, StreamReplica::prune($rNow + 60), 'the next run goes on past them');
		$this->assertSame([76, 77], [StreamVersions::floor(5), StreamVersions::floor(6)]);
		$this->assertSame('0:0', $this->pruneFrom());
		$this->assertSame(0, StreamReplica::prune($rNow + 120));
		$this->assertCount(StreamReplica::PRUNE_ROWS, $this->keys(), 'every held row stays');
	}

	public function testARowStampedAgainSinceItWasReadStays(): void {
		$rNow = 1800000000;
		$rOld = $rNow - StreamReplica::KEEP_DAYS * 86400 - 1;
		$this->rDb->exec("INSERT INTO `cluster_stream_ver` VALUES (6, 8, 42, $rOld), (6, 9, 43, $rOld)");
		$rLog = new QueryLogDb($this->rDb);
		DatabaseFactory::set($rLog);
		// A bump of stream 8 lands between the read and the delete.
		$rLog->rBefore = function (string $rQuery) use ($rNow): void {
			if (str_starts_with($rQuery, 'DELETE FROM `cluster_stream_ver`')) {
				$this->rDb->query('UPDATE `cluster_stream_ver` SET `ver` = 50, `updated_at` = ? WHERE `server_id` = 6 AND `stream_id` = 8', $rNow);
			}
		};
		$this->assertSame(1, StreamReplica::prune($rNow), 'only the row that was not');
		$this->assertSame(['6:8'], $this->keys());
		$this->assertSame(43, StreamVersions::floor(6));
	}
}
