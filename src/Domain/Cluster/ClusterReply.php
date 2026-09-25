<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\Crypto\Box;
use XcVm\Core\Cluster\Crypto\Canonical;
use XcVm\Core\Cluster\Crypto\SessionKeys;

/**
 * A session reply: the JSON payload in an XCVM-BOX-v1 under K_enc_down, MAC'd
 * under K_mac_down. The context binds it to the request it answers
 * (Canonical::response hashes the request context in).
 */
final class ClusterReply {
	public const CONTENT_TYPE = 'application/octet-stream';

	/**
	 * @param array<string, mixed> $rPayload
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	public static function boxed(SessionKeys $rKeys, string $rRequestContext, array $rPayload, int $rStatus = 200): array {
		$rTs = ClusterClock::nowMs();
		$rNonce = random_bytes(16);
		$rContext = Canonical::response($rRequestContext, $rStatus, self::CONTENT_TYPE, $rTs, $rNonce);
		$rBody = Box::box($rKeys->rEncDown, $rContext, (string) json_encode($rPayload, JSON_UNESCAPED_SLASHES));
		return [
			'status' => $rStatus,
			'headers' => [
				'Content-Type' => self::CONTENT_TYPE,
				Canonical::H_TS => (string) $rTs,
				Canonical::H_NONCE => bin2hex($rNonce),
				Canonical::H_SIG => bin2hex(Canonical::mac($rKeys->rMacDown, $rContext, $rBody)),
				'Cache-Control' => 'no-store',
			],
			'body' => $rBody,
		];
	}

	/**
	 * A pre-token reply under an enrolment code: the JSON body in the clear
	 * (it holds nothing secret; a token inside is sealed to the node's key),
	 * MAC'd under K_res and bound to the request like a session reply.
	 *
	 * @param array<string, string> $rHeaders Extra headers (a panel signature).
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	public static function maced(string $rKey, string $rRequestContext, string $rBody, array $rHeaders = [], int $rStatus = 200): array {
		$rTs = ClusterClock::nowMs();
		$rNonce = random_bytes(16);
		$rContext = Canonical::response($rRequestContext, $rStatus, 'application/json', $rTs, $rNonce);
		return [
			'status' => $rStatus,
			'headers' => [
				'Content-Type' => 'application/json',
				Canonical::H_TS => (string) $rTs,
				Canonical::H_NONCE => bin2hex($rNonce),
				Canonical::H_SIG => bin2hex(Canonical::mac($rKey, $rContext, $rBody)),
				'Cache-Control' => 'no-store',
			] + $rHeaders,
			'body' => $rBody,
		];
	}
}
