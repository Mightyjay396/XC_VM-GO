<?php

namespace XcVm\Core\Cluster\Crypto;

/**
 * `X-XCVM-Relay-Auth`: the child node proving, per connect, that it holds the
 * key its relay ticket names.
 *
 * ```text
 * msg    = "xcvm-relay-auth-v1" ‖ lp(ticket wire) ‖ lp(METHOD) ‖ lp(target) ‖ u64(ts_ms) ‖ nonce[16]
 * sig    = NodeSig(child node key, purpose "relay", msg)
 * header = ts_ms "." hex(nonce) "." b64url(sig)
 * ```
 *
 * The parent checks, at connect only: the ticket (Ticket::verify, tag `rly`),
 * `parent_sid == SERVER_ID`, the stream, the child active with this key and
 * generation in the signed node list, the ±90 s window, and a fresh nonce
 * (the 180 s nonce cache). This class does the cryptographic part.
 */
final class RelayAuth {
	public static function message(string $rTicketWire, string $rMethod, string $rTarget, int $rTsMs, string $rNonce): string {
		if (strlen($rNonce) !== 16) {
			throw new \InvalidArgumentException('nonce must be 16 bytes');
		}
		return 'xcvm-relay-auth-v1' . Enc::lp($rTicketWire) . Enc::lp(strtoupper($rMethod)) . Enc::lp($rTarget) . Enc::u64($rTsMs) . $rNonce;
	}

	/** @param string $rNodeSecretKey The child's 64-byte Ed25519 secret key. */
	public static function header(string $rNodeSecretKey, string $rTicketWire, string $rMethod, string $rTarget, int $rTsMs, ?string $rNonce = null): string {
		$rNonce ??= random_bytes(16);
		$rSig = NodeSig::sign($rNodeSecretKey, 'relay', self::message($rTicketWire, $rMethod, $rTarget, $rTsMs, $rNonce));
		return $rTsMs . '.' . bin2hex($rNonce) . '.' . Enc::b64url($rSig);
	}

	/**
	 * Verify a header against the child key the ticket names.
	 *
	 * @return array{ts_ms: int, nonce: string}|null The timestamp and nonce, for the window and replay checks.
	 */
	public static function verify(string $rChildSignPub, string $rHeader, string $rTicketWire, string $rMethod, string $rTarget, int $rNowMs): ?array {
		if (!preg_match('/^([1-9][0-9]{12,15})\.([0-9a-f]{32})\.([A-Za-z0-9_-]{86})$/', $rHeader, $rM)) {
			return null;
		}
		$rTs = (int) $rM[1];
		$rNonce = (string) hex2bin($rM[2]);
		$rSig = Enc::b64urlDecode($rM[3]);
		if ($rSig === null || !Canonical::withinWindow($rTs, $rNowMs)
			|| !NodeSig::verify($rChildSignPub, 'relay', self::message($rTicketWire, $rMethod, $rTarget, $rTs, $rNonce), $rSig)) {
			return null;
		}
		return ['ts_ms' => $rTs, 'nonce' => $rNonce];
	}
}
