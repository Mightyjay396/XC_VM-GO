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
 * The SQL is QueueSink's (its *Rows() methods, in Core so it ships to LBs,
 * where this class does not): both halves run the same statements. Here every
 * one is keyed to `server_id`, which the API takes from the authenticated
 * node. A node therefore sees and changes its own work only, whatever its
 * payload says.
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
		$rIDs = QueueSink::ids($rStreamIDs);
		if ($rIDs === [] || !in_array($rType, QueueSink::TYPES, true)) {
			return 0;
		}
		// The rows that were new, whether or not the INSERT went through (the
		// node's own path answers the INSERT's outcome instead).
		return QueueSink::insertRows(self::db(), $rServerID, $rType, $rIDs)[0];
	}

	/**
	 * What this node has running (with the pid it reported, so it can check
	 * its own processes) and what waits, oldest first.
	 *
	 * @return array{running: list<array{id: int, pid: int}>, pending: list<array{id: int, stream_id: int}>}
	 */
	public static function claim(int $rServerID, string $rType, int $rLimit): array {
		return QueueSink::claimRows(self::db(), $rServerID, $rType, $rLimit);
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
		return QueueSink::updateRows(self::db(), $rServerID, $rPids, $rDelete);
	}
}
