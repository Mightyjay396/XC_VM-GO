<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * ClusterMaintainStatsCommand — build the indexes `servers_stats` is read
 * and pruned by, online (plan, section 8, "MAIN capacity").
 *
 * The table had its primary key alone: retention's DELETE by `time` and the
 * per-server reads by `server_id` and `time` scanned every row, one per node
 * per minute. A migration would build the indexes inside the update, on a
 * table that can hold a year of rows, so they are built here instead,
 * without locking the table (`ALGORITHM=INPLACE, LOCK=NONE`). cron:cleanup
 * starts it while one is missing; one run at a time.
 *
 * Usage: `console.php cluster:maintain-stats`
 *
 * Exit code 0 when both indexes exist, 1 otherwise.
 *
 * @package XC_VM_CLI_Commands
 */
class ClusterMaintainStatsCommand implements CommandInterface {
	use DatabaseAware;

	/** Name => leading columns. An index that starts with the same columns counts. */
	public const INDEXES = ['time' => ['time'], 'server_time' => ['server_id', 'time']];

	public function getName(): string {
		return 'cluster:maintain-stats';
	}

	public function getDescription(): string {
		return 'Build the servers_stats indexes online';
	}

	public function execute(array $rArgs): int {
		if (!NodeRole::isMain()) {
			echo "Run this on MAIN.\n";
			return 1;
		}
		$rLock = @fopen(TMP_PATH . 'cluster_maintain_stats.lock', 'c');
		if ($rLock !== false && !flock($rLock, LOCK_EX | LOCK_NB)) {
			echo "Already running.\n";
			return 0;
		}
		$rDb = self::db();
		$rFailed = 0;
		foreach (self::missing($rDb) as $rName => $rColumns) {
			echo 'servers_stats: adding index ' . $rName . '... ';
			if (self::build($rDb, $rName, $rColumns)) {
				echo "done.\n";
			} else {
				echo "failed.\n";
				$rFailed++;
			}
		}
		return $rFailed === 0 ? 0 : 1;
	}

	/**
	 * The indexes of INDEXES that `servers_stats` has not got.
	 *
	 * @return array<string, list<string>>
	 */
	public static function missing(object $rDb): array {
		if (!$rDb->query('SHOW INDEX FROM `servers_stats`;')) {
			return [];
		}
		$rHave = [];
		foreach ($rDb->get_rows() ?: [] as $rRow) {
			$rHave[$rRow['Key_name']][(int) $rRow['Seq_in_index']] = $rRow['Column_name'];
		}
		foreach ($rHave as &$rColumns) {
			ksort($rColumns);
			$rColumns = array_values($rColumns);
		}
		unset($rColumns);
		return array_filter(self::INDEXES, static fn(array $rWant): bool => !array_filter($rHave, static fn(array $rColumns): bool => array_slice($rColumns, 0, count($rWant)) === $rWant));
	}

	/**
	 * Add one index without locking the table. A server that cannot build it
	 * in place refuses, and nothing falls back to a locking ALTER.
	 *
	 * @param list<string> $rColumns
	 */
	public static function build(object $rDb, string $rName, array $rColumns): bool {
		return (bool) $rDb->query('ALTER TABLE `servers_stats` ADD INDEX `' . $rName . '` (`' . implode('`, `', $rColumns) . '`), ALGORITHM=INPLACE, LOCK=NONE;');
	}
}
