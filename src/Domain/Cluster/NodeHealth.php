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
 *
 * Hysteresis (settle()): a node's published state gets worse at once but
 * better only after RECOVER_MS of steady health, so a node whose heartbeats
 * straddle the 10 s threshold does not flap between ok and suspect (each flip
 * rewrites the servers cache and changes its routing weight). An offline node
 * that speaks again is suspect at once, so routing resumes cautiously.
 */
final class NodeHealth {
	public const SUSPECT_AFTER_MS = 10000;

	/**
	 * Steady health a node needs before its published state improves to ok.
	 * Longer than the suspect threshold, or a node whose long gaps come every
	 * ~11 s would recover between them and flap as often as without it.
	 */
	public const RECOVER_MS = 30000;

	private const RANK = ['ok' => 0, 'suspect' => 1, 'offline' => 2];

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

	/**
	 * The state to publish, from the previous one and this pass's judgement.
	 *
	 * @param string|null $rPrev     Published last pass (null: not judged yet).
	 * @param string      $rRaw      This pass's state(): ok, suspect or offline.
	 * @param int|null    $rOkSince  Since when the node has been judged ok without a break.
	 * @return array{0: string, 1: ?int} [state to publish, ok-since to keep]
	 */
	public static function settle(?string $rPrev, string $rRaw, ?int $rOkSince, int $rNowMs): array {
		$rOkSince = $rRaw === 'ok' ? ($rOkSince ?? $rNowMs) : null;
		if ($rPrev === null || !isset(self::RANK[$rPrev]) || self::RANK[$rRaw] >= self::RANK[$rPrev]) {
			return [$rRaw, $rOkSince];
		}
		if ($rRaw === 'ok' && $rNowMs - (int) $rOkSince >= self::RECOVER_MS) {
			return ['ok', $rOkSince];
		}
		return ['suspect', $rOkSince];
	}
}
