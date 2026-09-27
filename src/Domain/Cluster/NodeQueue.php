<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\QueueSink;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * One node's rows of the encoding queue, as MAIN serves them over the cluster
 * API (`queue_enqueue`, `queue_claim`, `queue_update`; {@see QueueSink} is the
 * node's half). The queue is MAIN's table, and a node in mode 2 has no
 * database of MAIN's to reach.
 *
 * Every statement here is keyed to `server_id`, which the API takes from the
 * authenticated node. A node therefore sees and changes its own work only,
 * whatever its payload says.
 */
final class NodeQueue {
	use DatabaseAware;

	/**
	 * Add work for a node. Movies replace what is queued for the same stream
	 * (a re-queue re-encodes), channels already queued are left as they are —
	 * the same rules the node's own SQL had.
	 *
	 * @param list<mixed> $rStreamIDs
	 * @return int How many rows were added.
	 */
	public static function enqueue(int $rServerID, string $rType, array $rStreamIDs): int {
		$rIDs = array_values(array_unique(array_filter(array_map('intval', $rStreamIDs), static fn(int $rID): bool => $rID > 0)));
		if ($rIDs === [] || !in_array($rType, QueueSink::TYPES, true)) {
			return 0;
		}
		$rDb = self::db();
		$rPlaceholders = implode(',', array_fill(0, count($rIDs), '?'));

		if ($rType === 'movie') {
			$rDb->query('DELETE FROM `queue` WHERE `stream_id` IN (' . $rPlaceholders . ') AND `server_id` = ?;', ...[...$rIDs, $rServerID]);
		} else {
			$rDb->query('SELECT `stream_id` FROM `queue` WHERE `stream_id` IN (' . $rPlaceholders . ') AND `server_id` = ?;', ...[...$rIDs, $rServerID]);
			$rHave = array_map(static fn(array $rRow): int => (int) $rRow['stream_id'], $rDb->get_rows() ?: []);
			$rIDs = array_values(array_diff($rIDs, $rHave));
			if ($rIDs === []) {
				return 0;
			}
		}

		$rNow = time();
		$rArgs = [];
		foreach ($rIDs as $rStreamID) {
			array_push($rArgs, $rType, $rStreamID, $rServerID, $rNow);
		}
		$rDb->query(
			'INSERT INTO `queue`(`type`, `stream_id`, `server_id`, `added`) VALUES ' . implode(',', array_fill(0, count($rIDs), '(?, ?, ?, ?)')) . ';',
			...$rArgs
		);
		return count($rIDs);
	}

	/**
	 * What this node has running (with the pid it reported, so it can check
	 * its own processes) and what waits, oldest first.
	 *
	 * @return array{running: list<array{id: int, pid: int}>, pending: list<array{id: int, stream_id: int}>}
	 */
	public static function claim(int $rServerID, string $rType, int $rLimit): array {
		$rLimit = max(0, min(QueueSink::MAX_CLAIM, $rLimit));
		$rDb = self::db();
		$rRunning = $rPending = [];

		if ($rDb->query('SELECT `id`, `pid` FROM `queue` WHERE `server_id` = ? AND `pid` IS NOT NULL AND `type` = ? ORDER BY `added` ASC;', $rServerID, $rType)) {
			foreach ($rDb->get_rows() ?: [] as $rRow) {
				$rRunning[] = ['id' => (int) $rRow['id'], 'pid' => (int) $rRow['pid']];
			}
		}
		if ($rLimit > 0 && $rDb->query('SELECT `id`, `stream_id` FROM `queue` WHERE `server_id` = ? AND `pid` IS NULL AND `type` = ? ORDER BY `added` ASC LIMIT ' . $rLimit . ';', $rServerID, $rType)) {
			foreach ($rDb->get_rows() ?: [] as $rRow) {
				$rPending[] = ['id' => (int) $rRow['id'], 'stream_id' => (int) $rRow['stream_id']];
			}
		}

		return ['running' => $rRunning, 'pending' => $rPending];
	}

	/**
	 * Record what the node started (`id => pid`) and drop what it finished or
	 * lost. Rows of another node are not touched.
	 *
	 * @param array<mixed, mixed> $rPids
	 * @param list<mixed>         $rDelete
	 * @return int How many rows were changed or dropped.
	 */
	public static function update(int $rServerID, array $rPids, array $rDelete): int {
		$rDb = self::db();
		$rDone = 0;

		foreach ($rPids as $rID => $rPid) {
			$rID = (int) $rID;
			$rPid = (int) $rPid;
			if ($rID > 0 && $rPid > 0 && $rDb->query('UPDATE `queue` SET `pid` = ? WHERE `id` = ? AND `server_id` = ?;', $rPid, $rID, $rServerID)) {
				$rDone++;
			}
		}

		$rIDs = array_values(array_unique(array_filter(array_map('intval', $rDelete), static fn(int $rID): bool => $rID > 0)));
		if ($rIDs !== []) {
			$rDb->query('DELETE FROM `queue` WHERE `id` IN (' . implode(',', $rIDs) . ') AND `server_id` = ?;', $rServerID);
			$rDone += count($rIDs);
		}

		return $rDone;
	}
}
