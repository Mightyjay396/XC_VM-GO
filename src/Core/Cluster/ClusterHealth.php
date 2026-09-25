<?php

namespace XcVm\Core\Cluster;

/**
 * Liveness of the nodes whose telemetry is authoritative, as MAIN's liveness
 * loop last judged it (`tmp/cluster/health.json`, written by
 * Domain\Cluster\LivenessService). Routing reads it:
 *
 * - `offline` — no new viewers are routed to the node;
 * - `suspect` — its capacity weight is doubled, so it gets fewer;
 * - `ok` — it is online whatever its legacy last_check_ago says.
 *
 * Nodes not in the file (legacy nodes, the TELEMETRY flow off, every LB)
 * keep the legacy rule. In Core: ServerRepository and ConnectionTracker
 * ship to LBs, where the file simply does not exist.
 */
final class ClusterHealth {
	/** @var array{states: array<int, string>, guard: bool}|null */
	private static ?array $rCache = null;

	private static float $rReadAt = 0.0;

	private static ?string $rPath = null;

	/** @return 'ok'|'suspect'|'offline'|null null when the node is not judged by the loop. */
	public static function state(int $rServerID): ?string {
		return self::read()['states'][$rServerID] ?? null;
	}

	/** Capacity weight: 2 for a suspect node, else 1. */
	public static function weight(int $rServerID): float {
		return self::state($rServerID) === 'suspect' ? 2.0 : 1.0;
	}

	/** @return array{states: array<int, string>, guard: bool} */
	public static function read(): array {
		if (self::$rCache === null || microtime(true) - self::$rReadAt >= 1.0) {
			$rDoc = json_decode((string) @file_get_contents(self::path()), true);
			$rStates = [];
			foreach ((is_array($rDoc['states'] ?? null) ? $rDoc['states'] : []) as $rID => $rState) {
				if (in_array($rState, ['ok', 'suspect', 'offline'], true)) {
					$rStates[(int) $rID] = $rState;
				}
			}
			self::$rCache = ['states' => $rStates, 'guard' => !empty($rDoc['guard'])];
			self::$rReadAt = microtime(true);
		}
		return self::$rCache;
	}

	/** @param array<int, string> $rStates */
	public static function write(array $rStates, bool $rGuard): void {
		$rPath = self::path();
		if (!is_dir(dirname($rPath))) {
			@mkdir(dirname($rPath), 0750, true);
		}
		$rTmp = $rPath . '.tmp';
		if (@file_put_contents($rTmp, (string) json_encode(['states' => $rStates, 'guard' => $rGuard]), LOCK_EX) !== false) {
			@rename($rTmp, $rPath);
		}
		self::$rCache = ['states' => $rStates, 'guard' => $rGuard];
		self::$rReadAt = microtime(true);
	}

	/** Tests: use another file, and forget what was read. */
	public static function usePath(?string $rPath): void {
		self::$rPath = $rPath;
		self::$rCache = null;
	}

	private static function path(): string {
		return self::$rPath ?? ((defined('TMP_PATH') ? TMP_PATH : sys_get_temp_dir() . '/') . 'cluster/health.json');
	}
}
