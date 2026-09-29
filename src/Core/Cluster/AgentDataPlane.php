<?php

namespace XcVm\Core\Cluster;

/**
 * The two things a node's PHP cannot do for itself while it serves a data-plane
 * request, and asks its agent for over the local socket (plan, section 6.6):
 *
 * ```text
 * POST /v1/nonce        {nonce}                             -> {fresh}
 * POST /v1/file_digest  {tid, owner_sid, size, sha256[, offset, total]} -> {header}
 * ```
 *
 * - **The nonce window.** A parent verifying a child's relay or file request
 *   must see each nonce once. MAIN has the cluster bus for that; a load
 *   balancer serving as a parent has only its agent, which keeps a window of
 *   `NonceWindow` (180 s) — longer than the window a signature is accepted in.
 * - **The file digest.** The owner of a file vouches for what it served with
 *   its *node* key, which the agent holds and PHP does not; the fetcher
 *   verifies the same document ({@see \XcVm\Core\Cluster\Crypto\FileDigest}).
 *
 * Both answer null when the agent did not: no agent, no socket, a refusal. A
 * caller must treat null as "I cannot prove this" and refuse the request — a
 * parent that cannot spend a nonce cannot tell a replay from a first attempt.
 * Nothing here reaches MAIN. The parent's and the owner's checks that use
 * them are RelayGuard's and FileTicketServer's.
 */
final class AgentDataPlane {
	/** Seconds one call may take: the request that needs it is already waiting. */
	public const TIMEOUT = 2.0;

	/**
	 * Spend a nonce: true when this parent had not seen it, false when it had
	 * (or its window is full), null when the agent did not answer.
	 *
	 * @param string $rNonceHex The relay auth header's nonce, 16 bytes as hex.
	 */
	public static function nonceFresh(string $rNonceHex): ?bool {
		if (!preg_match('/^[0-9a-fA-F]{32}\z/', $rNonceHex)) {
			return false;
		}
		$rOut = AgentClient::request('POST', '/v1/nonce', ['nonce' => strtolower($rNonceHex)], self::TIMEOUT);
		if ($rOut === null || $rOut[0] !== 200 || !is_array($rOut[1]) || !is_bool($rOut[1]['fresh'] ?? null)) {
			return null;
		}
		return $rOut[1]['fresh'];
	}

	/**
	 * The `X-XCVM-File-Digest` header for a file this node is serving: the
	 * agent signs `FileDigest`'s document with the node key. Null when it did
	 * not answer, and the caller then serves nothing — an unvouched body is
	 * what the digest exists to prevent.
	 *
	 * @param string      $rTid      The file ticket's id.
	 * @param string      $rSha256   The file's (or chunk's) SHA-256, lowercase hex.
	 * @param int|null    $rOffset   Where the chunk starts, with $rTotal the file's size (`/xfile`'s chunks); null for a whole file.
	 * @param string|null $rNonceHex The answered request's `X-XCVM-File-Auth` nonce (32 hex), which binds a chunk's digest to that request.
	 *                               An agent from before it ignores the field and signs without it.
	 */
	public static function fileDigest(string $rTid, int $rOwnerSid, int $rSize, string $rSha256, ?int $rIat = null, ?int $rOffset = null, ?int $rTotal = null, ?string $rNonceHex = null): ?string {
		if (!preg_match('/^[A-Za-z0-9_-]{8,64}\z/', $rTid) || !preg_match('/^[0-9a-f]{64}\z/', $rSha256) || $rSize < 0 || $rOwnerSid <= 0
			|| ($rOffset === null) !== ($rTotal === null) || ($rOffset !== null && ($rOffset < 0 || $rTotal < $rOffset + $rSize))
			|| ($rNonceHex !== null && ($rOffset === null || !preg_match('/^[0-9a-f]{32}\z/', $rNonceHex)))
		) {
			return null;
		}
		$rBody = [
			'tid' => $rTid,
			'owner_sid' => $rOwnerSid,
			'size' => $rSize,
			'sha256' => $rSha256,
			'iat' => $rIat ?? time(),
		];
		if ($rOffset !== null) {
			$rBody += ['offset' => $rOffset, 'total' => $rTotal];
		}
		if ($rNonceHex !== null) {
			$rBody['nonce'] = $rNonceHex;
		}
		$rOut = AgentClient::request('POST', '/v1/file_digest', $rBody, self::TIMEOUT);
		if ($rOut === null || $rOut[0] !== 200 || !is_array($rOut[1])) {
			return null;
		}
		$rHeader = $rOut[1]['header'] ?? null;
		return is_string($rHeader) && $rHeader !== '' && substr_count($rHeader, '.') === 1 ? $rHeader : null;
	}
}
