<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\Crypto\ClusterCrypto;
use XcVm\Core\Cluster\Crypto\ClusterCryptoFactory;
use XcVm\Core\Cluster\StreamRuntime;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * MAIN's own writes to the runtime columns of streams that nodes run (the
 * admin's Rescan VOD, Recreate channels and symlink tools, a channel saved
 * with re-encode), sent to the nodes that keep those columns themselves
 * (plan, section 7: `stream.assign`). A node whose STREAMS flow is on reads
 * its streams' runtime state from its own store (StreamRuntime), so a write
 * to MAIN's `streams_servers` rows alone never reached it: it did not analyse
 * a movie again, nor rebuild a channel's sources.
 *
 * Each active node whose STREAMS flow is on and that takes commands gets
 * `stream.assign {stream_ids, set, fill?}` for the streams it holds, at most
 * StreamRuntime::ASSIGN_MAX a command; `set` is written as given, `fill`
 * only where the node's value is empty. Granting, so a MAIN without a licence
 * sends none. Never fails the write: a node that cannot be told keeps its
 * store until its next write or seed.
 */
final class StreamAssign {
	use DatabaseAware;

	/** What the symlink and Recreate channels tools reset on every server running the stream. */
	public const RESET = [
		'bitrate' => null, 'current_source' => null, 'to_analyze' => 0, 'pid' => null, 'stream_started' => null,
		'stream_info' => null, 'stream_status' => 0, 'monitor_pid' => null,
	];

	/**
	 * @param list<int|string> $rStreamIDs
	 * @param array<string, mixed> $rSet
	 * @param array<string, mixed> $rFill
	 * @return int the commands queued
	 */
	public static function send(array $rStreamIDs, array $rSet, array $rFill = [], ?ClusterCrypto $rCrypto = null): int {
		$rIDs = array_values(array_unique(array_filter(array_map('intval', $rStreamIDs), static fn(int $rID): bool => $rID > 0)));
		if ($rIDs === [] || $rSet + $rFill === []) {
			return 0;
		}
		try {
			$rCrypto ??= ClusterCryptoFactory::create();
			self::db()->query("SELECT * FROM `cluster_nodes` WHERE `state` = 'active';");
			$rNodes = self::db()->get_rows() ?: [];
		} catch (\Throwable) {
			return 0;
		}
		$rArgs = ['set' => $rSet] + ($rFill !== [] ? ['fill' => $rFill] : []);
		$rSent = 0;
		foreach ($rNodes as $rNode) {
			if (((int) ($rNode['flows'] ?? 0) & NodeRegistry::FLOW_STREAMS) === 0 || !CommandBus::accepts($rNode)) {
				continue;
			}
			try {
				foreach (array_chunk(self::held((int) $rNode['server_id'], $rIDs), StreamRuntime::ASSIGN_MAX) as $rChunk) {
					CommandBus::enqueue($rCrypto, (int) $rNode['server_id'], 'stream.assign', ['stream_ids' => $rChunk] + $rArgs);
					$rSent++;
				}
			} catch (\Throwable) {
				// This node keeps its store until its next write or seed.
			}
		}
		return $rSent;
	}

	/**
	 * Which of these streams the server runs (its `streams_servers` rows).
	 *
	 * @param list<int> $rIDs
	 * @return list<int>
	 */
	private static function held(int $rServerID, array $rIDs): array {
		$rHeld = [];
		foreach (array_chunk($rIDs, StreamRuntime::ASSIGN_MAX) as $rChunk) {
			self::db()->query('SELECT DISTINCT `stream_id` FROM `streams_servers` WHERE `server_id` = ? AND `stream_id` IN (' . implode(',', $rChunk) . ');', $rServerID);
			foreach (self::db()->get_rows() ?: [] as $rRow) {
				$rHeld[] = (int) $rRow['stream_id'];
			}
		}
		sort($rHeld);
		return $rHeld;
	}
}
