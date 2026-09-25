<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\Crypto\Canonical;
use XcVm\Core\Cluster\Crypto\ClusterCrypto;
use XcVm\Core\Cluster\Crypto\Enc;

/**
 * Panel-signed replies for when MAIN cannot (or must not) answer with the
 * node's session keys: refusals before or instead of a session, `health` and
 * `challenge`.
 *
 * ```text
 * body   = JSON {"v":1, "typ":"xcvm-denial", "reason", "node", "req_nonce", "main_time_ms", …}
 * header = X-XCVM-Panel-Sig: b64url(Ed25519 panel sig, tag "den" or "hlt")
 * ```
 *
 * A denial names the node and the request nonce it answers, so a captured one
 * can neither be replayed to another request nor to another node: an agent
 * acts on it only when both match what it just sent. Denials are restrictive
 * records, signable without a licence.
 */
final class DenialFactory {
	public const REASONS = [
		'BAD_REQUEST', 'BAD_MAC', 'BAD_NODE_SIG', 'REPLAY', 'CLOCK_SKEW', 'PROTO',
		'UNKNOWN_NODE', 'NODE_REVOKED', 'TOKEN_EXPIRED', 'NOT_ACTIVE', 'ENROL_EXPIRED',
		'LICENCE_INVALID', 'CLOCK', 'STARTING', 'DISABLED', 'DB', 'UNKNOWN_OP',
		'CHALLENGE', 'RATE_LIMITED', 'CODE_INVALID', 'ENROL_CONFLICT', 'USEQ_GAP', 'FLOW_OFF', 'SNAP_GAP',
	];

	/**
	 * @param array<string, mixed> $rExtra Reason-specific fields (min/max proto, revoked_gen, …).
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	public static function deny(ClusterCrypto $rCrypto, int $rStatus, string $rReason, ?string $rNode = null, ?string $rReqNonce = null, array $rExtra = []): array {
		$rDoc = ['v' => 1, 'typ' => 'xcvm-denial', 'reason' => $rReason, 'node' => $rNode, 'req_nonce' => $rReqNonce === null ? null : bin2hex($rReqNonce), 'main_time_ms' => ClusterClock::nowMs()] + $rExtra;
		return self::signed($rCrypto, $rStatus, 'den', $rDoc);
	}

	/**
	 * A panel-signed JSON reply.
	 *
	 * @param array<string, mixed> $rDoc
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	public static function signed(ClusterCrypto $rCrypto, int $rStatus, string $rTag, array $rDoc): array {
		$rBody = (string) json_encode($rDoc, JSON_UNESCAPED_SLASHES);
		return [
			'status' => $rStatus,
			'headers' => [
				'Content-Type' => 'application/json',
				Canonical::H_PANEL_SIG => Enc::b64url($rCrypto->sign($rTag, $rBody)),
				'Cache-Control' => 'no-store',
			],
			'body' => $rBody,
		];
	}

	/**
	 * A plain, unsigned refusal: only when the panel cannot sign at all (no
	 * extension). Agents treat it as a transport error, never as a decision.
	 *
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	public static function unsigned(int $rStatus, string $rReason): array {
		return ['status' => $rStatus, 'headers' => ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'], 'body' => (string) json_encode(['v' => 1, 'reason' => $rReason])];
	}
}
