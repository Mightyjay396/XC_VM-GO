<?php

namespace XcVm\Domain\Cluster;

use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * Whether a node's agent holds its loopback relay proxy's port, as its
 * heartbeat reports it (`relay`; ADR 0004, Phase 9's eighth increment), kept
 * with the node (`cluster_nodes.relay_down_since`, `relay_error`, migration
 * 054) and shown on the Cluster Nodes page and by `server:diagnose`.
 *
 * ```text
 * relay   {"bound": true}
 *         {"bound": false, "since_ms": <agent's unix ms>, "failures": n, "error": "…"}
 * ```
 *
 * The port (127.0.0.1:31290) is an unprivileged one: while another process
 * holds it, the node's data-plane relays and file reads fail and are retried,
 * and before this only the node's own log said why. A heartbeat without
 * `relay` (an older agent, or one whose proxy has not tried yet) changes
 * nothing. The row is written only on a change of state (or of the error),
 * and each transition is audited: `node.relay_unbound` with the error,
 * `node.relay_bound`. `relay_down_since` is on MAIN's clock: the agent's
 * `since_ms` moved by the node's clock offset, never later than now.
 */
final class NodeRelay {
	use DatabaseAware;

	/** Longest error kept. */
	public const MAX_ERROR = 255;

	/**
	 * The report as MAIN keeps it: `[down_since, error]`, `[null, null]` for a
	 * bound port, or null when it is not a report (nothing changes).
	 *
	 * @return array{0: int|null, 1: string|null}|null
	 */
	public static function normalise(mixed $rRelay, int $rNowMs, int $rOffsetMs = 0): ?array {
		if (!is_array($rRelay) || !is_bool($rRelay['bound'] ?? null)) {
			return null;
		}
		if ($rRelay['bound']) {
			return [null, null];
		}
		$rSince = is_int($rRelay['since_ms'] ?? null) && $rRelay['since_ms'] > 0 ? min($rNowMs, $rRelay['since_ms'] - $rOffsetMs) : $rNowMs;
		$rError = is_string($rRelay['error'] ?? null) ? (string) preg_replace('/[^\x20-\x7e]/', '', $rRelay['error']) : '';
		return [intdiv(max(0, $rSince), 1000), substr($rError, 0, self::MAX_ERROR)];
	}

	/**
	 * Keep a heartbeat's `relay`, when it changed what the row holds.
	 *
	 * @param array<string, mixed> $rNode cluster_nodes row
	 */
	public static function record(array $rNode, mixed $rRelay, int $rNowMs, int $rOffsetMs = 0): void {
		$rReport = self::normalise($rRelay, $rNowMs, $rOffsetMs);
		if ($rReport === null || !array_key_exists('relay_down_since', $rNode)) {
			return; // not a report, or a table from before migration 054
		}
		[$rSince, $rError] = $rReport;
		$rWasDown = $rNode['relay_down_since'] !== null;
		if ($rSince === null) {
			if ($rWasDown) {
				NodeRegistry::update((int) $rNode['server_id'], ['relay_down_since' => null, 'relay_error' => null]);
				ClusterAudit::log('node.relay_bound', (int) $rNode['server_id'], ['down_since' => (int) $rNode['relay_down_since']], 'node');
			}
			return;
		}
		if (!$rWasDown) {
			NodeRegistry::update((int) $rNode['server_id'], ['relay_down_since' => $rSince, 'relay_error' => $rError]);
			ClusterAudit::log('node.relay_unbound', (int) $rNode['server_id'], ['error' => $rError], 'node');
		} elseif ($rError !== (string) ($rNode['relay_error'] ?? '')) {
			NodeRegistry::update((int) $rNode['server_id'], ['relay_error' => $rError]);
		}
	}
}
