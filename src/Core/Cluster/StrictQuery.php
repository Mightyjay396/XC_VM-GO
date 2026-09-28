<?php

namespace XcVm\Core\Cluster;

/**
 * One statement of something MAIN signs or a node compares, run so that a
 * failure throws (ADR 0004, "Never from a failed read").
 *
 * `Database::query` answers a failed statement with false, and `get_row()`
 * then answers false or the previous statement's row. A section built from
 * that would be signed as MAIN's word, and a node would report streams
 * missing that are not. The callers (StreamRecords, and on MAIN
 * StreamReplica, BlocklistDelta and ReplicaBuilder) name their section, which
 * the message carries; the op answering them then says `503 DB`.
 *
 * In Core: StreamRecords ships to LBs, where Domain\Cluster does not.
 */
final class StrictQuery {
	/**
	 * Run $rQuery on $rDb: a failed one throws, never an empty result.
	 *
	 * @throws \RuntimeException `<section>: a read failed`
	 */
	public static function run(object $rDb, string $rSection, string $rQuery, mixed ...$rArgs): void {
		if ($rDb->query($rQuery, ...$rArgs) === false) {
			throw new \RuntimeException($rSection . ': a read failed');
		}
	}
}
