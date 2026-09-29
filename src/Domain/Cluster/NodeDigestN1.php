<?php

namespace XcVm\Domain\Cluster;

/**
 * The owners whose chunk digest named no request, as a node's agent reports
 * them in every heartbeat (`digest_n1`; ADR 0004, "The N−1 digest report"),
 * kept with the node (`cluster_nodes.digest_n1`, migration 055) and shown on
 * the Cluster Nodes page and by `server:diagnose`.
 *
 * ```text
 * digest_n1   [<owner server id>, …]   sorted, the last 24 h; [] for none
 * ```
 *
 * Only an owner from before the nonce sends such a digest, and a fetcher takes
 * it inside the ±90 s window. Once no node names one, an operator can refuse
 * them all with `lb_digest_nonce_required`. A heartbeat without the field (an
 * agent from before it) changes nothing, so the column stays NULL; the row is
 * written only when the list changes.
 */
final class NodeDigestN1 {
	/** Most owners kept: they fit the 255-character column. */
	public const MAX_OWNERS = 32;

	/**
	 * The report as MAIN keeps it, sorted server ids, or null when it is not one.
	 *
	 * @return list<int>|null
	 */
	public static function normalise(mixed $rReport): ?array {
		if (!is_array($rReport) || !array_is_list($rReport)) {
			return null;
		}
		$rOwners = array_values(array_unique(array_filter($rReport, static fn(mixed $rSid): bool => is_int($rSid) && $rSid > 0 && $rSid < 1000000)));
		sort($rOwners);
		return array_slice($rOwners, 0, self::MAX_OWNERS);
	}

	/**
	 * The owners a row holds; null while its agent has not reported.
	 *
	 * @param array<string, mixed> $rNode cluster_nodes row
	 * @return list<int>|null
	 */
	public static function owners(array $rNode): ?array {
		return isset($rNode['digest_n1']) ? self::normalise(json_decode((string) $rNode['digest_n1'], true)) : null;
	}

	/**
	 * Keep a heartbeat's `digest_n1`, when it changed what the row holds.
	 *
	 * @param array<string, mixed> $rNode cluster_nodes row
	 */
	public static function record(array $rNode, mixed $rReport): void {
		$rOwners = self::normalise($rReport);
		if ($rOwners === null || !array_key_exists('digest_n1', $rNode) || $rOwners === self::owners($rNode)) {
			return; // not a report, a table from before migration 055, or no change
		}
		NodeRegistry::update((int) $rNode['server_id'], ['digest_n1' => json_encode($rOwners)]);
	}
}
