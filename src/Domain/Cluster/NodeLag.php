<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\ClusterDiagnosis;

/**
 * A node's event lanes' lag and the MAIN URLs it cannot reach, as its
 * heartbeat reports them (plan, section 11, "Servers list badges": P0/P1 lag,
 * "MAIN cluster URL unreachable from this LB"), kept with the node
 * (`cluster_nodes.p0_lag_since`, `p1_lag_since`, `unreachable_urls`,
 * migration 056) for the Servers list's badges.
 *
 * ```text
 * lanes        {"p0": {"files": n, "lag_ms": n}, "p1": {…}}   the oldest spooled event's age
 * unreachable  [{"url": "https://…", "for_ms": n}]            failed, not answered since
 * ```
 *
 * Both are ages on the node's own clock, so no clock offset applies. A lane
 * lags once its oldest event has waited past ClusterDiagnosis::OUTBOX_LAG_SEC,
 * as `server:diagnose` judges it; `<lane>_lag_since` is then when that began,
 * on MAIN's clock (unix seconds), and NULL again once the lane catches up. The
 * row is written only on such a transition or a change of the URL list, and
 * each is audited. A heartbeat without the fields (an older agent) changes
 * nothing.
 */
final class NodeLag {
	/** The lanes kept: P0 (state) and P1 (logs). */
	public const LANES = ['p0', 'p1'];

	/** The longest URL list kept (space-separated). */
	public const MAX_URLS = 1024;

	/**
	 * Each lane's lag as MAIN keeps it: when it began (MAIN's unix seconds),
	 * or null for a lane that does not lag. Null when it is not a report.
	 *
	 * @return array<string, int|null>|null
	 */
	public static function lanes(mixed $rLanes, int $rNowMs): ?array {
		if (!is_array($rLanes)) {
			return null;
		}
		$rOut = [];
		foreach (self::LANES as $rLane) {
			$rLag = $rLanes[$rLane]['lag_ms'] ?? null;
			$rOut[$rLane] = is_int($rLag) && $rLag > ClusterDiagnosis::OUTBOX_LAG_SEC * 1000 ? intdiv($rNowMs - $rLag, 1000) : null;
		}
		return $rOut;
	}

	/** The unreachable URLs as MAIN keeps them (sorted, space-separated, printable), or null when it is not a report. */
	public static function urls(mixed $rUrls): ?string {
		if (!is_array($rUrls) || !array_is_list($rUrls)) {
			return null;
		}
		$rList = [];
		foreach ($rUrls as $rEntry) {
			if (is_array($rEntry) && is_string($rEntry['url'] ?? null)) {
				$rList[] = (string) preg_replace('/[^\x21-\x7e]/', '', $rEntry['url']);
			}
		}
		$rList = array_unique(array_filter($rList));
		sort($rList);
		return substr(implode(' ', $rList), 0, self::MAX_URLS);
	}

	/**
	 * Keep a heartbeat's `lanes` and `unreachable`, when they changed what the row holds.
	 *
	 * @param array<string, mixed> $rNode cluster_nodes row
	 * @param array<string, mixed> $rPayload the heartbeat's payload
	 */
	public static function record(array $rNode, array $rPayload, int $rNowMs): void {
		if (!array_key_exists('p0_lag_since', $rNode)) {
			return; // a table from before migration 056
		}
		$rServerID = (int) $rNode['server_id'];
		$rSet = [];
		foreach (self::lanes($rPayload['lanes'] ?? null, $rNowMs) ?? [] as $rLane => $rSince) {
			$rWas = $rNode[$rLane . '_lag_since'] ?? null;
			if (($rSince === null) !== ($rWas === null)) {
				$rSet[$rLane . '_lag_since'] = $rSince;
				ClusterAudit::log($rSince === null ? 'node.lane_caught_up' : 'node.lane_lagging', $rServerID, $rSince === null ? ['lane' => $rLane, 'since' => (int) $rWas] : ['lane' => $rLane, 'lag_s' => intdiv($rNowMs, 1000) - $rSince], 'node');
			}
		}
		$rUrls = self::urls($rPayload['unreachable'] ?? null);
		if ($rUrls !== null && $rUrls !== (string) ($rNode['unreachable_urls'] ?? '')) {
			$rSet['unreachable_urls'] = $rUrls === '' ? null : $rUrls;
			ClusterAudit::log($rUrls === '' ? 'node.urls_reachable' : 'node.urls_unreachable', $rServerID, ['urls' => $rUrls], 'node');
		}
		if ($rSet !== []) {
			NodeRegistry::update($rServerID, $rSet);
		}
	}
}
