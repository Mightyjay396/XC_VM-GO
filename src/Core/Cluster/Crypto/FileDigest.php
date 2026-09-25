<?php

namespace XcVm\Core\Cluster\Crypto;

/**
 * `X-XCVM-File-Digest`: the owner of a file vouching for what `/xfile` serves.
 *
 * ```text
 * doc    = JSON {"v":1, "typ":"xcvm-file-digest", "tid", "owner_sid", "size", "sha256", "iat"} (sorted keys)
 * header = b64url(doc) "." b64url(sig)
 * ```
 *
 * When MAIN owns the file, `xcvm_core` signs with tag `dig` (a restrictive
 * record). When an LB owns it, the LB signs with its node key (NodeSig
 * purpose `digest`), and the fetcher checks it against that node's key in the
 * signed node list. The fetcher hashes what it received and refuses a
 * mismatch before the file is used.
 */
final class FileDigest {
	public static function document(string $rTid, int $rOwnerSid, int $rSize, string $rSha256Hex, int $rIat): string {
		if (!preg_match('/^[0-9a-f]{64}$/', $rSha256Hex) || $rSize < 0 || $rOwnerSid <= 0) {
			throw new \InvalidArgumentException('file digest fields');
		}
		$rDoc = ['v' => 1, 'typ' => 'xcvm-file-digest', 'tid' => $rTid, 'owner_sid' => $rOwnerSid, 'size' => $rSize, 'sha256' => $rSha256Hex, 'iat' => $rIat];
		ksort($rDoc);
		return (string) json_encode($rDoc, JSON_UNESCAPED_SLASHES);
	}

	public static function header(string $rDoc, string $rSig): string {
		return Enc::b64url($rDoc) . '.' . Enc::b64url($rSig);
	}

	/**
	 * Verify a header. `$rPanelSignPub` for MAIN-owned files, else the owner
	 * node's key in `$rNodeSignPub`.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function verify(string $rHeader, string $rTid, ?string $rPanelSignPub, ?string $rNodeSignPub = null): ?array {
		if (strlen($rHeader) > 2048 || substr_count($rHeader, '.') !== 1) {
			return null;
		}
		[$rDocPart, $rSigPart] = explode('.', $rHeader);
		$rDoc = Enc::b64urlDecode($rDocPart);
		$rSig = Enc::b64urlDecode($rSigPart);
		if ($rDoc === null || $rSig === null) {
			return null;
		}
		$rOK = $rPanelSignPub !== null
			? PanelSig::verify($rPanelSignPub, 'dig', $rDoc, $rSig)
			: ($rNodeSignPub !== null && NodeSig::verify($rNodeSignPub, 'digest', $rDoc, $rSig));
		if (!$rOK) {
			return null;
		}
		$rData = json_decode($rDoc, true);
		if (!is_array($rData) || ($rData['v'] ?? null) !== 1 || ($rData['typ'] ?? null) !== 'xcvm-file-digest'
			|| ($rData['tid'] ?? null) !== $rTid || !is_int($rData['size'] ?? null) || !preg_match('/^[0-9a-f]{64}$/', (string) ($rData['sha256'] ?? ''))) {
			return null;
		}
		return $rData;
	}

	/** Does a received file match the verified digest? */
	public static function matches(array $rDigest, string $rPath): bool {
		return is_file($rPath) && filesize($rPath) === $rDigest['size'] && hash_equals($rDigest['sha256'], (string) hash_file('sha256', $rPath));
	}
}
