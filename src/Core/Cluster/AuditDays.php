<?php

namespace XcVm\Core\Cluster;

/**
 * The day files the node audits keep (SettingsAudit's settings misses,
 * ConnectAudit's connects): one `YYYYMMDD.<ext>` per UTC day in a directory,
 * counts of names, bounded to the most counted with the rest under OTHER.
 * The file names and formats are the audits' own; this is the handling they
 * share: the window read, the locked rewrite, the prune and the ranking.
 *
 * Lives in Core: it ships to LBs, where Domain\Cluster does not.
 */
final class AuditDays {
	/** The name counts past an audit's bound go under. */
	public const OTHER = '*';

	/**
	 * Most counted first (then by name), at most $rMax names; the rest, and
	 * OTHER, last under OTHER.
	 *
	 * @param array<string, int> $rCounts
	 * @return array<string, int>
	 */
	public static function top(array $rCounts, int $rMax): array {
		$rOther = $rCounts[self::OTHER] ?? 0;
		unset($rCounts[self::OTHER]);
		$rKeys = array_map('strval', array_keys($rCounts));
		usort($rKeys, static fn(string $a, string $b): int => [$rCounts[$b], $a] <=> [$rCounts[$a], $b]);
		$rOut = [];
		foreach ($rKeys as $i => $rKey) {
			if ($i < $rMax) {
				$rOut[$rKey] = $rCounts[$rKey];
			} else {
				$rOther += $rCounts[$rKey];
			}
		}
		if ($rOther > 0) {
			$rOut[self::OTHER] = $rOther;
		}
		return $rOut;
	}

	/**
	 * The name a count of $rKey goes under in $rCounts: $rKey when it is
	 * there already or there is room for it (at most $rMax names besides
	 * OTHER), OTHER otherwise.
	 *
	 * @param array<string, int> $rCounts
	 */
	public static function slot(array $rCounts, string $rKey, int $rMax): string {
		if (!isset($rCounts[$rKey]) && count(array_diff_key($rCounts, [self::OTHER => 0])) >= $rMax) {
			return self::OTHER;
		}
		return $rKey;
	}

	/**
	 * The day files in $rDir with one of the extensions $rExts: path =>
	 * YYYYMMDD.
	 *
	 * @param list<string> $rExts
	 * @return array<string, string>
	 */
	public static function files(string $rDir, array $rExts): array {
		$rOut = [];
		foreach ($rExts as $rExt) {
			foreach (glob($rDir . '*.' . $rExt) ?: [] as $rFile) {
				if (preg_match('/^(\d{8})\.' . preg_quote($rExt, '/') . '\z/', basename($rFile), $rMatch)) {
					$rOut[$rFile] = $rMatch[1];
				}
			}
		}
		return $rOut;
	}

	/**
	 * Delete the day files (extensions $rExts) of the days before $rKeepDays
	 * ago. Returns how many went.
	 *
	 * @param list<string> $rExts
	 */
	public static function prune(string $rDir, array $rExts, int $rKeepDays, int $rNow): int {
		$rCut = gmdate('Ymd', $rNow - $rKeepDays * 86400);
		$rCount = 0;
		foreach (self::files($rDir, $rExts) as $rFile => $rDay) {
			if ($rDay < $rCut && @unlink($rFile)) {
				$rCount++;
			}
		}
		return $rCount;
	}

	/**
	 * The `.json` day files of the last $rDays UTC days, today included, as
	 * their decoded contents (whatever they hold), newest first; a day before
	 * $rFrom's is left out, and an absent day is a day without a count. Null
	 * when this process cannot tell: $rDir (or the nearest level above it
	 * that exists) is not one it may search, or a day file is not one it may
	 * read. Each is read under its lock (readDay).
	 *
	 * @return list<mixed>|null
	 */
	public static function window(string $rDir, int $rDays, int $rNow, int $rFrom = 0): ?array {
		if (!self::searchable($rDir)) {
			return null;
		}
		$rFirst = gmdate('Ymd', $rFrom);
		$rOut = [];
		for ($d = 0; $d < $rDays; $d++) {
			$rDate = gmdate('Ymd', $rNow - $d * 86400);
			if ($rDate < $rFirst) {
				break;
			}
			$rFile = $rDir . $rDate . '.json';
			if (!is_file($rFile)) {
				continue;
			}
			$rRaw = self::readDay($rFile);
			if ($rRaw === false) {
				return null;
			}
			$rOut[] = json_decode($rRaw, true);
		}
		return $rOut;
	}

	/**
	 * Rewrite a day file in place under its exclusive lock: $rChange gets its
	 * decoded contents (null when new or not JSON) and returns the new
	 * contents. False, with nothing written, when it cannot be opened.
	 *
	 * @param \Closure(mixed): string $rChange
	 */
	public static function rewrite(string $rFile, \Closure $rChange): bool {
		$rHandle = @fopen($rFile, 'c+');
		if ($rHandle === false) {
			return false;
		}
		try {
			flock($rHandle, LOCK_EX);
			$rText = $rChange(json_decode((string) stream_get_contents($rHandle), true));
			ftruncate($rHandle, 0);
			rewind($rHandle);
			fwrite($rHandle, $rText);
			fflush($rHandle);
		} finally {
			flock($rHandle, LOCK_UN);
			fclose($rHandle);
		}
		return true;
	}

	/** Can this process search $rDir, or the nearest level above it that exists? */
	public static function searchable(string $rDir): bool {
		for ($rPath = rtrim($rDir, '/'); !is_dir($rPath); $rPath = dirname($rPath)) {
			if (dirname($rPath) === $rPath) {
				return false;
			}
		}
		return is_executable($rPath);
	}

	/**
	 * A day file's contents, read under its shared lock: a count rewrites the
	 * file in place under its exclusive lock (truncated, then written), and a
	 * read between the two would find a day without counts. False when it
	 * cannot be read.
	 */
	public static function readDay(string $rFile): string|false {
		$rHandle = @fopen($rFile, 'r');
		if ($rHandle === false) {
			return false;
		}
		try {
			flock($rHandle, LOCK_SH);
			return stream_get_contents($rHandle);
		} finally {
			flock($rHandle, LOCK_UN);
			fclose($rHandle);
		}
	}

	/**
	 * Make $rDir and each missing level above it, 0750. Root makes them under
	 * SettingsAudit::asAgentUser, as xc_vm: a level root's own would shut
	 * xc_vm's processes out of every day file below it.
	 */
	public static function makeDir(string $rDir): bool {
		return is_dir($rDir) || @mkdir($rDir, 0750, true) || is_dir($rDir);
	}
}
