<?php

namespace XcVm\Domain\Cluster;

/**
 * Node liveness from silence (plan, "Liveness"). Silence counts from
 * max(last_seen_at, cluster_ready_at), so MAIN's own downtime never counts
 * against a node. Pure.
 *
 * | Silence                         | State   |
 * |---------------------------------|---------|
 * | ≤ 10 s                          | ok      |
 * | > 10 s                          | suspect |
 * | > cluster_offline_after_sec     | offline |
 */
final class NodeHealth {
	public const SUSPECT_AFTER_MS = 10000;

	public static function state(?int $rLastSeenMs, int $rReadyAtMs, int $rNowMs, int $rOfflineAfterSec): string {
		if ($rLastSeenMs === null) {
			return 'unknown';
		}
		$rSilence = $rNowMs - max($rLastSeenMs, $rReadyAtMs);
		if ($rSilence > $rOfflineAfterSec * 1000) {
			return 'offline';
		}
		return $rSilence > self::SUSPECT_AFTER_MS ? 'suspect' : 'ok';
	}
}
