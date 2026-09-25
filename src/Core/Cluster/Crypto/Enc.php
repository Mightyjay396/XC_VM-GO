<?php

namespace XcVm\Core\Cluster\Crypto;

/**
 * The cluster API's one canonical encoding (ADR-002 in xcvm_core, "Encoding"):
 * `lp(x) = u32be(len(x)) ‖ x` for variable-length fields, big-endian 64-bit
 * integers, fixed-length keys and hashes as they are. No two field lists can
 * produce the same bytes, so a MAC or AAD over them is unambiguous.
 */
final class Enc {
	public static function lp(string $rBytes): string {
		return pack('N', strlen($rBytes)) . $rBytes;
	}

	/** Unsigned (and non-negative signed) 64-bit big-endian. */
	public static function u64(int $rValue): string {
		if ($rValue < 0) {
			throw new \InvalidArgumentException('u64 must not be negative');
		}
		return pack('J', $rValue);
	}

	public static function u32(int $rValue): string {
		if ($rValue < 0 || $rValue > 0xFFFFFFFF) {
			throw new \InvalidArgumentException('u32 out of range');
		}
		return pack('N', $rValue);
	}

	/** Constant-time comparison of two binary strings. */
	public static function equals(string $rA, string $rB): bool {
		return hash_equals($rA, $rB);
	}

	public static function b64url(string $rBytes): string {
		return rtrim(strtr(base64_encode($rBytes), '+/', '-_'), '=');
	}

	public static function b64urlDecode(string $rText): ?string {
		if ($rText === '' || preg_match('/[^A-Za-z0-9_-]/', $rText)) {
			return null;
		}
		$rOut = base64_decode(strtr($rText, '-_', '+/') . str_repeat('=', (4 - strlen($rText) % 4) % 4), true);
		return $rOut === false ? null : $rOut;
	}
}
