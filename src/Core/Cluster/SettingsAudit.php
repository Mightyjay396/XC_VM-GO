<?php

namespace XcVm\Core\Cluster;

/**
 * Settings reads the node replica would not answer (plan, section 9, R1
 * `settings`: "API nodes log unknown-key reads to audit.settings_misses").
 *
 * On a node in mode 1 or 2, each read through SettingsManager of a key
 * outside `lb_settings_keys.php` (its `keys` and `withheld`) is counted.
 * Once the replica owns the settings such a key is absent, so the count
 * names a read the allowlist's scan missed (a key built at run time) before
 * it fails. Reading a key costs one lookup; nothing reaches a database:
 *
 * ```text
 * a process      counts in memory, merged into the day's file at exit and
 *                at most every FLUSH_EVERY s while it runs
 * day file       STORAGE_PATH/cluster/settings_misses/YYYYMMDD.json (UTC),
 *                {key: count}, at most MAX_KEYS keys, the rest under OTHER
 * audit.json     config/cluster/audit.json, {"settings_misses": {key: count}}:
 *                the last WINDOW_DAYS days, most missed first, at most
 *                MAX_KEYS keys and OTHER; the agent sends it as the
 *                heartbeat's `audit`, and MAIN keeps it with the node
 * ```
 *
 * audit.json is rewritten when a process merges misses, and by cron:cleanup
 * every hour, so days that leave the window drop out. Only on a node with an
 * agent (config/cluster/), and in mode 1 or 2; in mode 0 it is removed.
 *
 * Lives in Core: it ships to LBs, where Domain\Cluster does not.
 */
final class SettingsAudit {
	/** Keys a day file, and the report, name at most; the rest count under OTHER. */
	public const MAX_KEYS = 64;

	/** A key past MAX_KEYS, or not a settings name ([a-z0-9_]{1,64}). */
	public const OTHER = '*';

	/** Days the report covers, today included. */
	public const WINDOW_DAYS = 7;

	/** Seconds a long-running process keeps its counts before it merges them. */
	public const FLUSH_EVERY = 60;

	/** Tests: another directory for the day files; false: off. */
	private static string|false|null $rDir = null;

	/** Tests: another agent directory (where audit.json goes). */
	private static ?string $rAgentDir = null;

	/**
	 * The keys that are no miss (null: not decided yet in this process;
	 * false: this process counts nothing).
	 *
	 * @var array<string, true>|false|null
	 */
	private static array|false|null $rKnown = null;

	/** @var array<string, int> */
	private static array $rCounts = [];

	private static int $rFlushedAt = 0;

	private static bool $rAtExit = false;

	/**
	 * Tests: another directory for the day files, or false for none (the
	 * suite's default), and another agent directory; null restores
	 * STORAGE_PATH's and CONFIG_PATH's. The process decides again whether it
	 * counts.
	 */
	public static function useDir(string|false|null $rDir, ?string $rAgentDir = null): void {
		self::$rDir = $rDir;
		self::$rAgentDir = $rAgentDir;
		self::$rKnown = null;
		self::$rCounts = [];
		self::$rAtExit = false;
	}

	public static function dir(): ?string {
		if (self::$rDir === false) {
			return null;
		}
		return self::$rDir ?? (defined('STORAGE_PATH') ? STORAGE_PATH . 'cluster/settings_misses/' : null);
	}

	/**
	 * A read of $rKey (SettingsManager). Counted when this is a node in mode 1
	 * or 2 and the key is outside the allowlist. Decided once per process,
	 * from the agent's flows.json alone (NodeFlows::declared): MAIN has no
	 * agent, so it counts nothing.
	 */
	public static function read(string $rKey): void {
		if (self::$rKnown === false || (self::$rKnown !== null && isset(self::$rKnown[$rKey]))) {
			return;
		}
		if (self::$rKnown === null) {
			self::$rKnown = false; // a read while deciding is not counted
			self::$rKnown = self::known();
			self::$rFlushedAt = time();
			if (self::$rKnown === false || isset(self::$rKnown[$rKey])) {
				return;
			}
		}
		// Bounded in memory too: past MAX_KEYS distinct keys, the rest are OTHER.
		$rKey = isset(self::$rCounts[$rKey]) || count(self::$rCounts) < self::MAX_KEYS ? $rKey : self::OTHER;
		self::$rCounts[$rKey] = (self::$rCounts[$rKey] ?? 0) + 1;
		if (!self::$rAtExit) {
			self::$rAtExit = true;
			register_shutdown_function([self::class, 'flush']);
		} elseif (time() - self::$rFlushedAt >= self::FLUSH_EVERY) {
			self::flush();
		}
	}

	/**
	 * Merge this process's counts into the day's file (under its lock), then
	 * rewrite audit.json. Never throws: an audit must not break a read.
	 */
	public static function flush(?int $rNow = null): void {
		$rCounts = self::$rCounts;
		self::$rCounts = [];
		self::$rFlushedAt = $rNow ?? time();
		$rDir = self::dir();
		if ($rCounts === [] || $rDir === null) {
			return;
		}
		try {
			if (!is_dir($rDir)) {
				@mkdir($rDir, 0750, true);
				self::own($rDir);
			}
			$rFile = $rDir . gmdate('Ymd', self::$rFlushedAt) . '.json';
			$rHandle = @fopen($rFile, 'c+');
			if ($rHandle === false) {
				return;
			}
			try {
				flock($rHandle, LOCK_EX);
				$rDay = json_decode((string) stream_get_contents($rHandle), true);
				$rJson = (string) json_encode((object) self::merge(is_array($rDay) ? $rDay : [], $rCounts));
				ftruncate($rHandle, 0);
				rewind($rHandle);
				fwrite($rHandle, $rJson);
				fflush($rHandle);
			} finally {
				flock($rHandle, LOCK_UN);
				fclose($rHandle);
			}
			self::own($rFile);
			self::publish(null, self::$rFlushedAt);
		} catch (\Throwable) {
			// Counted, not needed: the next flush tries again.
		}
	}

	/**
	 * The misses of the last WINDOW_DAYS UTC days, today included: most missed
	 * first (then by name), at most MAX_KEYS names, the rest under OTHER.
	 *
	 * @return array<string, int>
	 */
	public static function summary(?int $rNow = null): array {
		$rDir = self::dir();
		$rNow ??= time();
		$rSum = [];
		for ($d = 0; $rDir !== null && $d < self::WINDOW_DAYS; $d++) {
			$rDay = json_decode((string) @file_get_contents($rDir . gmdate('Ymd', $rNow - $d * 86400) . '.json'), true);
			foreach (is_array($rDay) ? $rDay : [] as $rKey => $rCount) {
				if (is_int($rCount) && $rCount > 0) {
					$rKey = self::name((string) $rKey);
					$rSum[$rKey] = ($rSum[$rKey] ?? 0) + $rCount;
				}
			}
		}
		return self::top($rSum);
	}

	/**
	 * Write `audit.json` for the agent in $rAgentDir (config/cluster/):
	 * `{"settings_misses": summary()}` on a node in mode 1 or 2; removed in
	 * mode 0, where nothing is counted. False when there is no agent (MAIN, a
	 * legacy node) or the write failed.
	 */
	public static function publish(?string $rAgentDir = null, ?int $rNow = null): bool {
		$rAgentDir ??= self::$rAgentDir ?? (defined('CONFIG_PATH') ? CONFIG_PATH . 'cluster/' : null);
		if ($rAgentDir === null || self::dir() === null || !is_dir($rAgentDir)) {
			return false;
		}
		if (NodeFlows::declared()['mode'] < 1) {
			@unlink($rAgentDir . 'audit.json');
			return false;
		}
		$rTmp = $rAgentDir . 'audit.json.' . getmypid() . '.tmp';
		if (@file_put_contents($rTmp, (string) json_encode(['settings_misses' => (object) self::summary($rNow)])) === false || !@rename($rTmp, $rAgentDir . 'audit.json')) {
			@unlink($rTmp);
			return false;
		}
		self::own($rAgentDir . 'audit.json');
		return true;
	}

	/** Delete day files older than $rKeepDays. Returns how many went. */
	public static function prune(int $rKeepDays = 8, ?int $rNow = null): int {
		$rDir = self::dir();
		if ($rDir === null) {
			return 0;
		}
		$rCut = gmdate('Ymd', ($rNow ?? time()) - $rKeepDays * 86400);
		$rCount = 0;
		foreach (glob($rDir . '*.json') ?: [] as $rFile) {
			$rDay = basename($rFile, '.json');
			if (preg_match('/^\d{8}$/', $rDay) && $rDay < $rCut && @unlink($rFile)) {
				$rCount++;
			}
		}
		return $rCount;
	}

	/**
	 * The keys that are no miss: the allowlist's, secrets withheld included
	 * (their reads are known). False when this process counts nothing: no
	 * directory, or not a node in mode 1 or 2.
	 *
	 * @return array<string, true>|false
	 */
	private static function known(): array|false {
		if (self::dir() === null || NodeFlows::declared()['mode'] < 1) {
			return false;
		}
		$rList = @include __DIR__ . '/lb_settings_keys.php';
		if (!is_array($rList)) {
			return false;
		}
		return array_fill_keys(array_merge((array) ($rList['keys'] ?? []), (array) ($rList['withheld'] ?? [])), true);
	}

	/**
	 * $rCounts added to a day's counts, each key a settings name or OTHER, at
	 * most MAX_KEYS names.
	 *
	 * @param array<mixed> $rDay
	 * @param array<string, int> $rCounts
	 * @return array<string, int>
	 */
	private static function merge(array $rDay, array $rCounts): array {
		$rOut = [];
		foreach ($rDay as $rKey => $rCount) {
			if (is_int($rCount) && $rCount > 0) {
				$rOut[self::name((string) $rKey)] = $rCount;
			}
		}
		foreach ($rCounts as $rKey => $rCount) {
			$rKey = self::name((string) $rKey);
			if (!isset($rOut[$rKey]) && $rKey !== self::OTHER && count(array_diff_key($rOut, [self::OTHER => 0])) >= self::MAX_KEYS) {
				$rKey = self::OTHER;
			}
			$rOut[$rKey] = ($rOut[$rKey] ?? 0) + $rCount;
		}
		return $rOut;
	}

	/**
	 * Most missed first (then by name), at most MAX_KEYS names; the rest,
	 * and OTHER, last under OTHER.
	 *
	 * @param array<string, int> $rCounts
	 * @return array<string, int>
	 */
	public static function top(array $rCounts): array {
		$rOther = $rCounts[self::OTHER] ?? 0;
		unset($rCounts[self::OTHER]);
		$rKeys = array_map('strval', array_keys($rCounts));
		usort($rKeys, static fn(string $a, string $b): int => [$rCounts[$b], $a] <=> [$rCounts[$a], $b]);
		$rOut = [];
		foreach ($rKeys as $i => $rKey) {
			if ($i < self::MAX_KEYS) {
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

	/** A settings name, or OTHER. */
	public static function name(string $rKey): string {
		return preg_match('/^[a-z0-9_]{1,64}$/', $rKey) ? $rKey : self::OTHER;
	}

	/** A file or directory root made stays the panel user's, whose processes add to it. */
	private static function own(string $rPath): void {
		if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
			@chown($rPath, 'xc_vm');
			@chgrp($rPath, 'xc_vm');
		}
	}
}
