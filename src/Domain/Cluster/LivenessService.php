<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\ClusterHealth;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * MAIN's liveness loop (plan, "Liveness"): every second, from the signals
 * daemon (and each minute from cron:cluster), the nodes whose telemetry is
 * authoritative are judged by their silence (NodeHealth) and the result is
 * published for routing (Core\Cluster\ClusterHealth).
 *
 * Published states get worse at once and better only after steady health
 * (NodeHealth::settle), so a node near the 10 s threshold does not flap.
 *
 * The fleet silence guard: when more than half of those nodes, and at least
 * two, fall silent together, MAIN is more likely the one cut off (its
 * network, its API pool). The guard then holds every node at its last
 * published state instead of marking any offline, and raises an alert,
 * until the silence clears.
 */
final class LivenessService {
	use DatabaseAware;

	/**
	 * One pass. Returns the transitions it published, server id => [from, to];
	 * the caller rewrites the servers cache when there are any.
	 *
	 * @return array<int, array{0: ?string, 1: ?string}>
	 */
	public static function tick(int $rOfflineAfterSec): array {
		$rReady = ClusterMeta::readyAtMs();
		$rNow = ClusterClock::nowMs();
		self::db()->query("SELECT `server_id`, `last_seen_at` FROM `cluster_nodes` WHERE `state` = 'active' AND `mode` >= 1 AND (`flows` & ?) <> 0;", NodeRegistry::FLOW_TELEMETRY);
		$rRows = self::db()->get_rows();
		$rPrev = ClusterHealth::read();
		$rJudged = [];
		$rOkSince = [];
		foreach ($rRows as $rRow) {
			$rState = NodeHealth::state($rRow['last_seen_at'] === null ? null : (int) $rRow['last_seen_at'], $rReady, $rNow, $rOfflineAfterSec);
			// A node that has not spoken since the flow went on is judged offline
			// only once the loop has been ready long enough to have heard it.
			if ($rState === 'unknown') {
				$rState = $rNow - $rReady > $rOfflineAfterSec * 1000 ? 'offline' : 'suspect';
			}
			$rID = (int) $rRow['server_id'];
			[$rJudged[$rID], $rSince] = NodeHealth::settle($rPrev['states'][$rID] ?? null, $rState, $rPrev['ok_since'][$rID] ?? null, $rNow);
			if ($rSince !== null) {
				$rOkSince[$rID] = $rSince;
			}
		}

		$rSilent = count(array_filter($rJudged, static fn($rState) => $rState !== 'ok'));
		$rGuard = count($rJudged) >= 2 && $rSilent >= 2 && $rSilent * 2 > count($rJudged);
		if ($rGuard) {
			foreach ($rJudged as $rID => $rState) {
				if ($rState === 'offline' && ($rPrev['states'][$rID] ?? null) !== 'offline') {
					$rJudged[$rID] = $rPrev['states'][$rID] ?? 'suspect';
				}
			}
		}
		if ($rGuard !== $rPrev['guard']) {
			ClusterAudit::log($rGuard ? 'cluster.fleet_silence' : 'cluster.fleet_silence_clear', null, ['silent' => $rSilent, 'nodes' => count($rJudged)], 'liveness');
		}

		$rTransitions = [];
		foreach ($rJudged + $rPrev['states'] as $rID => $rUnused) {
			$rFrom = $rPrev['states'][$rID] ?? null;
			$rTo = $rJudged[$rID] ?? null;
			if ($rFrom !== $rTo) {
				$rTransitions[$rID] = [$rFrom, $rTo];
				if ($rTo !== null && $rFrom !== null) {
					ClusterAudit::log('node.health', $rID, ['from' => $rFrom, 'to' => $rTo], 'liveness');
				}
			}
		}
		ksort($rOkSince);
		if ($rTransitions !== [] || $rGuard !== $rPrev['guard'] || $rOkSince !== $rPrev['ok_since']) {
			ksort($rJudged);
			ClusterHealth::write($rJudged, $rGuard, $rOkSince);
		}
		return $rTransitions;
	}
}
