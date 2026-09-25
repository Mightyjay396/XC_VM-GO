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
 * spool/p0/<hrtime>-<pid>-<rand>.ndjson   stream state (never dropped)
 * spool/p1/<hrtime>-<pid>-<rand>.ndjson   logs (oldest dropped past the cap)
 * one line per event: {"type": "...", "t": <ms>, "d": {...}}
 * ```
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
	 * Append events of one lane as one file.
	 *
	 * @param list<array{type: string, d: array<string, mixed>}> $rEvents
	 * @return bool False when nothing was spooled: the caller falls back.
	 */
	public static function append(string $rLane, array $rEvents): bool {
		if (!in_array($rLane, self::LANES, true) || $rEvents === [] || !self::agentAlive()) {
			return false;
		}
		$rDir = self::dir() . $rLane . '/';
		if (!is_dir($rDir) && !@mkdir($rDir, 0750, true) && !is_dir($rDir)) {
			return false;
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
		$rName = sprintf('%019d-%d-%04x.ndjson', hrtime(true), getmypid(), random_int(0, 0xffff));
		$rTmp = $rDir . '.' . $rName . '.tmp';
		if (@file_put_contents($rTmp, $rBody) !== strlen($rBody)) {
			@unlink($rTmp);
			return false;
		}
		if (!@rename($rTmp, $rDir . $rName)) {
			@unlink($rTmp);
			return false;
		}
		return true;
	}

	/**
	 * The agent rewrites or touches flows.json on every heartbeat reply; an old
	 * file means it stopped, and nothing would drain the spool.
	 */
	private static function agentAlive(): bool {
		$rFlows = dirname(rtrim(self::dir(), '/')) . '/flows.json';
		$rMtime = @filemtime($rFlows);
		return $rMtime !== false && time() - $rMtime <= self::STALE_AFTER;
	}
}
