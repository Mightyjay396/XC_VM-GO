<?php

namespace XcVm\Core\Cluster\Crypto;

/**
 * Node signatures: an LB's Ed25519 node key signing its own requests
 * (`X-XCVM-Node-Sig` on token and enrolment ops) and relay connects.
 *
 * ```text
 * sig = Ed25519(node_sign_sk, "xcvm-node-sig-v1" ‖ lp(purpose) ‖ payload)
 * ```
 *
 * The domain string differs from the panel's `xcvm-sig-v1`, so no node
 * signature can ever pass as a panel record, whatever the tag or purpose.
 */
final class NodeSig {
	public const PURPOSES = ['request', 'enrol', 'relay', 'digest'];

	public static function input(string $rPurpose, string $rPayload): string {
		if (!in_array($rPurpose, self::PURPOSES, true)) {
			throw new \InvalidArgumentException('Unknown node signature purpose: ' . $rPurpose);
		}
		return 'xcvm-node-sig-v1' . Enc::lp($rPurpose) . $rPayload;
	}

	/** @param string $rSecretKey 64-byte Ed25519 secret key (sodium form). */
	public static function sign(string $rSecretKey, string $rPurpose, string $rPayload): string {
		return sodium_crypto_sign_detached(self::input($rPurpose, $rPayload), $rSecretKey);
	}

	public static function verify(string $rNodeSignPub, string $rPurpose, string $rPayload, string $rSig): bool {
		if (strlen($rNodeSignPub) !== 32 || strlen($rSig) !== 64 || !in_array($rPurpose, self::PURPOSES, true)) {
			return false;
		}
		try {
			return sodium_crypto_sign_verify_detached($rSig, self::input($rPurpose, $rPayload), $rNodeSignPub);
		} catch (\SodiumException) {
			return false;
		}
	}
}
