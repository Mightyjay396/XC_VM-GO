<?php

namespace XcVm\Core\Cluster\Crypto;

/**
 * Panel signatures (ADR-002, "Panel signatures and record classes").
 *
 * ```text
 * sig = Ed25519(panel_sign_sk, "xcvm-sig-v1" ‖ lp(tag) ‖ payload)
 * ```
 *
 * The tag registry is closed and the extension derives each record's class
 * (restrictive R / granting G); PHP never declares it. Signing happens only in
 * `xcvm_core` (ClusterCrypto::sign); this class verifies, which any node can
 * do with the pinned panel public key.
 */
final class PanelSig {
	/** The closed registry. `tok` and `lea` are signed only by token and lease issue. */
	public const TAGS = ['cmd', 'blk', 'den', 'hlt', 'dig', 'pol', 'rep', 'bnd', 'rly', 'fil', 'nod', 'cfg', 'pre', 'tok', 'lea'];

	/** Tags whose records are always restrictive (signable without a licence). */
	public const RESTRICTIVE_TAGS = ['den', 'hlt', 'dig'];

	public static function input(string $rTag, string $rPayload): string {
		if (!in_array($rTag, self::TAGS, true)) {
			throw new \InvalidArgumentException('Unknown signature tag: ' . $rTag);
		}
		return 'xcvm-sig-v1' . Enc::lp($rTag) . $rPayload;
	}

	public static function verify(string $rPanelSignPub, string $rTag, string $rPayload, string $rSig): bool {
		if (strlen($rPanelSignPub) !== 32 || strlen($rSig) !== 64 || !in_array($rTag, self::TAGS, true)) {
			return false;
		}
		try {
			return sodium_crypto_sign_verify_detached($rSig, self::input($rTag, $rPayload), $rPanelSignPub);
		} catch (\SodiumException) {
			return false;
		}
	}
}
