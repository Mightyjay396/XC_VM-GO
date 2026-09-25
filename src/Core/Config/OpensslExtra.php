<?php

namespace XcVm\Core\Config;

/**
 * OPENSSL_EXTRA across the cluster.
 *
 * OPENSSL_EXTRA (ConstantsInitializer::resolveOpensslExtra: config/openssl_extra,
 * else the built-in default) keys stream tokens, hmac_keys and image-cache
 * names. A MAIN and its LBs must hold the same value: a token MAIN mints for a
 * redirect is read on the LB with the LB's value. A MAIN set up by the current
 * installer holds a random value, while an LB added with server:install before
 * provisioning shipped the file runs on the default.
 *
 * Each node publishes a fingerprint of its value (never the value) in
 * servers.server_hardware, so server:diagnose can compare them.
 *
 * @package XC_VM_Core_Config
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class OpensslExtra {
	/** The server_hardware key cron:servers publishes the fingerprint under. */
	public const HARDWARE_KEY = 'openssl_extra_fp';

	/**
	 * A fingerprint of $rValue that can be compared across nodes: an HMAC keyed
	 * by the value, cut to 64 bits. It does not reveal the value.
	 */
	public static function fingerprint(string $rValue): string {
		return substr(hash_hmac('sha256', 'xc_vm openssl_extra fingerprint v1', $rValue), 0, 16);
	}

	/**
	 * The fingerprint a server row publishes, or null when it publishes none
	 * (a proxy, or a node that has not been updated).
	 */
	public static function reportedFingerprint(array $rServer): ?string {
		$rHardware = json_decode((string) ($rServer['server_hardware'] ?? ''), true);
		$rPrint = is_array($rHardware) ? ($rHardware[self::HARDWARE_KEY] ?? null) : null;

		return (is_string($rPrint) && $rPrint !== '') ? $rPrint : null;
	}
}
