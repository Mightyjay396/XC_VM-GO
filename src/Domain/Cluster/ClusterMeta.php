<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\Crypto\ClusterCrypto;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * Cluster-wide state in `cluster_meta`, and MAIN's cluster bring-up.
 *
 * init() creates the extension's cluster root (idempotent) and records the
 * panel's public keys and `ready_at`. Liveness counts silence from
 * max(last_seen_at, ready_at) (NodeHealth), so the time MAIN's API was down
 * never counts against a node.
 */
final class ClusterMeta {
	use DatabaseAware;

	public static function get(string $rName): ?string {
		self::db()->query('SELECT `value` FROM `cluster_meta` WHERE `name` = ?;', $rName);
		return self::db()->num_rows() > 0 ? (string) self::db()->get_row()['value'] : null;
	}

	public static function set(string $rName, string $rValue): void {
		self::db()->query('DELETE FROM `cluster_meta` WHERE `name` = ?;', $rName);
		self::db()->query('INSERT INTO `cluster_meta` (`name`, `value`, `updated_at`) VALUES (?, ?, ?);', $rName, $rValue, ClusterClock::now());
	}

	/** @return array{created: bool, panel_fp: string} */
	public static function init(ClusterCrypto $rCrypto): array {
		$rRoot = $rCrypto->init();
		$rFp = bin2hex((string) $rRoot['panel_fp']);
		$rKnown = self::get('panel_fp');
		self::set('panel_sign_pub', base64_encode((string) $rRoot['panel_sign_pub']));
		self::set('panel_box_pub', base64_encode((string) $rRoot['panel_box_pub']));
		self::set('panel_fp', $rFp);
		self::markReady();
		if ($rKnown !== null && $rKnown !== $rFp) {
			// Nodes pinned the old keys: every one of them must re-enrol.
			ClusterAudit::log('cluster.root_changed', null, ['was' => $rKnown, 'now' => $rFp], 'system');
		} elseif (!empty($rRoot['created'])) {
			ClusterAudit::log('cluster.init', null, ['panel_fp' => $rFp], 'system');
		}
		return ['created' => (bool) ($rRoot['created'] ?? false), 'panel_fp' => $rFp];
	}

	/** MAIN's API is (again) serving: restart the silence clock for every node. */
	public static function markReady(): void {
		self::set('ready_at', (string) ClusterClock::nowMs());
	}

	public static function readyAtMs(): int {
		return (int) (self::get('ready_at') ?? 0);
	}
}
