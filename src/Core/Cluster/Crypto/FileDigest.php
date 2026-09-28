<?php

namespace XcVm\Core\Cluster\Crypto;

/**
 * `X-XCVM-File-Digest`: the owner of a file vouching for what `/xfile` serves.
 *
 * ```text
 * doc    = JSON {"v":1, "typ":"xcvm-file-digest", "tid", "owner_sid", "size", "sha256", "iat"
 *               [, "offset", "total"]} (sorted keys)
 * header = b64url(doc) "." b64url(sig)
 * ```
 *
 * `/xfile` serves a file in chunks of at most CHUNK bytes, and each chunk
 * carries a digest of its own naming where it starts (`offset`) and the
 * file's whole size (`total`): the fetcher checks each chunk before any of
 * its bytes reach the reader, and a chunk moved to another offset (or
 * served from another file) no longer matches. A digest without them covers
 * a whole file.
 *
 * When MAIN owns the file, `xcvm_core` signs with tag `dig` (a restrictive
 * record). When an LB owns it, the LB signs with its node key (NodeSig
 * purpose `digest`), and the fetcher checks it against that node's key in the
 * signed node list. The fetcher hashes what it received and refuses a
 * mismatch before the file is used.
 */
final class FileDigest {
	/** Longest `X-XCVM-File-Digest` header accepted, in bytes. */
	public const MAX_HEADER = 2048;

	/** The largest chunk one `/xfile` response carries (4 MiB). */
	public const CHUNK = 4194304;

	public static function document(string $rTid, int $rOwnerSid, int $rSize, string $rSha256Hex, int $rIat, ?int $rOffset = null, ?int $rTotal = null): string {
		if (!preg_match('/^[0-9a-f]{64}\z/', $rSha256Hex) || $rSize < 0 || $rOwnerSid <= 0 || ($rOffset === null) !== ($rTotal === null)
			|| ($rOffset !== null && ($rOffset < 0 || $rTotal < $rOffset + $rSize))
		) {
			throw new \InvalidArgumentException('file digest fields');
		}
		$rDoc = ['v' => 1, 'typ' => 'xcvm-file-digest', 'tid' => $rTid, 'owner_sid' => $rOwnerSid, 'size' => $rSize, 'sha256' => $rSha256Hex, 'iat' => $rIat];
		if ($rOffset !== null) {
			$rDoc += ['offset' => $rOffset, 'total' => $rTotal];
		}
		ksort($rDoc);
		return (string) json_encode($rDoc, JSON_UNESCAPED_SLASHES);
	}

	public static function header(string $rDoc, string $rSig): string {
		return Enc::joinSigned($rDoc, $rSig);
	}

	/**
	 * Verify a header. `$rPanelSignPub` for MAIN-owned files, else the owner
	 * node's key in `$rNodeSignPub`.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function verify(string $rHeader, string $rTid, ?string $rPanelSignPub, ?string $rNodeSignPub = null): ?array {
		$rParts = Enc::splitSigned($rHeader, self::MAX_HEADER);
		if ($rParts === null) {
			return null;
		}
		[$rDoc, $rSig] = $rParts;
		$rOK = $rPanelSignPub !== null
			? PanelSig::verify($rPanelSignPub, 'dig', $rDoc, $rSig)
			: ($rNodeSignPub !== null && NodeSig::verify($rNodeSignPub, 'digest', $rDoc, $rSig));
		if (!$rOK) {
			return null;
		}
		$rData = json_decode($rDoc, true);
		if (!is_array($rData) || ($rData['v'] ?? null) !== 1 || ($rData['typ'] ?? null) !== 'xcvm-file-digest'
			|| ($rData['tid'] ?? null) !== $rTid || !is_int($rData['size'] ?? null) || !preg_match('/^[0-9a-f]{64}\z/', (string) ($rData['sha256'] ?? ''))
			|| !is_int($rData['owner_sid'] ?? null)
		) {
			return null;
		}
		if (array_key_exists('offset', $rData) || array_key_exists('total', $rData)) {
			if (!is_int($rData['offset'] ?? null) || !is_int($rData['total'] ?? null) || $rData['offset'] < 0 || $rData['total'] < $rData['offset'] + $rData['size']) {
				return null;
			}
		}
		return $rData;
	}

	/**
	 * Does a received chunk match the verified digest, at the offset it was
	 * asked for?
	 *
	 * @param array<string, mixed> $rDigest
	 */
	public static function chunkMatches(array $rDigest, int $rOffset, string $rBytes): bool {
		return ($rDigest['offset'] ?? null) === $rOffset && $rDigest['size'] === strlen($rBytes) && hash_equals($rDigest['sha256'], hash('sha256', $rBytes));
	}

	/**
	 * Does a received file match the verified digest?
	 *
	 * @param array<string, mixed> $rDigest
	 */
	public static function matches(array $rDigest, string $rPath): bool {
		return is_file($rPath) && filesize($rPath) === $rDigest['size'] && hash_equals($rDigest['sha256'], (string) hash_file('sha256', $rPath));
	}
}
