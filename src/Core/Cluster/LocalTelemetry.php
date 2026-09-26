<?php

namespace XcVm\Core\Cluster;

/**
 * `config/cluster/local.json`: what a TELEMETRY node's agent cannot sample
 * itself, written by the node's watchdog (WatchdogCommand::writeLocalTelemetry)
 * for the agent to forward as the heartbeat's `telemetry.local`:
 *
 * ```text
 * requests_per_second  int     nginx requests per second
 * fanout               object  FanoutClient::status()
 * audio_devices        list    SystemInfo::getDevices(), refreshed every 30 s
 * video_devices        list
 * gpu_info             object
 * iostat_info          object
 * ```
 *
 * The agent forwards the file only while it is under 10 s old and at most
 * 64 KiB, parsed and re-encoded (key order and number formatting are not
 * kept); MAX_BYTES keeps a margin under that. MAIN reads the four device
 * sections into watchdog_data and servers_stats (HeartbeatService).
 *
 * In Core: the watchdog that calls it ships to LBs.
 */
final class LocalTelemetry {
	/** Largest local.json written: the agent ignores one over 64 KiB. */
	public const MAX_BYTES = 61440;

	/** Seconds a device probe is reused: the probes shell out. */
	public const DEVICES_EVERY = 30;

	/** Seconds each device tool may run (SystemInfo::getDevices()): a hung nvidia-smi must not stall the watchdog. */
	public const PROBE_TIMEOUT = 5;

	/** The device sections, in watchdog_data's order. */
	public const DEVICES = ['audio_devices', 'video_devices', 'gpu_info', 'iostat_info'];

	/**
	 * Rewrite `local.json` in $rDir with $rBase (requests_per_second, fanout)
	 * and the device sections, probed at most every $rEvery seconds.
	 *
	 * The watchdog runs one pass per process, so the last probe is kept in
	 * $rCache (`{"t": unix time, "devices": {...}}`), not in a variable; a
	 * cache that cannot be written only costs a probe per pass. When a probe
	 * is due, the file is written first with the last probe's sections and
	 * again after the probe, so a slow probe never ages it past the agent's
	 * 10 s. With no recent probe (none, one 2 x $rEvery old or more, an
	 * unreadable cache, a clock that went back) the probe runs first, as
	 * there is nothing current to report meanwhile.
	 *
	 * @param array<string, mixed> $rBase
	 * @param callable(): array<string, mixed> $rProbe SystemInfo::getDevices()
	 * @return bool False when $rDir is missing (no agent) or the last write failed.
	 */
	public static function refresh(string $rDir, string $rCache, int $rNow, array $rBase, callable $rProbe, int $rEvery = self::DEVICES_EVERY): bool {
		if (!is_dir($rDir)) {
			return false;
		}
		$rKept = json_decode((string) @file_get_contents($rCache), true);
		$rAge = PHP_INT_MAX;
		if (is_array($rKept) && is_int($rKept['t'] ?? null) && is_array($rKept['devices'] ?? null) && $rKept['t'] <= $rNow) {
			$rAge = $rNow - $rKept['t'];
		}
		if ($rAge < 2 * $rEvery) {
			$rWritten = self::write($rDir, $rBase + self::sections($rKept['devices']));
			if ($rAge < $rEvery) {
				return $rWritten;
			}
		}
		$rDevices = self::sections($rProbe());
		$rTmp = $rCache . '.' . getmypid() . '.tmp';
		if (@file_put_contents($rTmp, self::json(['t' => $rNow, 'devices' => $rDevices])) === false || !@rename($rTmp, $rCache)) {
			@unlink($rTmp);
		}
		return self::write($rDir, $rBase + $rDevices);
	}

	/**
	 * The document as JSON of at most $rMax bytes. Over it, the GPUs' process
	 * lists go first (one line per process on a busy transcoder), then the
	 * largest device section, one at a time; should that still not do, only
	 * requests_per_second and the four sections as [] are kept (fanout is
	 * dropped, so MAIN keeps its last value).
	 *
	 * @param array<string, mixed> $rDoc
	 */
	public static function encode(array $rDoc, int $rMax = self::MAX_BYTES): string {
		$rJson = self::json($rDoc);
		if (strlen($rJson) <= $rMax) {
			return $rJson;
		}
		if (is_array($rDoc['gpu_info']['gpus'] ?? null)) {
			foreach ($rDoc['gpu_info']['gpus'] as $rIndex => $rGPU) {
				if (is_array($rGPU) && array_key_exists('processes', $rGPU)) {
					$rDoc['gpu_info']['gpus'][$rIndex]['processes'] = [];
				}
			}
			$rJson = self::json($rDoc);
		}
		while (strlen($rJson) > $rMax) {
			$rLargest = null;
			$rSize = 0;
			foreach (self::DEVICES as $rKey) {
				$rLength = empty($rDoc[$rKey]) ? 0 : strlen(self::json($rDoc[$rKey]));
				if ($rLength > $rSize) {
					$rLargest = $rKey;
					$rSize = $rLength;
				}
			}
			if ($rLargest === null) {
				return self::json(['requests_per_second' => (int) ($rDoc['requests_per_second'] ?? 0)] + array_fill_keys(self::DEVICES, []));
			}
			$rDoc[$rLargest] = [];
			$rJson = self::json($rDoc);
		}
		return $rJson;
	}

	/**
	 * Replace `local.json` in $rDir (the agent's `config/cluster/`) atomically,
	 * so the agent never reads half a file. False when the directory is
	 * missing (no agent) or the write fails.
	 *
	 * @param array<string, mixed> $rDoc
	 */
	public static function write(string $rDir, array $rDoc): bool {
		if (!is_dir($rDir)) {
			return false;
		}
		$rTmp = $rDir . 'local.json.tmp';
		if (@file_put_contents($rTmp, self::encode($rDoc), LOCK_EX) === false || !@rename($rTmp, $rDir . 'local.json')) {
			@unlink($rTmp);
			return false;
		}
		return true;
	}

	/**
	 * @param array<mixed> $rDevices
	 * @return array<string, array<mixed>>
	 */
	private static function sections(array $rDevices): array {
		$rOut = [];
		foreach (self::DEVICES as $rKey) {
			$rOut[$rKey] = is_array($rDevices[$rKey] ?? null) ? $rDevices[$rKey] : [];
		}
		return $rOut;
	}

	private static function json(mixed $rValue): string {
		return (string) json_encode($rValue, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}
}
