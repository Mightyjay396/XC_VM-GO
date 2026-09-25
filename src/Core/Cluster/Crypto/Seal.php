<?php

namespace XcVm\Core\Cluster\Crypto;

/**
 * XCVM-SEAL-v1: an anonymous-sender box to an X25519 public key (ADR-002).
 *
 * ```text
 * sealed = "xs1" ‖ eph_pub[32] ‖ nonce[12] ‖ AES-256-GCM(key, pt, aad) ‖ tag[16]
 * shared = X25519(eph_sk, rcpt_pub)       an all-zero result is refused
 * key    = HKDF-SHA256(ikm = shared, salt = eph_pub ‖ rcpt_pub, info = "xcvm/seal/v1/" ‖ purpose)
 * aad    = "xs1" ‖ lp(purpose) ‖ lp(context)
 * ```
 *
 * Used for tokens, replica sections, stream bundles and pre-token bodies. The
 * purpose and context are bound, so a blob sealed for one use never opens as
 * another.
 */
final class Seal {
	public const MAGIC = 'xs1';
	public const OVERHEAD = 3 + 32 + 12 + 16;

	public static function seal(string $rRecipientPub, string $rPurpose, string $rContext, string $rPlaintext): string {
		return self::sealWith(random_bytes(32), random_bytes(12), $rRecipientPub, $rPurpose, $rContext, $rPlaintext);
	}

	/** Deterministic form for vectors; production calls seal(). */
	public static function sealWith(string $rEphSk, string $rNonce, string $rRecipientPub, string $rPurpose, string $rContext, string $rPlaintext): string {
		if (strlen($rEphSk) !== 32 || strlen($rRecipientPub) !== 32 || strlen($rNonce) !== 12) {
			throw new \InvalidArgumentException('SEAL key or nonce length');
		}
		$rEphPub = sodium_crypto_scalarmult_base($rEphSk);
		$rShared = self::shared($rEphSk, $rRecipientPub);
		if ($rShared === null) {
			throw new \InvalidArgumentException('SEAL recipient key is not usable');
		}
		$rKey = self::key($rShared, $rEphPub, $rRecipientPub, $rPurpose);
		$rTag = '';
		$rCipher = openssl_encrypt($rPlaintext, 'aes-256-gcm', $rKey, OPENSSL_RAW_DATA, $rNonce, $rTag, self::aad($rPurpose, $rContext), 16);
		if ($rCipher === false) {
			throw new \RuntimeException('AES-256-GCM failed');
		}
		return self::MAGIC . $rEphPub . $rNonce . $rCipher . $rTag;
	}

	/** @return string|null The plaintext, or null when the blob does not open for this key, purpose and context. */
	public static function open(string $rRecipientSk, string $rPurpose, string $rContext, string $rSealed): ?string {
		if (strlen($rRecipientSk) !== 32 || strlen($rSealed) < self::OVERHEAD || substr($rSealed, 0, 3) !== self::MAGIC) {
			return null;
		}
		$rEphPub = substr($rSealed, 3, 32);
		$rShared = self::shared($rRecipientSk, $rEphPub);
		if ($rShared === null) {
			return null;
		}
		$rKey = self::key($rShared, $rEphPub, sodium_crypto_scalarmult_base($rRecipientSk), $rPurpose);
		$rPlain = openssl_decrypt(substr($rSealed, 47, -16), 'aes-256-gcm', $rKey, OPENSSL_RAW_DATA, substr($rSealed, 35, 12), substr($rSealed, -16), self::aad($rPurpose, $rContext));
		return $rPlain === false ? null : $rPlain;
	}

	/** X25519, refusing the all-zero (non-contributory) result. */
	private static function shared(string $rSk, string $rPub): ?string {
		try {
			$rShared = sodium_crypto_scalarmult($rSk, $rPub);
		} catch (\SodiumException) {
			return null;
		}
		return hash_equals(str_repeat("\0", 32), $rShared) ? null : $rShared;
	}

	private static function key(string $rShared, string $rEphPub, string $rRecipientPub, string $rPurpose): string {
		return hash_hkdf('sha256', $rShared, 32, 'xcvm/seal/v1/' . $rPurpose, $rEphPub . $rRecipientPub);
	}

	private static function aad(string $rPurpose, string $rContext): string {
		return self::MAGIC . Enc::lp($rPurpose) . Enc::lp($rContext);
	}
}
