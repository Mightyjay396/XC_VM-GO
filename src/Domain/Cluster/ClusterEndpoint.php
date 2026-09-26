<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Config\SettingsRepository;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * MAIN endpoint changes (plan, "Mixed-version fleets"): when MAIN's HTTP
 * broadcast port changes and the cluster API has no port of its own
 * (`cluster_api_port` = 0), the nodes must not lose MAIN.
 *
 * - The change is announced: `cluster_policy_ver` goes up, so every agent
 *   refetches the policy within one heartbeat (the reply carries the
 *   version) and moves to the new URLs.
 * - The old port stays up for the cluster API alone for GRACE (7 days): MAIN's
 *   nginx gets a server block on it that serves `/cluster/v1/` and nothing
 *   else (`bin/nginx/conf/cluster.d/old_port.conf`, rendered by
 *   ClusterNginxConfig), and the policy lists it after the new URLs, so a
 *   node that was offline during the change still finds MAIN.
 * - cron:cluster drops expired ports, bumps the policy again and renders the
 *   nginx config again so nginx releases them.
 */
final class ClusterEndpoint {
	use DatabaseAware;

	public const GRACE = 7 * 86400;

	/**
	 * Old ports still served for the cluster API: port => expiry (unix time).
	 *
	 * @param array<string, mixed> $rSettings
	 * @return array<int, int>
	 */
	public static function legacyPorts(array $rSettings, ?int $rNow = null): array {
		$rNow ??= ClusterClock::now();
		$rOut = [];
		$rDoc = json_decode((string) ($rSettings['cluster_legacy_ports'] ?? ''), true);
		foreach (is_array($rDoc) ? $rDoc : [] as $rPort => $rUntil) {
			if ((int) $rPort >= 1 && (int) $rPort <= 65535 && (int) $rUntil > $rNow) {
				$rOut[(int) $rPort] = (int) $rUntil;
			}
		}
		ksort($rOut);
		return $rOut;
	}

	/**
	 * MAIN's HTTP broadcast port went from $rOld to $rNew. Returns whether the
	 * change was recorded (it is not when the API has its own port).
	 *
	 * @param array<string, mixed> $rSettings
	 */
	public static function recordChange(int $rOld, int $rNew, array $rSettings): bool {
		if ($rOld < 1 || $rOld === $rNew || intval($rSettings['cluster_api_port'] ?? 0) > 0) {
			return false;
		}
		$rPorts = self::keep(self::legacyPorts($rSettings), $rOld, $rNew);
		self::save($rPorts);
		ClusterAudit::log('cluster.endpoint_change', null, ['from' => $rOld, 'to' => $rNew, 'old_until' => $rPorts[$rOld]], 'admin');
		return true;
	}

	/**
	 * Drop expired ports. Returns true when some were dropped: the caller then
	 * renders the nginx config again (ClusterNginxConfig) so nginx releases them.
	 *
	 * @param array<string, mixed> $rSettings
	 */
	public static function prune(array $rSettings): bool {
		$rDoc = json_decode((string) ($rSettings['cluster_legacy_ports'] ?? ''), true);
		$rLive = self::legacyPorts($rSettings);
		if (!is_array($rDoc) || count($rDoc) === count($rLive)) {
			return false;
		}
		self::save($rLive);
		ClusterAudit::log('cluster.endpoint_expired', null, ['kept' => array_keys($rLive)], 'cron');
		return true;
	}

	/**
	 * $rPorts with $rFrom kept for GRACE from now, and $rTo (in use again) no
	 * longer kept.
	 *
	 * @param array<int, int> $rPorts
	 * @return array<int, int>
	 */
	private static function keep(array $rPorts, int $rFrom, int $rTo): array {
		unset($rPorts[$rTo]);
		$rPorts[$rFrom] = ClusterClock::now() + self::GRACE;
		ksort($rPorts);
		return $rPorts;
	}

	/** @param array<int, int> $rPorts */
	private static function save(array $rPorts): void {
		ksort($rPorts);
		self::db()->query('UPDATE `settings` SET `cluster_legacy_ports` = ?, `cluster_policy_ver` = `cluster_policy_ver` + 1;', $rPorts === [] ? '' : (string) json_encode($rPorts));
		try {
			SettingsManager::set(SettingsRepository::getAll(true));
		} catch (\Throwable) {
			// The settings cache catches up on its next refresh.
		}
	}
}
