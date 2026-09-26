<?php

namespace XcVm\Core\Config;

use XcVm\Core\Cache\FileCache;
use XcVm\Core\Cluster\ReplicaApply;
use XcVm\Core\Cluster\ReplicaSections;

/**
 * SettingsRepository — settings repository
 *
 * @package XC_VM_Core_Config
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class SettingsRepository {
	/**
	 * Load all panel settings, decoding JSON fields and caching the result.
	 *
	 * On a node whose replica owns the settings cache (CONFIG on, and an apply
	 * built it from the `settings` and `secrets` sections: ReplicaApply::owns),
	 * every caller gets that cache however old it is, even when forced, never
	 * MAIN's database; should it be gone, it is rebuilt from the replica on
	 * disk. The database's row is never written over a cache the replica owns.
	 *
	 * @param bool $rForce Bypass the file cache and re-read from the database.
	 * @return array Settings map (with normalized array fields).
	 */
	public static function getAll(bool $rForce = false) {
		global $db;
		$rReplica = self::replicaOwns();
		if (!$rForce || $rReplica) {
			$rCache = $rReplica ? FileCache::getCache('settings') : FileCache::getCache('settings', 20);
			if (empty($rCache) && $rReplica) {
				ReplicaApply::settings(true);
				$rCache = self::replicaOwns() ? FileCache::getCache('settings') : false;
			}
			if (!empty($rCache)) {
				return $rCache;
			}
		}

		$db->query('SELECT * FROM `settings`');
		$rOutput = self::decode($db->get_row() ?: []);

		// Asked again: an apply may have built the replica's cache meanwhile.
		if (!self::replicaOwns()) {
			FileCache::setCache('settings', $rOutput);
		}

		return $rOutput;
	}

	/**
	 * The settings this process loaded (SettingsManager), or while it has not
	 * loaded them yet, the settings cache: the servers read to rule out MAIN
	 * (NodeFlows) can come first, and must not build their URLs without them.
	 *
	 * @return array<string, mixed>
	 */
	public static function loaded(): array {
		$rSettings = SettingsManager::getAll();
		if ($rSettings === []) {
			$rCache = FileCache::getCache('settings');
			$rSettings = is_array($rCache) ? $rCache : [];
		}
		return $rSettings;
	}

	/**
	 * Does the node replica own the settings cache? The apply's record first:
	 * without it (MAIN, a legacy node, CONFIG off) nothing asks NodeFlows,
	 * which on a node with an agent reads the servers, before the settings
	 * are loaded.
	 */
	private static function replicaOwns(): bool {
		return ReplicaApply::built(ReplicaSections::SETTINGS) && ReplicaApply::owns(ReplicaSections::SETTINGS);
	}

	/**
	 * The settings row as the panel reads it, with its array fields decoded.
	 * Shared with the node replica, whose `settings` section carries raw rows.
	 *
	 * @param array<string, mixed> $rRow the raw `settings` row
	 * @return array<string, mixed>
	 */
	public static function decode(array $rRow): array {
		$rOutput = $rRow;
		$rOutput['allow_countries'] = json_decode($rOutput['allow_countries'] ?? '', true);

		$decodedAllowedSTB = json_decode($rOutput['allowed_stb_types'] ?? '', true);
		$rOutput['allowed_stb_types'] = [];
		if (is_array($decodedAllowedSTB)) {
			// Drop blank entries so an "empty" selection (an unset multiselect is
			// commonly stored as [""]) collapses to a truly empty array. An empty
			// Allowed STB Types list means every STB type is accepted — see the
			// get_profile gate in Ministra/portal.php, which allows when this is empty.
			$rOutput['allowed_stb_types'] = array_values(array_filter(
				array_map(static fn ($rType) => strtolower(trim((string) $rType)), $decodedAllowedSTB),
				static fn ($rType) => $rType !== ''
			));
		}

		$rOutput['stalker_lock_images'] = json_decode($rOutput['stalker_lock_images'] ?? '', true);
		if (array_key_exists('bouquet_name', $rOutput)) {
			$rOutput['bouquet_name'] = str_replace(' ', '_', $rOutput['bouquet_name']);
		}
		$rOutput['api_ips'] = !empty($rOutput['api_ips']) ? explode(',', $rOutput['api_ips']) : [];

		$rDecodedPrefixes = json_decode($rOutput['shared_mount_prefixes'] ?? '', true);
		if (!is_array($rDecodedPrefixes)) {
			// Legacy CSV format from before this became a select2 tags field; self-heals to JSON on next save.
			$rDecodedPrefixes = !empty($rOutput['shared_mount_prefixes']) ? explode(',', $rOutput['shared_mount_prefixes']) : [];
		}
		$rOutput['shared_mount_prefixes'] = array_values(array_filter(array_map('trim', $rDecodedPrefixes)));

		return $rOutput;
	}
}
