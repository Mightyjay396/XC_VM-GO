<?php

namespace XcVm\Tests\Support;

use XcVm\Core\Cluster\Crypto\Enc;
use XcVm\Core\Cluster\Crypto\PanelSig;

/**
 * Reference crypto for the tests: the parts of ADR-002 only `xcvm_core` (MAIN)
 * or the Go agent compute in production: licence binding, CK_B, the token
 * MAC, the session keys and panel signing with a known seed. It lives under
 * tests/ so it never ships; CI rejects any archive that carries it.
 */
final class ClusterReference {
	/** @return array<string, mixed> */
	public static function vectors(): array {
		return json_decode((string) file_get_contents(__DIR__ . '/cluster_vectors.json'), true);
	}

	public static function binding(string $rJti, string $rKeySha256): string {
		return substr(hash('sha256', 'xcvm-lic-v1' . 'wl:' . Enc::lp($rJti) . $rKeySha256, true), 0, 16);
	}

	public static function ck(string $rPrk, string $rB): string {
		return hash_hkdf('sha256', $rPrk, 32, 'xcvm/cluster/v1', $rB);
	}

	public static function tokenMsg(string $rUuid, int $rSid, int $rGen, string $rNodeSignPub, int $rEpoch, int $rNbf, int $rExp, string $rZ): string {
		return 'xcvm-node-token' . "\x01" . Enc::lp($rUuid) . Enc::u64($rSid) . Enc::u64($rGen)
			. hash('sha256', $rNodeSignPub, true) . Enc::u64($rEpoch) . Enc::u64($rNbf) . Enc::u64($rExp)
			. hash('sha256', $rZ, true);
	}

	/** @return array{mac_up: string, mac_down: string, enc_up: string, enc_down: string} */
	public static function sessionKeys(string $rT): array {
		return [
			'mac_up' => hash_hmac('sha256', 'xcvm/mac/up', $rT, true),
			'mac_down' => hash_hmac('sha256', 'xcvm/mac/down', $rT, true),
			'enc_up' => hash_hmac('sha256', 'xcvm/enc/up', $rT, true),
			'enc_down' => hash_hmac('sha256', 'xcvm/enc/down', $rT, true),
		];
	}

	public static function panelSign(string $rSeed, string $rTag, string $rPayload): string {
		$rPair = sodium_crypto_sign_seed_keypair($rSeed);
		return sodium_crypto_sign_detached(PanelSig::input($rTag, $rPayload), sodium_crypto_sign_secretkey($rPair));
	}

	public static function panelPub(string $rSeed): string {
		return sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($rSeed));
	}
}
