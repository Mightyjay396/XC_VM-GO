<?php

namespace XcVm\Core\Cluster\Crypto;

/**
 * XCVM-BOX-v1: a session body, both directions (ADR-002).
 *
 * ```text
 * box = "xb1" ‖ nonce[12] ‖ AES-256-GCM(K_enc_up | K_enc_down, pt, aad) ‖ tag[16]
 * aad = "xb1" ‖ lp(context)        context = Canonical request/response context
 * ```
 *
 * No compression before encryption (CRIME); a body that is not a box is refused.
 */
final class Box {
	public const MAGIC = 'xb1';
	public const OVERHEAD = 3 + 12 + 16;

	public static function box(string $rKey, string $rContext, string $rPlaintext): string {
		return self::boxWith($rKey, random_bytes(12), $rContext, $rPlaintext);
	}

	/** Deterministic form for vectors; production calls box(). */
	public static function boxWith(string $rKey, string $rNonce, string $rContext, string $rPlaintext): string {
		self::checkKey($rKey);
		if (strlen($rNonce) !== 12) {
			throw new \InvalidArgumentException('BOX nonce must be 12 bytes');
		}
		$rTag = '';
		$rCipher = openssl_encrypt($rPlaintext, 'aes-256-gcm', $rKey, OPENSSL_RAW_DATA, $rNonce, $rTag, self::aad($rContext), 16);
		if ($rCipher === false) {
			throw new \RuntimeException('AES-256-GCM failed');
		}
		return self::MAGIC . $rNonce . $rCipher . $rTag;
	}

	/** @return string|null The plaintext, or null when the box does not open. */
	public static function open(string $rKey, string $rContext, string $rBoxed): ?string {
		self::checkKey($rKey);
		if (strlen($rBoxed) < self::OVERHEAD || substr($rBoxed, 0, 3) !== self::MAGIC) {
			return null;
		}
		$rPlain = openssl_decrypt(substr($rBoxed, 15, -16), 'aes-256-gcm', $rKey, OPENSSL_RAW_DATA, substr($rBoxed, 3, 12), substr($rBoxed, -16), self::aad($rContext));
		return $rPlain === false ? null : $rPlain;
	}

	private static function aad(string $rContext): string {
		return self::MAGIC . Enc::lp($rContext);
	}

	private static function checkKey(string $rKey): void {
		if (strlen($rKey) !== 32) {
			throw new \InvalidArgumentException('BOX key must be 32 bytes');
		}
	}
}
