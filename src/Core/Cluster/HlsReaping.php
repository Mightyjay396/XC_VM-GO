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

	/** @var array<int, bool> server id => the node ends its own idle HLS viewers */
	private static array $rReaps = [];

	/** @var list<int> CONNECTIONS nodes silent past the orphan TTL, watched by MAIN */
	private static array $rOrphaned = [];

	private static ?string $rPath = null;

	/** Does this node end its own idle HLS viewers, so the 30 s rule must not? */
	public static function nodeReaps(int $rServerID): bool {
		if (defined('SERVER_ID') && $rServerID === (int) SERVER_ID) {
			return self::localReaps();
		}
		return self::$rReaps[$rServerID] ?? false;
	}

	/** On an LB: does this node's own agent end its idle HLS viewers? */
	public static function localReaps(): bool {
		return NodeFlows::on(NodeFlows::CONNECTIONS) && NodeFlows::agentHas(self::FEATURE);
	}

	/**
	 * MAIN, once per reaper pass: which nodes reap for themselves, and the
	 * silence watch that finds orphaned ones. Nothing is known (every node
	 * falls back to the 30 s rule) when cluster_nodes cannot be read.
	 */
	public static function begin(int $rNowSec, int $rOrphanTtlSec): void {
		self::$rReaps = [];
		self::$rOrphaned = [];
		$db = self::db();
		if (!$db->query("SELECT `server_id`, `mode`, `flows`, `features`, `last_seen_at` FROM `cluster_nodes` WHERE `state` = 'active';")) {
			return;
		}
		$rRows = $db->get_rows();
		$rState = self::load();
		$rSince = $rNowSec - $rState['run'] > self::MAX_PASS_GAP ? [] : $rState['since'];
		$rKeep = [];
		foreach ($rRows as $rRow) {
			$rID = (int) $rRow['server_id'];
			// Every CONNECTIONS node is watched (the orphan purge); only those
			// whose agent says hls_reaper end their own idle HLS viewers.
			if ((int) $rRow['mode'] < 1 || ((int) $rRow['flows'] & NodeFlows::CONNECTIONS) === 0) {
				continue;
			}
			$rCapable = in_array(self::FEATURE, explode(',', (string) ($rRow['features'] ?? '')), true);
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
		self::save(['run' => $rNowSec, 'since' => $rKeep]);
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
	}

	private static function path(): ?string {
		return self::$rPath ?? (defined('TMP_PATH') ? TMP_PATH . 'cluster_orphans.json' : null);
	}

	/** @return array{run: int, since: array<int, int>} */
	private static function load(): array {
		$rPath = self::path();
		$rDoc = $rPath === null ? null : json_decode((string) @file_get_contents($rPath), true);
		if (!is_array($rDoc) || !is_array($rDoc['since'] ?? null)) {
			return ['run' => 0, 'since' => []];
		}
		$rSince = [];
		foreach ($rDoc['since'] as $rID => $rAt) {
			$rSince[(int) $rID] = (int) $rAt;
		}
		return ['run' => (int) ($rDoc['run'] ?? 0), 'since' => $rSince];
	}

	/** @param array{run: int, since: array<int, int>} $rState */
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
