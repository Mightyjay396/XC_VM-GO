<?php

namespace XcVm\Core\Cluster;

/**
 * QC: each node drops from its own agent registry the viewers that are gone,
 * with XC_VM's own timings: a TS viewer whose worker is dead on two passes in a
 * row, an HLS viewer ended and unread for `cluster_orphan_conn_ttl_sec`.
 * Run by cron:servers on the node itself.
 */
final class QcGhostReaper {
	private const REGISTRY = '/home/xc_vm/config/cluster/registry.snap';

	public static function run(): void {
		if (!is_file(self::REGISTRY) || !AgentConnections::enabled()) {
			return;
		}
		$rReg = json_decode((string) @file_get_contents(self::REGISTRY), true);
		if (!is_array($rReg)) {
			return;
		}
		$rTmp = defined('TMP_PATH') ? TMP_PATH : '/home/xc_vm/tmp/';
		$rStateFile = $rTmp . 'qc_reaper_state.json';
		$rPrev = json_decode((string) @file_get_contents($rStateFile), true) ?: [];
		$rNow = time();
		$rTtl = ClusterSettings::int('cluster_orphan_conn_ttl_sec', \XcVm\Core\Config\SettingsManager::get('cluster_orphan_conn_ttl_sec'));
		$rDeadNow = [];
		$rDel = [];
		foreach ($rReg as $rUUID => $rC) {
			if (!is_array($rC)) {
				continue;
			}
			if (!empty($rC['hls_end'])) {
				if ($rNow - max((int) ($rC['hls_last_read'] ?? 0), (int) ($rC['date_start'] ?? 0)) >= $rTtl) {
					$rDel[$rUUID] = 'hls_ended';
				}
				continue;
			}
			$rPid = (int) ($rC['pid'] ?? 0);
			if (($rC['container'] ?? '') !== 'ts' || $rPid <= 0 || file_exists('/proc/' . $rPid)) {
				continue;
			}
			$rDeadNow[$rUUID] = $rPid;
			if (($rPrev[$rUUID] ?? null) === $rPid) {
				$rDel[$rUUID] = 'ts_worker_dead';
			}
		}
		$rOk = 0;
		foreach ($rDel as $rUUID => $rWhy) {
			if (AgentConnections::delete((string) $rUUID)) {
				$rOk++;
				unset($rDeadNow[$rUUID]);
			}
		}
		@file_put_contents($rStateFile, json_encode($rDeadNow), LOCK_EX);
		if ($rDel) {
			@file_put_contents($rTmp . 'qc_conn_reaper.log', date('c') . sprintf(' registry=%d %s removed=%d ', count($rReg), json_encode(array_count_values($rDel)), $rOk) . implode(',', array_map(fn($u) => substr((string) $u, 0, 8), array_keys($rDel))) . "\n", FILE_APPEND | LOCK_EX);
		}
	}
}
