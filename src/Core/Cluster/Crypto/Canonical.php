<?php

namespace XcVm\Core\Cluster\Crypto;

/**
 * Canonical request and response serialisation for the cluster API. It is the
 * BOX context and the input of the request/response MAC. ADR-002 leaves this
 * to the panel; this class is its definition, and the Go agent must produce
 * the same bytes (`CanonicalRequestTest` pins them).
 *
 * ```text
 * req_ctx = "xcvm-req-v1" ‖ u32(proto) ‖ lp(agent) ‖ lp(METHOD) ‖ lp(path) ‖ lp(query)
 *           ‖ lp(content_type) ‖ lp(content_encoding) ‖ lp(node) ‖ u64(epoch) ‖ u64(ts_ms) ‖ nonce[16]
 * res_ctx = "xcvm-res-v1" ‖ SHA-256(req_ctx) ‖ u32(status) ‖ lp(content_type) ‖ u64(ts_ms) ‖ nonce[16]
 * mac     = HMAC-SHA256(K_mac_up | K_mac_down, "xcvm-mac-v1" ‖ lp(ctx) ‖ SHA-256(body))
 * ```
 *
 * - `METHOD` is upper-case, `path` starts with `/cluster/v1/`, content type and
 *   encoding are lower-case and trimmed (parameters kept, e.g. `; charset=`).
 * - `query` is every `key=value` pair percent-decoded, re-encoded per RFC 3986,
 *   sorted bytewise by key then value, and joined with `&`. Duplicates stay.
 * - `node` is the `X-XCVM-Node` value: a node uuid, or `sid:<n>` before a
 *   node has one (enrolment by code).
 * - The response context hashes the request context in, so a reply cannot be
 *   replayed onto another request, and a request's nonce and timestamp are
 *   covered twice.
 * - `body` is the bytes on the wire: the BOX, or the plain body of the two
 *   unboxed ops (`health`, `challenge`).
 */
final class Canonical {
	public const PATH_PREFIX = '/cluster/v1/';
	/** Accepted |ts − now| in milliseconds. */
	public const WINDOW_MS = 90000;

	public const H_PROTO = 'X-XCVM-Proto';
	public const H_AGENT = 'X-XCVM-Agent';
	public const H_NODE = 'X-XCVM-Node';
	public const H_EPOCH = 'X-XCVM-Epoch';
	public const H_TS = 'X-XCVM-Ts';
	public const H_NONCE = 'X-XCVM-Nonce';
	public const H_SIG = 'X-XCVM-Sig';
	public const H_NODE_SIG = 'X-XCVM-Node-Sig';
	public const H_PANEL_SIG = 'X-XCVM-Panel-Sig';

	/**
	 * @param array{proto:int, agent:string, method:string, path:string, query:string, content_type:string,
	 *              content_encoding:string, node:string, epoch:int, ts_ms:int, nonce:string} $r
	 */
	public static function request(array $r): string {
		$rPath = (string) $r['path'];
		if (!str_starts_with($rPath, self::PATH_PREFIX) || str_contains($rPath, '?')) {
			throw new \InvalidArgumentException('cluster path');
		}
		if (strlen($r['nonce']) !== 16) {
			throw new \InvalidArgumentException('nonce must be 16 bytes');
		}
		return 'xcvm-req-v1'
			. Enc::u32((int) $r['proto'])
			. Enc::lp((string) $r['agent'])
			. Enc::lp(strtoupper((string) $r['method']))
			. Enc::lp($rPath)
			. Enc::lp(self::query((string) $r['query']))
			. Enc::lp(self::lowerTrim((string) $r['content_type']))
			. Enc::lp(self::lowerTrim((string) $r['content_encoding']))
			. Enc::lp((string) $r['node'])
			. Enc::u64((int) $r['epoch'])
			. Enc::u64((int) $r['ts_ms'])
			. $r['nonce'];
	}

	public static function response(string $rRequestContext, int $rStatus, string $rContentType, int $rTsMs, string $rNonce): string {
		if (strlen($rNonce) !== 16) {
			throw new \InvalidArgumentException('nonce must be 16 bytes');
		}
		return 'xcvm-res-v1'
			. hash('sha256', $rRequestContext, true)
			. Enc::u32($rStatus)
			. Enc::lp(self::lowerTrim($rContentType))
			. Enc::u64($rTsMs)
			. $rNonce;
	}

	public static function mac(string $rKey, string $rContext, string $rBody): string {
		if (strlen($rKey) !== 32) {
			throw new \InvalidArgumentException('MAC key must be 32 bytes');
		}
		return hash_hmac('sha256', 'xcvm-mac-v1' . Enc::lp($rContext) . hash('sha256', $rBody, true), $rKey, true);
	}

	public static function verifyMac(string $rKey, string $rContext, string $rBody, string $rMac): bool {
		return strlen($rMac) === 32 && hash_equals(self::mac($rKey, $rContext, $rBody), $rMac);
	}

	public static function query(string $rRaw): string {
		if ($rRaw === '') {
			return '';
		}
		$rPairs = [];
		foreach (explode('&', $rRaw) as $rPart) {
			if ($rPart === '') {
				continue;
			}
			[$rKey, $rValue] = array_pad(explode('=', $rPart, 2), 2, '');
			$rPairs[] = [rawurlencode(rawurldecode(str_replace('+', ' ', $rKey))), rawurlencode(rawurldecode(str_replace('+', ' ', $rValue)))];
		}
		usort($rPairs, static fn($rA, $rB) => strcmp($rA[0], $rB[0]) ?: strcmp($rA[1], $rB[1]));
		return implode('&', array_map(static fn($rP) => $rP[0] . '=' . $rP[1], $rPairs));
	}

	public static function withinWindow(int $rTsMs, int $rNowMs): bool {
		return abs($rTsMs - $rNowMs) <= self::WINDOW_MS;
	}

	/** A node id as X-XCVM-Node may carry it. */
	public static function validNode(string $rNode): bool {
		return (bool) preg_match('/^([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|sid:[1-9][0-9]{0,9})$/', $rNode);
	}

	/**
	 * Parse and syntax-check the authentication headers of a request. Nothing
	 * here is trusted until the MAC or signature over the context verifies.
	 *
	 * @param array<string, string> $rHeaders Header name => value (any case).
	 * @return array{proto:int, agent:string, node:string, epoch:int, ts_ms:int, nonce:string, sig:?string}|null
	 */
	public static function parseHeaders(array $rHeaders): ?array {
		$rH = array_change_key_case($rHeaders, CASE_LOWER);
		$rGet = static fn(string $rName) => isset($rH[strtolower($rName)]) ? trim((string) $rH[strtolower($rName)]) : null;
		$rProto = $rGet(self::H_PROTO);
		$rNode = $rGet(self::H_NODE);
		$rEpoch = $rGet(self::H_EPOCH);
		$rTs = $rGet(self::H_TS);
		$rNonce = $rGet(self::H_NONCE);
		$rSig = $rGet(self::H_SIG);
		if ($rProto === null || !preg_match('/^[1-9][0-9]{0,3}$/', $rProto)
			|| $rNode === null || !self::validNode($rNode)
			|| $rEpoch === null || !preg_match('/^(0|[1-9][0-9]{0,17})$/', $rEpoch)
			|| $rTs === null || !preg_match('/^[1-9][0-9]{12,15}$/', $rTs)
			|| $rNonce === null || !preg_match('/^[0-9a-f]{32}$/', $rNonce)
			|| ($rSig !== null && !preg_match('/^[0-9a-f]{64}$/', $rSig))) {
			return null;
		}
		$rAgent = (string) ($rGet(self::H_AGENT) ?? '');
		if (strlen($rAgent) > 64 || preg_match('/[^\x21-\x7e]/', $rAgent)) {
			return null;
		}
		return [
			'proto' => (int) $rProto,
			'agent' => $rAgent,
			'node' => $rNode,
			'epoch' => (int) $rEpoch,
			'ts_ms' => (int) $rTs,
			'nonce' => (string) hex2bin($rNonce),
			'sig' => $rSig === null ? null : (string) hex2bin($rSig),
		];
	}

	private static function lowerTrim(string $rValue): string {
		return strtolower(trim($rValue));
	}
}
