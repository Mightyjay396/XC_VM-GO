<?php

namespace XcVm\Core\Cluster;

use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * Who ends an idle HLS viewer (plan, Phase 6: "Connection close (HLS) —
 * agent reaper, 30 s after the last read").
 *
 * An HLS viewer has no worker to watch, only its playlist requests. The
 * legacy reaper (UsersCronJob) ends one 30 s after its `hls_last_read`. On a
 * node whose CONNECTIONS flow is on, that time only reaches MAIN in the
 * agent's throttled upserts, so a slow or cut link would end viewers who are
 * still watching. An agent that says `hls_reaper` at hello ends them itself,
 * by the node's own clock (a P0 `conn.upsert` with `hls_end` 1), and the
 * reaper here then closes only what the node ended.
 *
 * The exception is a node that has gone silent (orphaned): its viewers would
 * otherwise count against their lines forever. A CONNECTIONS node is orphaned
 * once its `last_seen_at` is older than `cluster_orphan_conn_ttl_sec` and this
 * reaper has itself watched it stay silent that long, so MAIN's own downtime
 * never orphans anyone. Its rows are then purged from MAIN's store
 * (orphaned(), ConnectionIngest::purgeNode), HLS and TS alike.
 *
 * A node that stops reaping for itself (CONNECTIONS off, mode 0, revoked,
 * an agent without `hls_reaper`) keeps counting as reaping for LEAVE_GRACE:
 * its touches reached only the cluster bus (conn.touch), so the store's
 * hls_last_read can be minutes old until the node's PHP, or an older agent's
 * P0 upserts, write fresh reads there again.
 *
 * In Core: UsersCronJob ships to LBs, which reap their own rows in MySQL
 * mode and ask their own agent (NodeFlows) instead of cluster_nodes.
 */
final class HlsReaping {
	use DatabaseAware;

	public const FEATURE = 'hls_reaper';

	/** Seconds without a playlist request before an HLS viewer ends. */
	public const STALE_AFTER = 30;

	/** Silence (s) from which the reaper starts watching a node. */
	private const WATCH_AFTER = 10;

	/** A gap between two passes longer than this restarts the watch (MAIN was down). */
	private const MAX_PASS_GAP = 180;

	/**
	 * Seconds a node still counts as reaping after a pass first finds it no
	 * longer does: two reaper passes, for the node to hear of the change at
	 * its next heartbeat and its viewers' next playlist requests to refresh
	 * the store.
	 */
	public const LEAVE_GRACE = 120;

	/** @var array<int, bool> server id => the node ends its own idle HLS viewers */
	private static array $rReaps = [];

	/** @var list<int> CONNECTIONS nodes silent past the orphan TTL, watched by MAIN */
	private static array $rOrphaned = [];

	/** On an LB, this pass's answer for its own rows (beginLocal()); null: ask the agent now. */
	private static ?bool $rLocal = null;

	private static ?string $rPath = null;

	/** Does this node end its own idle HLS viewers, so the 30 s rule must not? */
	public static function nodeReaps(int $rServerID): bool {
		if (defined('SERVER_ID') && $rServerID === (int) SERVER_ID) {
			return self::$rLocal ?? self::localReaps();
		}
		return self::$rReaps[$rServerID] ?? false;
	}

	/** On an LB: does this node's own agent end its idle HLS viewers? */
	public static function localReaps(): bool {
		return NodeFlows::on(NodeFlows::CONNECTIONS) && NodeFlows::agentHas(self::FEATURE);
	}

	/**
	 * Does this node's agent end its idle HLS viewers, by its cluster_nodes
	 * row (mode ≥ 1, CONNECTIONS on, `hls_reaper` said at hello)? Its touches
	 * then stay on MAIN's cluster bus (conn.touch).
	 *
	 * @param array<string, mixed> $rRow
	 */
	public static function capable(array $rRow): bool {
		return (int) ($rRow['mode'] ?? 0) >= 1 && ((int) ($rRow['flows'] ?? 0) & NodeFlows::CONNECTIONS) !== 0
			&& in_array(self::FEATURE, explode(',', (string) ($rRow['features'] ?? '')), true);
	}

	/**
	 * MAIN, once per reaper pass: which nodes reap for themselves, and the
	 * silence watch that finds orphaned ones. A node that reaped in an
	 * earlier pass and no longer does (its row changed or left the active
	 * ones) still counts for LEAVE_GRACE from the pass that first found it
	 * so, unless it is orphaned. When cluster_nodes cannot be read, the
	 * reapers of the last pass that read it stand (those leaving, within
	 * their grace) and nothing is orphaned: a reaping node's touches reach
	 * only the cluster bus, so MAIN's store holds no fresh read for the 30 s
	 * rule to judge by. With no such pass, every node falls back to the 30 s
	 * rule.
	 */
	public static function begin(int $rNowSec, int $rOrphanTtlSec): void {
		self::$rReaps = [];
		self::$rOrphaned = [];
		$db = self::db();
		try {
			$rRead = $db->query("SELECT `server_id`, `mode`, `flows`, `features`, `last_seen_at` FROM `cluster_nodes` WHERE `state` = 'active';");
		} catch (\Throwable) {
			$rRead = false;
		}
		$rState = self::load();
		if (!$rRead) {
			foreach ($rState['reaps'] as $rID) {
				self::$rReaps[$rID] = true;
			}
			foreach ($rState['leaving'] as $rID => $rSince) {
				self::$rReaps[$rID] = self::$rReaps[$rID] ?? ($rNowSec - $rSince < self::LEAVE_GRACE);
			}
			return;
		}
		$rRows = $db->get_rows();
		$rSince = $rNowSec - $rState['run'] > self::MAX_PASS_GAP ? [] : $rState['since'];
		$rKeep = [];
		foreach ($rRows as $rRow) {
			$rID = (int) $rRow['server_id'];
			// Every CONNECTIONS node is watched (the orphan purge); only those
			// whose agent says hls_reaper end their own idle HLS viewers.
			if ((int) $rRow['mode'] < 1 || ((int) $rRow['flows'] & NodeFlows::CONNECTIONS) === 0) {
				continue;
			}
			$rCapable = self::capable($rRow);
			$rSilent = $rRow['last_seen_at'] === null ? PHP_INT_MAX : $rNowSec - intdiv((int) $rRow['last_seen_at'], 1000);
			if ($rSilent >= self::WATCH_AFTER) {
				$rKeep[$rID] = $rSince[$rID] ?? $rNowSec;
			}
			$rOrphaned = isset($rKeep[$rID]) && $rSilent >= $rOrphanTtlSec && $rNowSec - $rKeep[$rID] >= $rOrphanTtlSec;
			self::$rReaps[$rID] = $rCapable && !$rOrphaned;
			if ($rOrphaned) {
				self::$rOrphaned[] = $rID;
			}
		}
		$rReapers = array_keys(array_filter(self::$rReaps));
		$rLeaving = [];
		foreach (array_unique([...$rState['reaps'], ...array_keys($rState['leaving'])]) as $rID) {
			if (!empty(self::$rReaps[$rID]) || in_array($rID, self::$rOrphaned, true)) {
				continue; // reaps again, or its rows are purged
			}
			$rLeft = $rState['leaving'][$rID] ?? $rNowSec;
			if ($rNowSec - $rLeft < self::LEAVE_GRACE) {
				self::$rReaps[$rID] = true;
				$rLeaving[$rID] = $rLeft;
			}
		}
		self::save(['run' => $rNowSec, 'since' => $rKeep, 'reaps' => $rReapers, 'leaving' => $rLeaving] + $rState);
	}

	/**
	 * An LB, once per reaper pass (in MySQL mode its users cron judges its
	 * own rows in `lines_live`): does its agent end its idle HLS viewers? One
	 * that stops (CONNECTIONS off, the reaper gone) still counts for
	 * LEAVE_GRACE from the pass that first found it so, as on MAIN.
	 */
	public static function beginLocal(int $rNowSec): void {
		$rState = self::load();
		$rReaps = self::localReaps();
		$rLeft = null;
		if (!$rReaps && ($rState['local'] || $rState['local_left'] !== null)) {
			$rLeft = $rState['local_left'] ?? $rNowSec;
			if ($rNowSec - $rLeft >= self::LEAVE_GRACE) {
				$rLeft = null;
			}
		}
		self::$rLocal = $rReaps || $rLeft !== null;
		self::save(['local' => $rReaps, 'local_left' => $rLeft] + $rState);
	}

	/**
	 * CONNECTIONS nodes found orphaned by the last begin(): their connections
	 * are purged from MAIN's store (ConnectionIngest::purgeNode).
	 *
	 * @return list<int>
	 */
	public static function orphaned(): array {
		return self::$rOrphaned;
	}

	/** Tests: keep the watch elsewhere, and forget the last pass. */
	public static function usePath(?string $rPath): void {
		self::$rPath = $rPath;
		self::$rReaps = [];
		self::$rOrphaned = [];
		self::$rLocal = null;
	}

	private static function path(): ?string {
		return self::$rPath ?? (defined('TMP_PATH') ? TMP_PATH . 'cluster_orphans.json' : null);
	}

	/**
	 * The last pass: MAIN's (run, since, reaps, leaving) and an LB's own
	 * (local, local_left).
	 *
	 * @return array{run: int, since: array<int, int>, reaps: list<int>, leaving: array<int, int>, local: bool, local_left: int|null}
	 */
	private static function load(): array {
		$rPath = self::path();
		$rDoc = $rPath === null ? null : json_decode((string) @file_get_contents($rPath), true);
		$rDoc = is_array($rDoc) ? $rDoc : [];
		$rReaps = array_values(array_map('intval', array_filter(is_array($rDoc['reaps'] ?? null) ? $rDoc['reaps'] : [], 'is_int')));
		return [
			'run' => (int) ($rDoc['run'] ?? 0), 'since' => self::times($rDoc['since'] ?? null), 'reaps' => $rReaps, 'leaving' => self::times($rDoc['leaving'] ?? null),
			'local' => ($rDoc['local'] ?? false) === true, 'local_left' => is_int($rDoc['local_left'] ?? null) ? $rDoc['local_left'] : null,
		];
	}

	/** @return array<int, int> server id => a time, from the saved doc */
	private static function times(mixed $rMap): array {
		$rOut = [];
		foreach (is_array($rMap) ? $rMap : [] as $rID => $rAt) {
			$rOut[(int) $rID] = (int) $rAt;
		}
		return $rOut;
	}

	/** @param array{run: int, since: array<int, int>, reaps: list<int>, leaving: array<int, int>, local: bool, local_left: int|null} $rState */
	private static function save(array $rState): void {
		$rPath = self::path();
		if ($rPath === null) {
			return;
		}
		$rTmp = $rPath . '.tmp';
		if (@file_put_contents($rTmp, json_encode($rState)) !== false) {
			@rename($rTmp, $rPath);
		}
	}
}
