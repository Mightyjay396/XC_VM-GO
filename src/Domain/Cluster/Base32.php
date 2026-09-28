<?php

namespace XcVm\Domain\Cluster;

/**
 * RFC 4648 base32 (A–Z, 2–7) without padding, as the enrolment code and
 * the SAS a node shows are written (EnrolCodeService, EnrolmentService):
 * the last character's unused bits are zero, and a decode drops a last
 * partial byte's bits.
 */
final class Base32 {
	public const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

	public static function encode(string $rBytes): string {
		if ($rBytes === '') {
			return '';
		}
		$rBits = '';
		foreach (str_split($rBytes) as $rByte) {
			$rBits .= str_pad(decbin(ord($rByte)), 8, '0', STR_PAD_LEFT);
		}
		$rOut = '';
		foreach (str_split($rBits, 5) as $rChunk) {
			$rOut .= self::ALPHABET[bindec(str_pad($rChunk, 5, '0'))];
		}
		return $rOut;
	}

	/** The bytes of upper-case base32 text; null when a character is not one. */
	public static function decode(string $rText): ?string {
		if ($rText === '') {
			return '';
		}
		if (strspn($rText, self::ALPHABET) !== strlen($rText)) {
			return null;
		}
		$rBits = '';
		foreach (str_split($rText) as $rChar) {
			$rBits .= str_pad(decbin(strpos(self::ALPHABET, $rChar)), 5, '0', STR_PAD_LEFT);
		}
		$rBytes = '';
		foreach (str_split(substr($rBits, 0, intdiv(strlen($rBits), 8) * 8), 8) as $rByte) {
			$rBytes .= chr(bindec($rByte));
		}
		return $rBytes;
	}
}
