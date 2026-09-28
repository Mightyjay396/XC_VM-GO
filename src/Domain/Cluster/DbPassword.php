<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Config\SettingsManager;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * Rotating the panel's database password (plan, section 10; Phase 9), MAIN's
 * side: `cluster:rotate-db-password`.
 *
 * `\XC_VM::db_set_password($new)` does the database half in `xcvm_core`
 * (the extension's ADR-002): MAIN's own accounts first (all or none), then
 * MAIN's `config.enc`, then every load balancer's grant; its result is true
 * once MAIN runs on the new password, and `cluster_last_error()` is then
 * `PARTIAL` when a node's grant kept the old one. PHP never stores the
 * password and never sends it anywhere.
 *
 * What each load balancer needs afterwards (plan()):
 *
 * - `config`: an active node in mode 1 that takes root commands and whose
 *   install_id MAIN knows (CorePins) is sent a config with the new password,
 *   packed by `\XC_VM::config_pack` for that install only
 *   (DbCredentials::installConfig, `node.root install_config`).
 * - `mode2`: a node in mode 2 does not use MAIN's database; the stale password
 *   in its config does nothing (strip it: `cluster:strip-credentials`).
 * - `revoked`: MAIN revoked the node's grant; there is nothing to change.
 * - `manual`: any other load balancer (legacy, mode 0, not taking root
 *   commands, install_id unknown) keeps the old password in its config and
 *   loses MAIN's database until an operator runs `cluster:set-db-password`
 *   on it (`\XC_VM::config_set_db`), or re-installs it.
 *
 * The password never rides a command: a `node.root` payload is stored in
 * MAIN's `cluster_commands` and the node's root inbox in the clear, so a
 * rotation meant to shut out a leaked password would leak the new one there.
 *
 * @package XC_VM_Domain_Cluster
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class DbPassword {
	use DatabaseAware;

	/** What `db_set_password` and `config_set_db` accept (they also write it into a `[client]` option file). */
	public const PATTERN = '/^[A-Za-z0-9\-_.~+=@%^*!,:\/?]{16,128}\z/';

	/** Length of a generated password. */
	public const GENERATED_LENGTH = 32;

	/** @var \Closure(string): bool|null Tests: `db_set_password`. */
	private static ?\Closure $rSet = null;

	/** @var \Closure(): (string|null)|null Tests: `cluster_last_error`. */
	private static ?\Closure $rLastError = null;

	/** @var \Closure(int, string): (string|null)|null Tests: sending a node its config. */
	private static ?\Closure $rInstall = null;

	/**
	 * Tests: reach the extension through these, and send configs through
	 * $rInstall (server id, actor → null or why not); null restores `\XC_VM`
	 * and DbCredentials::installConfig.
	 */
	public static function useExtension(?\Closure $rSet, ?\Closure $rLastError = null, ?\Closure $rInstall = null): void {
		self::$rSet = $rSet;
		self::$rLastError = $rLastError;
		self::$rInstall = $rInstall;
	}

	public static function valid(string $rPassword): bool {
		return preg_match(self::PATTERN, $rPassword) === 1;
	}

	/** A random password of letters and digits, which every client quotes safely. */
	public static function generate(int $rLength = self::GENERATED_LENGTH): string {
		$rAlphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
		$rOut = '';
		for ($i = 0; $i < max(16, min(128, $rLength)); $i++) {
			$rOut .= $rAlphabet[random_int(0, strlen($rAlphabet) - 1)];
		}
		return $rOut;
	}

	/** Does this MAIN's xcvm_core rotate the password? */
	public static function available(): bool {
		return self::$rSet !== null || (class_exists('XC_VM') && method_exists('XC_VM', 'db_set_password'));
	}

	/**
	 * What a rotation does to each load balancer, before it runs.
	 *
	 * @return list<array{server_id: int, server_name: string, how: string, why: string|null}>
	 *         `how`: config, mode2, revoked or manual; `why`: what makes a node manual
	 */
	public static function plan(): array {
		self::db()->query('SELECT `id`, `server_name` FROM `servers` WHERE `server_type` = 0 AND `is_main` = 0 ORDER BY `id` ASC;');
		$rServers = self::db()->get_rows() ?: [];
		$rApi = !empty(SettingsManager::get('cluster_api_enabled'));
		$rOut = [];
		foreach ($rServers as $rServer) {
			$rServerID = (int) $rServer['id'];
			$rNode = $rApi ? NodeRegistry::byServer($rServerID) : null;
			[$rHow, $rWhy] = self::how($rServerID, $rNode, $rApi);
			$rOut[] = ['server_id' => $rServerID, 'server_name' => (string) $rServer['server_name'], 'how' => $rHow, 'why' => $rWhy];
		}
		return $rOut;
	}

	/**
	 * @param array<string, mixed>|null $rNode cluster_nodes row
	 * @return array{0: string, 1: string|null}
	 */
	private static function how(int $rServerID, ?array $rNode, bool $rApi): array {
		if ($rNode === null) {
			return ['manual', $rApi ? 'not enrolled in the cluster API' : 'the cluster API is off'];
		}
		if (DbCredentials::revokedAt($rServerID) !== null) {
			return ['revoked', null];
		}
		if ((int) $rNode['mode'] === 2) {
			return ['mode2', null];
		}
		if ($rNode['state'] !== 'active') {
			return ['manual', 'the node is ' . $rNode['state']];
		}
		if (!CommandBus::acceptsRoot($rNode)) {
			return ['manual', 'the node takes no root commands (mode 1, COMMANDS on, root pin)'];
		}
		if (CorePins::installId($rServerID) === null) {
			return ['manual', 'MAIN does not know its install_id (Pin core records it)'];
		}
		return ['config', null];
	}

	/**
	 * Rotate. `ok` false (`why`: the extension's refusal code, `no_extension`
	 * or `password`) changed nothing; `ok` true means MAIN runs on the new
	 * password, `partial` that a node's grant kept the old one, and `nodes`
	 * says what each load balancer was sent (`result` null: queued, else the
	 * message key of why not; `how` as plan()).
	 *
	 * @return array{ok: bool, why: string|null, partial: bool, nodes: list<array{server_id: int, server_name: string, how: string, why: string|null, result: string|null}>}
	 */
	public static function rotate(string $rNew, string $rActor = 'cli'): array {
		$rFail = static fn(string $rWhy): array => ['ok' => false, 'why' => $rWhy, 'partial' => false, 'nodes' => []];
		if (!self::valid($rNew)) {
			return $rFail('password');
		}
		if (!self::available()) {
			return $rFail('no_extension');
		}
		$rPlan = self::plan();
		$rSet = self::$rSet ?? static fn(string $rPass): bool => (bool) \XC_VM::db_set_password($rPass);
		$rLast = self::$rLastError ?? static fn(): ?string => method_exists('XC_VM', 'cluster_last_error') ? \XC_VM::cluster_last_error() : null;
		if (!$rSet($rNew)) {
			$rWhy = $rLast();
			ClusterAudit::log('db.password_rotate_failed', null, ['why' => is_string($rWhy) && $rWhy !== '' ? $rWhy : 'unknown'], $rActor);
			return $rFail(is_string($rWhy) && $rWhy !== '' ? $rWhy : 'unknown');
		}
		$rPartial = $rLast() === 'PARTIAL';
		$rInstall = self::$rInstall ?? static fn(int $rServerID, string $rBy): ?string => DbCredentials::installConfig($rServerID, true, $rBy);
		$rNodes = [];
		foreach ($rPlan as $rRow) {
			$rRow['result'] = null;
			if ($rRow['how'] === 'config') {
				try {
					$rRow['result'] = $rInstall($rRow['server_id'], $rActor);
				} catch (\Throwable) {
					$rRow['result'] = 'cluster_config_not_queued';
				}
			}
			$rNodes[] = $rRow;
		}
		$rCount = static fn(string $rHow): int => count(array_filter($rNodes, static fn(array $rN): bool => $rN['how'] === $rHow));
		ClusterAudit::log('db.password_rotated', null, [
			'partial' => $rPartial,
			'config' => $rCount('config'),
			'manual' => $rCount('manual'),
			'not_queued' => count(array_filter($rNodes, static fn(array $rN): bool => $rN['how'] === 'config' && $rN['result'] !== null)),
		], $rActor);
		return ['ok' => true, 'why' => null, 'partial' => $rPartial, 'nodes' => $rNodes];
	}
}
