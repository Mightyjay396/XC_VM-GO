<?php

namespace XcVm\Core\Cluster;

/**
 * The credential-free node config (plan, section 10, step 3; xcvm_core's
 * ADR-002, "Credential-free nodes"): what a load balancer installed in API
 * mode (`lb_new_node_mode = api`, mode 2) gets as its config.enc — MAIN's DB
 * host, port and name, and none of its DB user, DB password or Redis password.
 *
 * xcvm_core packs it with `config_pack($install_id, $params + ['db_credentials'
 * => false])`. An older extension does not know the key, and must never be
 * asked: it would pack MAIN's credentials as before. The release that knows it
 * is the one that also has `install_config()` and `strip_db_credentials()`
 * (found by method_exists, no API version bump), so supported() asks for
 * that, and a panel without it refuses API mode rather than installing a node
 * with MAIN's credentials in it.
 */
final class CredentialFreeConfig {
	/** The method whose presence says config_pack understands `db_credentials`. */
	public const MARKER = 'install_config';

	/** @var (callable(): bool)|null */
	private static $rSupported = null;

	/** @var (callable(string, array<string, mixed>): (string|false))|null */
	private static $rPack = null;

	/**
	 * Tests: another feature check and packer; null restores the extension.
	 *
	 * @param (callable(): bool)|null $rSupported
	 * @param (callable(string, array<string, mixed>): (string|false))|null $rPack
	 */
	public static function useSeams(?callable $rSupported, ?callable $rPack = null): void {
		self::$rSupported = $rSupported;
		self::$rPack = $rPack;
	}

	/** Does this panel's xcvm_core pack a credential-free config? */
	public static function supported(): bool {
		if (self::$rSupported !== null) {
			return (self::$rSupported)();
		}
		return class_exists('XC_VM', false) && method_exists('XC_VM', self::MARKER) && method_exists('XC_VM', 'config_pack');
	}

	/**
	 * The XCVT blob for $rInstallID, without credentials; false when the
	 * extension refused, or cannot pack one at all.
	 *
	 * @param array<string, mixed> $rParams config_pack's server params
	 */
	public static function pack(string $rInstallID, array $rParams): string|false {
		if (!self::supported()) {
			return false;
		}
		$rParams = ['db_credentials' => false] + array_diff_key($rParams, ['include_redis' => true]);
		if (self::$rPack !== null) {
			return (self::$rPack)($rInstallID, $rParams);
		}
		$rBlob = \XC_VM::config_pack($rInstallID, $rParams);
		return is_string($rBlob) && $rBlob !== '' ? $rBlob : false;
	}
}
