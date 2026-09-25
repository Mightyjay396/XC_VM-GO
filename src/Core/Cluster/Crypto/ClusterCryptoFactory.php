<?php

namespace XcVm\Core\Cluster\Crypto;

/**
 * Fail-closed access to the cluster crypto. create() returns a ClusterCrypto
 * only when:
 *
 * - `xcvm_core` is loaded and has the cluster API (`XC_VM::cluster_session`);
 * - its `cluster_info()['api']` is within [API_MIN, API_MAX];
 * - sodium and OpenSSL (AES-256-GCM) are available for BOX and SEAL.
 *
 * Otherwise it throws ClusterUnavailableException. The node then stays
 * legacy and `db_grant` remains the gate. There is no PHP fallback: the
 * reference crypto used by the tests lives under tests/ and never ships.
 */
final class ClusterCryptoFactory {
	/** The extension cluster API versions this panel speaks (N/N−1 once 2 exists). */
	public const API_MIN = 1;
	public const API_MAX = 1;

	/** @var (callable(): ?array)|null Test hook: replaces the extension probe; null answer = no extension. */
	private static $rProbe = null;

	public static function create(): ClusterCrypto {
		$rStatus = self::status();
		if (!$rStatus['available']) {
			throw new ClusterUnavailableException('cluster crypto unavailable: ' . $rStatus['reason']);
		}
		return new ClusterCrypto();
	}

	public static function available(): bool {
		return self::status()['available'];
	}

	/**
	 * Why the cluster crypto is or is not usable, for the admin UI and
	 * `console.php xcvm_core`.
	 *
	 * @return array{available: bool, reason: string, api: ?int, ext_version: ?string, range: string}
	 */
	public static function status(): array {
		$rRange = self::API_MIN . '–' . self::API_MAX;
		$rOut = ['available' => false, 'reason' => '', 'api' => null, 'ext_version' => null, 'range' => $rRange];
		if (!function_exists('sodium_crypto_scalarmult') || !in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) {
			$rOut['reason'] = 'NO_SODIUM_OR_GCM';
			return $rOut;
		}
		$rInfo = self::probe();
		if ($rInfo === null) {
			$rOut['reason'] = 'NO_EXTENSION';
			return $rOut;
		}
		$rOut['api'] = isset($rInfo['api']) ? (int) $rInfo['api'] : null;
		$rOut['ext_version'] = isset($rInfo['ext_version']) ? (string) $rInfo['ext_version'] : null;
		if ($rOut['api'] === null || $rOut['api'] < self::API_MIN || $rOut['api'] > self::API_MAX) {
			$rOut['reason'] = 'API_OUT_OF_RANGE';
			return $rOut;
		}
		$rOut['available'] = true;
		$rOut['reason'] = 'OK';
		return $rOut;
	}

	/** Replace the extension probe (tests). Null restores the real one. */
	public static function useProbe(?callable $rProbe): void {
		self::$rProbe = $rProbe;
	}

	/** @return array<string, mixed>|null */
	private static function probe(): ?array {
		if (self::$rProbe !== null) {
			return (self::$rProbe)();
		}
		if (!class_exists('XC_VM', false) || !method_exists('XC_VM', 'cluster_session') || !method_exists('XC_VM', 'cluster_info')) {
			return null;
		}
		$rInfo = \XC_VM::cluster_info();
		return is_array($rInfo) ? $rInfo : null;
	}
}
