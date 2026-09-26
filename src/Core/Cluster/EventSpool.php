<?php

namespace XcVm\Core\Cluster;

/**
 * Where this load balancer's PHP hands events to its agent (plan, sections 7
 * and 8, Phase 5): one file per write under `config/cluster/spool/<lane>/`,
 * written aside and renamed in, so the agent never reads half a file. The
 * agent sends them to MAIN's `events` op in name order and deletes them once
 * MAIN has applied them; until then they survive restarts of either side.
 *
 * ```text
 * spool/p0/<hrtime>-<pid>-<rand>[-<tag>].ndjson   stream state (never dropped)
 * spool/p1/<hrtime>-<pid>-<rand>[-<tag>].ndjson   logs (oldest dropped past the cap)
 * one line per event: {"type": "...", "t": <ms>, "d": {...}}
 * ```
 *
 * A writer that only ever needs its latest report sent tags its files, and
 * skips a write while one of them is still pending() (DivergenceSink).
 *
 * Callers redact before appending: nothing with a credential is written here.
 * When the agent looks stopped (it has not confirmed MAIN's flows for
 * STALE_AFTER seconds) append() refuses, and the caller writes the legacy way.
 */
final class EventSpool {
	public const LANES = ['p0', 'p1'];

	/** Seconds without an agent heartbeat after which PHP stops spooling. */
	public const STALE_AFTER = 120;

	private static ?string $rDir = null;

	/** Tests: another spool directory; null restores the default. */
	public static function useDir(?string $rDir): void {
		self::$rDir = $rDir;
	}

	public static function dir(): string {
		return self::$rDir ?? ((defined('CONFIG_PATH') ? CONFIG_PATH : '/home/xc_vm/config/') . 'cluster/spool/');
	}

	/**
	 * Append events of one lane as one file, its name ending in $rTag when
	 * one is given (`[a-z_]+`).
	 *
	 * @param list<array{type: string, d: array<string, mixed>}> $rEvents
	 * @return bool False when nothing was spooled: the caller falls back.
	 */
	public static function append(string $rLane, array $rEvents, string $rTag = ''): bool {
		if (!in_array($rLane, self::LANES, true) || $rEvents === [] || !preg_match('/^[a-z_]*$/', $rTag) || !self::agentAlive()) {
			return false;
		}
		$rDir = self::dir() . $rLane . '/';
		// Root (cron:root_signals, certbot) hands what it creates to the owner
		// of the agent's state dir: a root-owned lane or file is one the agent
		// cannot drain.
		$rRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;
		$rHome = dirname(rtrim(self::dir(), '/'));
		if (!is_dir($rDir)) {
			if (!@mkdir($rDir, 0750, true) && !is_dir($rDir)) {
				return false;
			}
			if ($rRoot) {
				foreach ([self::dir(), $rDir] as $rMade) {
					if (!self::giveToOwnerOf($rMade, $rHome, 0750)) {
						return false;
					}
				}
			}
		}
		$rNow = (int) floor(microtime(true) * 1000);
		$rBody = '';
		foreach ($rEvents as $rEvent) {
			$rLine = json_encode(['type' => $rEvent['type'], 't' => $rNow, 'd' => (object) $rEvent['d']], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
			if ($rLine === false) {
				return false;
			}
			$rBody .= $rLine . "\n";
		}
		$rName = sprintf('%019d-%d-%04x', hrtime(true), getmypid(), random_int(0, 0xffff)) . ($rTag === '' ? '' : '-' . $rTag) . '.ndjson';
		$rTmp = $rDir . '.' . $rName . '.tmp';
		if (@file_put_contents($rTmp, $rBody) !== strlen($rBody) || ($rRoot && !self::giveToOwnerOf($rTmp, $rHome, 0640))) {
			@unlink($rTmp);
			return false;
		}
		if (!@rename($rTmp, $rDir . $rName)) {
			@unlink($rTmp);
			return false;
		}
		return true;
	}

	/** Give what root made to the owner of $rOf (the agent's state dir). */
	private static function giveToOwnerOf(string $rPath, string $rOf, int $rMode): bool {
		$rOwner = @fileowner($rOf);
		$rGroup = @filegroup($rOf);
		return $rOwner !== false && $rGroup !== false && @chown($rPath, $rOwner) && @chgrp($rPath, $rGroup) && @chmod($rPath, $rMode);
	}

	/** Is a file tagged $rTag still in the lane, not yet sent to MAIN by the agent? */
	public static function pending(string $rLane, string $rTag): bool {
		if (!in_array($rLane, self::LANES, true) || !preg_match('/^[a-z_]+$/', $rTag)) {
			return false;
		}
		return (glob(self::dir() . $rLane . '/*-' . $rTag . '.ndjson') ?: []) !== [];
	}

	/**
	 * The agent rewrites or touches flows.json on every heartbeat reply; an old
	 * file means it stopped, and nothing would drain the spool.
	 */
	public static function agentAlive(): bool {
		$rFlows = dirname(rtrim(self::dir(), '/')) . '/flows.json';
		// A long-running process would otherwise keep reading a cached mtime.
		clearstatcache(true, $rFlows);
		$rMtime = @filemtime($rFlows);
		return $rMtime !== false && time() - $rMtime <= self::STALE_AFTER;
	}
}
