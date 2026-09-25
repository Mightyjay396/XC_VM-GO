<?php

namespace XcVm\Core\Cluster;

/**
 * Signal Dispatcher
 *
 * The one way code tells a node to do something: kill a viewer process, run a
 * root action, or refresh cached data. Callers used to write `signals` rows
 * directly (47 INSERTs); they now name the intent and the dispatcher writes it
 * through a SignalSink. Today the only sink is LegacySqlSignalSink, which
 * writes exactly the rows the old INSERTs wrote, so nothing changes for the
 * consumers (the signals daemon, cron:root_signals, the cache handler). The
 * cluster API plan (Phase 4) adds a sink that turns these into signed
 * commands for nodes in API mode.
 *
 * @package XC_VM_Core_Cluster
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

final class SignalDispatcher {
	private static ?SignalSink $rSink = null;

	/**
	 * Kill a viewer process (a connection's `pid`) on its node. Written with
	 * the database's clock, as the stream endpoints always did. A pid that is
	 * not positive names no process (the signals daemon would skip it, or send
	 * an RTMP drop for client 0), so nothing is written.
	 *
	 * @param object|null $rDb The caller's DatabaseHandler, if it holds its own.
	 */
	public static function kill(int $rServerID, int $rPID, bool $rRTMP = false, ?object $rDb = null): bool {
		if ($rPID <= 0) {
			return false;
		}
		$rRow = ['pid' => $rPID, 'server_id' => $rServerID];
		if ($rRTMP) {
			$rRow['rtmp'] = 1;
		}
		$rRow['time'] = null;
		return self::sink($rDb)->insert([$rRow]);
	}

	/**
	 * A root action for cron:root_signals on the node (update, reboot,
	 * restart_services, set_port, …). Encoded with json_encode(), so the
	 * consumers that match custom_data byte for byte keep matching.
	 *
	 * @param array<string, mixed>|string $rPayload An array, or JSON already encoded.
	 */
	public static function rootAction(int $rServerID, array|string $rPayload, ?object $rDb = null): bool {
		$rJson = is_string($rPayload) ? $rPayload : json_encode($rPayload);
		return self::sink($rDb)->insert([['server_id' => $rServerID, 'time' => time(), 'custom_data' => $rJson]]);
	}

	/**
	 * A cache job (update_line, update_streams, delete_vod, drop_con, …).
	 *
	 * @param array<string, mixed> $rPayload
	 * @param bool $rOnce   Skip when an identical cache signal is already pending.
	 * @param bool $rDbTime Stamp with the database's clock instead of PHP's.
	 */
	public static function cache(int $rServerID, array $rPayload, bool $rOnce = false, bool $rDbTime = false, ?object $rDb = null): bool {
		$rJson = json_encode($rPayload);
		$rSink = self::sink($rDb);
		if ($rOnce && $rSink->pending($rServerID, $rJson)) {
			return true;
		}
		return $rSink->insert([['server_id' => $rServerID, 'cache' => 1, 'time' => $rDbTime ? null : time(), 'custom_data' => $rJson]]);
	}

	/**
	 * Several cache jobs for one node in one write.
	 *
	 * @param list<array<string, mixed>> $rPayloads
	 */
	public static function cacheBatch(int $rServerID, array $rPayloads, ?int $rTime = null, ?object $rDb = null): bool {
		if (empty($rPayloads)) {
			return true;
		}
		$rTime ??= time();
		$rRows = [];
		foreach ($rPayloads as $rPayload) {
			$rRows[] = ['server_id' => $rServerID, 'cache' => 1, 'time' => $rTime, 'custom_data' => json_encode($rPayload)];
		}
		return self::sink($rDb)->insert($rRows);
	}

	/** Replace the sink (tests; later the cluster API). Null restores the legacy SQL sink. */
	public static function useSink(?SignalSink $rSink): void {
		self::$rSink = $rSink;
	}

	private static function sink(?object $rDb): SignalSink {
		return self::$rSink ?? new LegacySqlSignalSink($rDb);
	}
}
