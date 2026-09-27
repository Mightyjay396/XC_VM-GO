<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\SettingsAudit;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * What a node's heartbeat reports of its own audit (`audit`), kept with the
 * node in `cluster_nodes.audit` and shown on the Cluster Nodes page. Today
 * one member: `settings_misses`, the settings reads outside the replica's
 * allowlist over the node's last seven days (Core\Cluster\SettingsAudit;
 * plan, section 9, R1 `settings`).
 *
 * ```text
 * audit            {"settings_misses": {key: count}}, at most MAX_BYTES as sent
 * settings_misses  key: [a-z0-9_]{1,64} or "*" (the rest), count: an integer >= 1;
 *                  at most SettingsAudit::MAX_KEYS keys and "*"
 * ```
 *
 * A heartbeat without `audit` (today's agent), or with one that is not the
 * above, changes nothing. A new report is written only when it differs from
 * the stored one: the row is read for every request already, so an
 * unchanged report costs no query, on the cluster bus or not.
 */
final class NodeAudit {
	use DatabaseAware;

	/** Largest `audit` taken, as MAIN encodes it. */
	public const MAX_BYTES = 16384;

	/**
	 * The heartbeat's `audit` as MAIN keeps it, or null when it is not one
	 * (the stored report stays). Entries that are not a settings name and a
	 * count are dropped; past SettingsAudit::MAX_KEYS names, the least missed
	 * count under "*".
	 *
	 * @return array{settings_misses: array<string, int>}|null
	 */
	public static function normalise(mixed $rAudit): ?array {
		if (!is_array($rAudit) || !is_array($rAudit['settings_misses'] ?? null) || strlen((string) json_encode($rAudit, JSON_PARTIAL_OUTPUT_ON_ERROR)) > self::MAX_BYTES) {
			return null;
		}
		$rMisses = [];
		foreach ($rAudit['settings_misses'] as $rKey => $rCount) {
			$rKey = (string) $rKey;
			if (is_int($rCount) && $rCount > 0 && ($rKey === SettingsAudit::OTHER || SettingsAudit::name($rKey) === $rKey)) {
				$rMisses[$rKey] = $rCount;
			}
		}
		return ['settings_misses' => SettingsAudit::top($rMisses)];
	}

	/** The stored form: `{"settings_misses": {…}}`, an object even when empty. */
	public static function encode(array $rAudit): string {
		return (string) json_encode(['settings_misses' => (object) $rAudit['settings_misses']]);
	}

	/**
	 * Keep a heartbeat's `audit` with its node when it is one and differs
	 * from what the row holds. Before migration 045 the row has no `audit`,
	 * and nothing is written. A failed write is tried again at the next
	 * heartbeat, which still differs.
	 *
	 * @param array<string, mixed> $rNode cluster_nodes row, as the request read it
	 */
	public static function record(array $rNode, mixed $rAudit): void {
		$rDoc = self::normalise($rAudit);
		if ($rDoc === null || !array_key_exists('audit', $rNode)) {
			return;
		}
		$rJson = self::encode($rDoc);
		if ($rJson === $rNode['audit']) {
			return;
		}
		try {
			NodeRegistry::update((int) $rNode['server_id'], ['audit' => $rJson]);
		} catch (\Throwable) {
			// The next heartbeat tries again.
		}
	}

	/**
	 * Each node's last reported settings misses, for the Cluster Nodes page
	 * (server id => {key: count}); a node that reported none is absent.
	 *
	 * @return array<int, array<string, int>>
	 */
	public static function settingsMisses(): array {
		try {
			$rRows = self::db()->query('SELECT `server_id`, `audit` FROM `cluster_nodes` WHERE `audit` IS NOT NULL;') ? self::db()->get_rows() : [];
		} catch (\Throwable) {
			$rRows = []; // before migration 045
		}
		$rOut = [];
		foreach ($rRows ?: [] as $rRow) {
			$rDoc = json_decode((string) $rRow['audit'], true);
			if (is_array($rDoc) && is_array($rDoc['settings_misses'] ?? null)) {
				$rOut[(int) $rRow['server_id']] = $rDoc['settings_misses'];
			}
		}
		return $rOut;
	}
}
