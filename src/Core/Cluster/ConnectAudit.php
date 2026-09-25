<?php

namespace XcVm\Core\Cluster;

/**
 * Connect Audit
 *
 * Records every connection this node opens to MAIN's MySQL or Redis, and from
 * where. The cluster API plan moves a node to API mode only after seven days
 * with none (the cutover gate), and the per-site counts show which code paths
 * still connect. Trace only: nothing is refused, and nothing is written unless
 * NodeRole::auditConnects() is on.
 *
 * Log: STORAGE_PATH/cluster/sql_audit/YYYYMMDD.ndjson (UTC), one line per
 * connect: {"t": unix time, "k": "sql"|"redis", "s": "file:line", "p": pid}.
 * STORAGE_PATH survives reboots, unlike tmp/, which the seven days need.
 *
 * @package XC_VM_Core_Cluster
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

final class ConnectAudit {
	public const SQL = 'sql';
	public const REDIS = 'redis';

	/** Frames that are the connect machinery itself, not the caller. */
	private const INTERNAL = [
		'XcVm\\Core\\Database\\Database',
		'XcVm\\Core\\Database\\DatabaseHandler',
		'XcVm\\Infrastructure\\Database\\DatabaseFactory',
		'XcVm\\Infrastructure\\Redis\\RedisManager',
		self::class,
	];

	public static function dir(): string {
		return STORAGE_PATH . 'cluster/sql_audit/';
	}

	/** Note one connect of $rKind. Never throws, never slows a caller that is not audited. */
	public static function record(string $rKind): void {
		if (!NodeRole::auditConnects()) {
			return;
		}
		try {
			$rLine = json_encode([
				't' => time(),
				'k' => $rKind,
				's' => self::site(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 16)),
				'p' => getmypid(),
			]) . "\n";
			$rDir = self::dir();
			if (!is_dir($rDir)) {
				@mkdir($rDir, 0750, true);
			}
			@file_put_contents($rDir . gmdate('Ymd') . '.ndjson', $rLine, FILE_APPEND | LOCK_EX);
		} catch (\Throwable $e) {
			// An audit must never break the connection it audits.
		}
	}

	/**
	 * The first frame outside the connect machinery, as "path:line" relative
	 * to MAIN_HOME.
	 *
	 * @param list<array<string, mixed>> $rTrace debug_backtrace() output.
	 */
	public static function site(array $rTrace): string {
		// Frame i's file:line is code inside frame i+1's function.
		foreach ($rTrace as $i => $rFrame) {
			if (!isset($rFrame['file']) || in_array($rTrace[$i + 1]['class'] ?? null, self::INTERNAL, true)) {
				continue;
			}
			$rFile = (string) $rFrame['file'];
			if (defined('MAIN_HOME') && str_starts_with($rFile, MAIN_HOME)) {
				$rFile = substr($rFile, strlen(MAIN_HOME));
			}
			return $rFile . ':' . ($rFrame['line'] ?? 0);
		}
		return 'unknown';
	}

	/**
	 * Connect counts over the last $rDays UTC days, including today.
	 *
	 * @return array{sql: int, redis: int, sites: array<string, int>}
	 */
	public static function summary(int $rDays = 7, ?int $rNow = null): array {
		$rNow ??= time();
		$rOut = ['sql' => 0, 'redis' => 0, 'sites' => []];
		for ($d = 0; $d < $rDays; $d++) {
			$rFile = self::dir() . gmdate('Ymd', $rNow - $d * 86400) . '.ndjson';
			$rHandle = @fopen($rFile, 'r');
			if ($rHandle === false) {
				continue;
			}
			while (($rRaw = fgets($rHandle)) !== false) {
				$rRow = json_decode($rRaw, true);
				if (!is_array($rRow) || !isset($rRow['k'], $rRow['s']) || !in_array($rRow['k'], [self::SQL, self::REDIS], true)) {
					continue;
				}
				$rOut[$rRow['k']]++;
				$rSite = $rRow['k'] . ' ' . $rRow['s'];
				$rOut['sites'][$rSite] = ($rOut['sites'][$rSite] ?? 0) + 1;
			}
			fclose($rHandle);
		}
		arsort($rOut['sites']);
		return $rOut;
	}

	/** Delete day files older than $rKeepDays. Returns how many went. */
	public static function prune(int $rKeepDays = 8, ?int $rNow = null): int {
		$rCut = gmdate('Ymd', ($rNow ?? time()) - $rKeepDays * 86400);
		$rCount = 0;
		foreach (glob(self::dir() . '*.ndjson') ?: [] as $rFile) {
			$rDay = basename($rFile, '.ndjson');
			if (preg_match('/^\d{8}$/', $rDay) && $rDay < $rCut && @unlink($rFile)) {
				$rCount++;
			}
		}
		return $rCount;
	}
}
