<?php

namespace XcVm\Streaming\Fanout;

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\License\LicenseGate;

/**
 * FanoutMode — the admin's master switch for the xc_fanout daemon
 * (`settings.fanout_enabled`, default on).
 *
 * On, nothing changes: the daemon serves live viewers as it does since ADR 0003.
 * Off, every node stops its daemon and live delivery goes back to the paths
 * that existed before fanout:
 *
 *  - HLS: the panel rewrites the on-disk `<id>_.m3u8` (HLSGenerator::generateHLS)
 *    and segment.php serves `<id>_<n>.ts` from STREAMS_PATH;
 *  - TS: live.php chase-reads the on-disk segments (SegmentReader);
 *  - proxy streams: ProxyCommand relays the source to viewers over a unix socket;
 *  - supervision falls back to the PHP monitor, and the PHP producers (LLOD,
 *    loopback, delay) keep their on-disk HLS and skip the daemon feed.
 *
 * Two places read the switch. PHP reads the setting through {@see enabled()}.
 * The shell side (the `service` script and run.sh, which cannot read settings)
 * reads a node-local flag file, {@see flagPath()}, which RootSignalsCronJob keeps
 * in step with the setting every minute through {@see applyToNode()}.
 *
 * @package XC_VM_Streaming_Fanout
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class FanoutMode {
	/** Flag file name, next to the daemon binary; present = fanout disabled. */
	public const FLAG = 'disabled';

	/**
	 * Whether the admin has fanout switched on. A settings array without the key
	 * (not loaded yet, or a database from before migration 027) reads as on, so
	 * the switch never turns fanout off by accident.
	 *
	 * @param array|null $rSettings Settings to read (null = SettingsManager).
	 */
	public static function enabled(?array $rSettings = null): bool {
		$rValue = $rSettings === null ? SettingsManager::get('fanout_enabled') : ($rSettings['fanout_enabled'] ?? null);
		if ($rValue === null || $rValue === '') {
			return true;
		}
		return (bool) intval($rValue);
	}

	/**
	 * Whether live viewers take the pre-fanout delivery paths: fanout switched
	 * off, or the licence denies it (LicenseGate's soft enforcement). A daemon
	 * that is merely down while fanout is on is not a reason: viewers then get
	 * not-on-air until the keepalive restarts it (~2 s), as before.
	 *
	 * @param array|null $rSettings Settings to read (null = SettingsManager).
	 */
	public static function legacyDelivery(?array $rSettings = null): bool {
		return !self::enabled($rSettings) || !LicenseGate::fanoutAllowed();
	}

	/**
	 * The node-local flag the shell scripts check before starting the daemon.
	 */
	public static function flagPath(): string {
		return (defined('MAIN_HOME') ? MAIN_HOME : '/home/xc_vm/') . 'bin/xc_fanout/' . self::FLAG;
	}

	/**
	 * Bring this node in line with the switch. Writes or removes the flag file;
	 * when fanout is off, it also stops the run.sh supervisor and the daemon. The
	 * supervisor goes first, so it cannot respawn the daemon in between.
	 *
	 * @param bool          $rEnabled The switch.
	 * @param callable|null $rExec    fn(string $cmd): void (defaults to shell_exec; tests stub it).
	 * @param string|null   $rFlag    Flag file (defaults to flagPath(); tests use a temp dir).
	 * @return bool True when this call changed the node's state.
	 */
	public static function applyToNode(bool $rEnabled, ?callable $rExec = null, ?string $rFlag = null): bool {
		$rExec = $rExec ?? static function (string $rCmd): void {
			shell_exec($rCmd);
		};
		$rFlag = $rFlag ?? self::flagPath();
		if ($rEnabled) {
			if (file_exists($rFlag)) {
				@unlink($rFlag);
				return true;
			}
			return false;
		}

		$rChanged = false;
		if (!file_exists($rFlag)) {
			if (is_dir(dirname($rFlag))) {
				@file_put_contents($rFlag, time());
			}
			$rChanged = true;
		}
		// Idempotent: nothing to kill leaves pkill a no-op.
		$rRunSh = dirname($rFlag) . '/run.sh';
		$rExec('pkill -u xc_vm -f ' . escapeshellarg($rRunSh) . ' >/dev/null 2>&1');
		$rExec('pkill -u xc_vm -x xc_fanout >/dev/null 2>&1');
		return $rChanged;
	}
}
