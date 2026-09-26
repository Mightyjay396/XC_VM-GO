<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\LocalTelemetry;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Util\SystemInfo;
use XcVm\Core\Util\TimeUtils;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * Heartbeats. A verified heartbeat refreshes the node's last_seen_at and
 * clock offset. Its telemetry is kept in shadow for every node, and for a
 * node with the TELEMETRY flow on it is authoritative (Phase 3):
 *
 * - every 5 s it becomes the server's `watchdog_data`, `last_check_ago`,
 *   `requests_per_second` and `php_pids`, in the shape the legacy watchdog
 *   wrote (toWatchdogData(), pinned by WatchdogDataContractTest), and, without
 *   the Redis handler, its `connections` and `users`;
 * - once a minute it becomes the node's `servers_stats` row, which
 *   `cron:servers` wrote on the LB until then.
 *
 * The LB's watchdog, the stats part of its cron:servers and network.py stand
 * down for that node (Core\Cluster\NodeFlows).
 */
final class HeartbeatService {
	use DatabaseAware;

	/** Largest telemetry document kept per node: local.json alone may be 64 KiB. */
	public const MAX_TELEMETRY = 131072;

	/**
	 * Largest `telemetry.local` read (encoded): the agent's cap on local.json,
	 * enforced here too, since only the node would enforce it otherwise and
	 * gpu_info and iostat_info are kept in servers_stats.
	 */
	public const MAX_LOCAL = 65536;

	/** Seconds between authoritative writes to `servers` and to `servers_stats`. */
	public const WRITE_EVERY = 5;
	public const STATS_EVERY = 60;

	/** Entries kept in watchdog_data.cpu_average_array, as the watchdog kept. */
	public const CPU_HISTORY = 30;

	private static ?string $rDir = null;

	/** Tests: another directory for the shadow copies and stats markers; null restores TMP_PATH's. */
	public static function useDir(?string $rDir): void {
		self::$rDir = $rDir;
	}

	/**
	 * @param array<string, mixed> $rNode
	 * @param array<string, mixed> $rPayload
	 */
	public static function record(array $rNode, array $rPayload, int $rNodeTsMs): void {
		$rNow = ClusterClock::nowMs();
		$rFields = [
			'last_seen_at' => $rNow,
			'clock_offset_ms' => max(-2147483648, min(2147483647, $rNodeTsMs - $rNow)),
		];
		// Whether the node's root-owned panel-key pin is in place (root commands).
		if (array_key_exists('root_ready', $rPayload)) {
			$rFields['root_ready'] = empty($rPayload['root_ready']) ? 0 : 1;
		}
		NodeRegistry::update((int) $rNode['server_id'], $rFields);
		$rTelemetry = $rPayload['telemetry'] ?? null;
		$rDir = self::dir();
		if (is_array($rTelemetry) && $rDir !== null) {
			$rJson = (string) json_encode(['at' => $rNow, 'telemetry' => $rTelemetry], JSON_UNESCAPED_SLASHES);
			if (strlen($rJson) <= self::MAX_TELEMETRY) {
				if (!is_dir($rDir)) {
					@mkdir($rDir, 0750, true);
				}
				@file_put_contents($rDir . 'tel_' . intval($rNode['server_id']) . '.json', $rJson, LOCK_EX);
			}
		}
		if (is_array($rTelemetry) && (int) $rNode['mode'] >= 1 && ((int) $rNode['flows'] & NodeRegistry::FLOW_TELEMETRY)) {
			try {
				self::authoritative((int) $rNode['server_id'], $rTelemetry);
			} catch (\Throwable $rE) {
				// Telemetry must never cost the node its heartbeat.
				ClusterAudit::log('telemetry.error', (int) $rNode['server_id'], substr($rE->getMessage(), 0, 200));
			}
		}
		// The node's first authenticated heartbeat is what marks the server up
		// (plan, section 6); legacy nodes keep setting it through the watchdog.
		self::db()->query('UPDATE `servers` SET `status` = 1 WHERE `id` = ? AND `status` <> 1;', (int) $rNode['server_id']);
	}

	/**
	 * The legacy `SystemInfo::getStats()` document, plus the watchdog's
	 * `cpu_average_array` and `fanout`, from an agent's telemetry.
	 *
	 * @param array<string, mixed> $rTel The agent's sample (clusteragent.Sampler).
	 * @param array<string, mixed> $rPrev The server's current watchdog_data.
	 * @param string|null $rInterface servers.network_interface; null or 'auto' for all.
	 * @return array<string, mixed>
	 */
	public static function toWatchdogData(array $rTel, array $rPrev, ?string $rInterface = null): array {
		$rCores = max(0, (int) ($rTel['cpu_cores'] ?? 0));
		$rLoad = is_array($rTel['load'] ?? null) ? (float) ($rTel['load'][0] ?? 0) : 0.0;
		$rCpu = isset($rTel['cpu']) ? (float) $rTel['cpu'] : (float) ($rPrev['cpu'] ?? 0);
		$rTotal = max(0, (int) ($rTel['mem_total_kb'] ?? 0));
		$rUsed = max(0, $rTotal - (int) ($rTel['mem_avail_kb'] ?? 0));
		$rOut = [
			'cpu' => min(100, round($rCpu, 2)),
			'cpu_cores' => $rCores,
			'cpu_avg' => min(100, round(($rLoad * 100) / ($rCores ?: 1), 2)),
			'cpu_name' => (string) ($rTel['cpu_name'] ?? ''),
			'total_mem' => $rTotal,
			'total_mem_free' => $rTotal - $rUsed,
			'total_mem_used' => $rUsed,
			'total_mem_used_percent' => min(100, SystemInfo::memUsedPercent($rUsed, $rTotal)),
			'total_disk_space' => (float) ($rTel['disk_total'] ?? 0),
			'free_disk_space' => (float) ($rTel['disk_free'] ?? 0),
			'kernel' => (string) ($rTel['kernel'] ?? ''),
			'uptime' => isset($rTel['uptime_s']) ? TimeUtils::secondsToTime((int) $rTel['uptime_s']) : '',
			'total_running_streams' => (int) ($rTel['stream_producers'] ?? 0),
			'bytes_sent' => 0,
			'bytes_sent_total' => 0,
			'bytes_received' => 0,
			'bytes_received_total' => 0,
			'network_speed' => 0,
			'interfaces' => array_values(array_filter((array) ($rTel['interfaces'] ?? []), 'is_string')),
			'network_info' => [],
		];
		$rOnly = (in_array($rInterface, [null, '', 'auto'], true)) ? null : $rInterface;
		$rNet = is_array($rTel['net'] ?? null) ? $rTel['net'] : [];
		ksort($rNet);
		foreach ($rNet as $rName => $rRate) {
			$rName = (string) $rName;
			if (!is_array($rRate) || $rName === 'lo' || ($rOnly !== null && $rName !== $rOnly) || ($rOnly === null && str_starts_with($rName, 'bond'))) {
				continue;
			}
			$rOut['network_info'][$rName] = [
				'in_bytes' => (int) ($rRate['in_bytes'] ?? 0), 'in_packets' => (int) ($rRate['in_packets'] ?? 0), 'in_errors' => (int) ($rRate['in_errors'] ?? 0),
				'out_bytes' => (int) ($rRate['out_bytes'] ?? 0), 'out_packets' => (int) ($rRate['out_packets'] ?? 0), 'out_errors' => (int) ($rRate['out_errors'] ?? 0),
			];
			$rOut['bytes_sent'] += (int) ($rRate['out_bytes'] ?? 0);
			$rOut['bytes_received'] += (int) ($rRate['in_bytes'] ?? 0);
			$rOut['bytes_sent_total'] += (int) ($rRate['tx_total'] ?? 0);
			$rOut['bytes_received_total'] += (int) ($rRate['rx_total'] ?? 0);
			if ($rOut['network_speed'] === 0 && (int) ($rRate['speed'] ?? 0) > 0) {
				$rOut['network_speed'] = (int) $rRate['speed'];
			}
		}
		// The node's watchdog probes these (local.json); an absent key is an absent tool.
		$rLocal = self::local($rTel);
		foreach (LocalTelemetry::DEVICES as $rKey) {
			$rOut[$rKey] = self::deviceSection($rKey, $rLocal[$rKey] ?? null);
		}
		$rOut['cpu_load_average'] = $rLoad;
		$rHistory = is_array($rPrev['cpu_average_array'] ?? null) ? array_values($rPrev['cpu_average_array']) : [];
		$rHistory[] = $rOut['cpu'];
		$rOut['cpu_average_array'] = array_slice($rHistory, -self::CPU_HISTORY);
		$rOut['fanout'] = is_array($rLocal['fanout'] ?? null) ? $rLocal['fanout'] : ($rPrev['fanout'] ?? null);
		return $rOut;
	}

	/**
	 * The telemetry's `local` (the node's local.json), or [] when there is none
	 * or it is over MAX_LOCAL: absent, as the agent would have left it out.
	 *
	 * @param array<string, mixed> $rTel
	 * @return array<mixed>
	 */
	private static function local(array $rTel): array {
		$rLocal = $rTel['local'] ?? null;
		if (!is_array($rLocal) || strlen((string) json_encode($rLocal, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) > self::MAX_LOCAL) {
			return [];
		}
		return $rLocal;
	}

	/**
	 * One device section of the node's local.json, in SystemInfo's shape as
	 * far as the panel reads it: [] for a non-array, capture devices as a
	 * list (names, and objects for video), GPUs as objects, and iostat's CPU
	 * figures, which the dashboard rounds, as numbers.
	 *
	 * @return array<mixed>
	 */
	private static function deviceSection(string $rKey, mixed $rValue): array {
		if (!is_array($rValue)) {
			return [];
		}
		switch ($rKey) {
			case 'audio_devices':
				return array_values(array_filter($rValue, 'is_string'));
			case 'video_devices':
				return array_values(array_filter($rValue, 'is_array'));
			case 'gpu_info':
				if (array_key_exists('gpus', $rValue)) {
					$rValue['gpus'] = is_array($rValue['gpus']) ? array_values(array_filter($rValue['gpus'], 'is_array')) : [];
				}
				return $rValue;
			default:
				if (array_key_exists('avg-cpu', $rValue)) {
					$rValue['avg-cpu'] = is_array($rValue['avg-cpu']) ? array_filter($rValue['avg-cpu'], 'is_numeric') : [];
				}
				return $rValue;
		}
	}

	/**
	 * Write a TELEMETRY node's figures where the legacy watchdog and
	 * cron:servers wrote them, at most every WRITE_EVERY / STATS_EVERY seconds.
	 *
	 * @param array<string, mixed> $rTel
	 */
	private static function authoritative(int $rServerID, array $rTel): void {
		$rNow = ClusterClock::now();
		self::db()->query('SELECT `watchdog_data`, `last_check_ago`, `network_interface` FROM `servers` WHERE `id` = ?;', $rServerID);
		$rRow = self::db()->num_rows() > 0 ? self::db()->get_row() : null;
		if ($rRow === null || $rNow - (int) $rRow['last_check_ago'] < self::WRITE_EVERY) {
			return;
		}
		$rPrev = json_decode((string) ($rRow['watchdog_data'] ?? ''), true);
		$rStats = self::toWatchdogData($rTel, is_array($rPrev) ? $rPrev : [], $rRow['network_interface'] ?? null);
		$rRps = (int) (self::local($rTel)['requests_per_second'] ?? 0);
		$rPIDs = array_key_exists('php_pids', $rTel) && is_array($rTel['php_pids']) ? array_values(array_map('intval', $rTel['php_pids'])) : null;
		$rCounts = self::counts($rServerID);
		$rSql = 'UPDATE `servers` SET `watchdog_data` = ?, `last_check_ago` = ?, `requests_per_second` = ?, `php_pids` = ?';
		$rArgs = [json_encode($rStats, JSON_PARTIAL_OUTPUT_ON_ERROR), $rNow, $rRps, json_encode($rPIDs)];
		if ($rCounts !== null) {
			$rSql .= ', `connections` = ?, `users` = ?';
			$rArgs[] = $rCounts['connections'];
			$rArgs[] = $rCounts['users'];
		}
		self::db()->query($rSql . ' WHERE `id` = ?;', ...[...$rArgs, $rServerID]);

		$rDir = self::dir();
		$rMarker = $rDir === null ? null : $rDir . 'stats_' . $rServerID;
		if ($rMarker !== null && is_file($rMarker) && $rNow - (int) @file_get_contents($rMarker) < self::STATS_EVERY) {
			return;
		}
		self::statsRow($rServerID, $rStats, $rCounts);
		if ($rMarker !== null) {
			if (!is_dir(dirname($rMarker))) {
				@mkdir(dirname($rMarker), 0750, true);
			}
			@file_put_contents($rMarker, (string) $rNow, LOCK_EX);
		}
	}

	/** Where the shadow copies and stats markers go; null (not kept) without TMP_PATH. */
	private static function dir(): ?string {
		return self::$rDir ?? (defined('TMP_PATH') ? TMP_PATH . 'cluster/' : null);
	}

	/**
	 * The node's connection and user counts as its watchdog counted them
	 * without the Redis handler; null with it (MAIN's watchdog counts those).
	 *
	 * @return array{connections: int, users: int}|null
	 */
	private static function counts(int $rServerID): ?array {
		if (SettingsManager::get('redis_handler')) {
			return null;
		}
		self::db()->query('SELECT COUNT(*) AS `count` FROM `lines_live` WHERE `hls_end` = 0 AND `server_id` = ?;', $rServerID);
		$rConnections = (int) (self::db()->get_row()['count'] ?? 0);
		self::db()->query('SELECT `activity_id` FROM `lines_live` WHERE `hls_end` = 0 AND `server_id` = ? GROUP BY `user_id`;', $rServerID);
		return ['connections' => $rConnections, 'users' => (int) self::db()->num_rows()];
	}

	/**
	 * The minute's `servers_stats` row, as the LB's cron:servers wrote it.
	 *
	 * @param array<string, mixed> $rStats toWatchdogData()
	 * @param array{connections: int, users: int}|null $rCounts
	 */
	private static function statsRow(int $rServerID, array $rStats, ?array $rCounts): void {
		if ($rCounts === null) {
			self::db()->query('SELECT `connections`, `users` FROM `servers` WHERE `id` = ?;', $rServerID);
			$rRow = self::db()->get_row() ?: [];
			$rCounts = ['connections' => (int) ($rRow['connections'] ?? 0), 'users' => (int) ($rRow['users'] ?? 0)];
		}
		self::db()->query('SELECT COUNT(*) AS `count` FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `server_id` = ? AND `pid` > 0 AND `type` = 1;', $rServerID);
		$rStreams = (int) (self::db()->get_row()['count'] ?? 0);
		$rHistory = (array) $rStats['cpu_average_array'];
		$rCpu = count($rHistory) > 0 ? round(array_sum($rHistory) / count($rHistory), 2) : $rStats['cpu'];
		self::db()->query(
			'INSERT INTO `servers_stats`(`server_id`, `connections`, `total_users`, `users`, `streams`, `cpu`, `cpu_cores`, `cpu_avg`, `total_mem`, `total_mem_free`, `total_mem_used`, `total_mem_used_percent`, `total_disk_space`, `uptime`, `total_running_streams`, `bytes_sent`, `bytes_received`, `bytes_sent_total`, `bytes_received_total`, `cpu_load_average`, `gpu_info`, `iostat_info`, `time`) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?);',
			$rServerID,
			$rCounts['connections'],
			(int) (SettingsManager::get('total_users') ?? 0),
			$rCounts['users'],
			$rStreams,
			$rCpu,
			$rStats['cpu_cores'],
			$rStats['cpu_avg'],
			$rStats['total_mem'],
			$rStats['total_mem_free'],
			$rStats['total_mem_used'],
			$rStats['total_mem_used_percent'],
			$rStats['total_disk_space'],
			$rStats['uptime'],
			$rStats['total_running_streams'],
			$rStats['bytes_sent'],
			$rStats['bytes_received'],
			$rStats['bytes_sent_total'],
			$rStats['bytes_received_total'],
			$rStats['cpu_load_average'],
			json_encode($rStats['gpu_info'], JSON_UNESCAPED_UNICODE),
			json_encode($rStats['iostat_info'], JSON_UNESCAPED_UNICODE),
			ClusterClock::now()
		);
	}
}
