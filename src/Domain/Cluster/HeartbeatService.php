<?php

namespace XcVm\Domain\Cluster;

use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * Heartbeats, in shadow (Phase 2): a verified heartbeat refreshes the node's
 * last_seen_at and clock offset, and its telemetry is kept for comparison
 * with the legacy watchdog data. Nothing reads it for routing yet; Phase 3
 * makes it authoritative (and moves it to the cluster bus).
 */
final class HeartbeatService {
	use DatabaseAware;

	/** Largest telemetry document kept per node. */
	public const MAX_TELEMETRY = 65536;

	/**
	 * @param array<string, mixed> $rNode
	 * @param array<string, mixed> $rPayload
	 */
	public static function record(array $rNode, array $rPayload, int $rNodeTsMs): void {
		$rNow = ClusterClock::nowMs();
		NodeRegistry::update((int) $rNode['server_id'], [
			'last_seen_at' => $rNow,
			'clock_offset_ms' => max(-2147483648, min(2147483647, $rNodeTsMs - $rNow)),
		]);
		$rTelemetry = $rPayload['telemetry'] ?? null;
		if (is_array($rTelemetry) && defined('TMP_PATH')) {
			$rJson = (string) json_encode(['at' => $rNow, 'telemetry' => $rTelemetry], JSON_UNESCAPED_SLASHES);
			if (strlen($rJson) <= self::MAX_TELEMETRY) {
				$rDir = TMP_PATH . 'cluster/';
				if (!is_dir($rDir)) {
					@mkdir($rDir, 0750, true);
				}
				@file_put_contents($rDir . 'tel_' . intval($rNode['server_id']) . '.json', $rJson, LOCK_EX);
			}
		}
		// The node's first authenticated heartbeat is what marks the server up
		// (plan, section 6); legacy nodes keep setting it through the watchdog.
		self::db()->query('UPDATE `servers` SET `status` = 1 WHERE `id` = ? AND `status` <> 1;', (int) $rNode['server_id']);
	}
}
