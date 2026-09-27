<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\ClusterHealth;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * MAIN's liveness loop (plan, "Liveness"): every second, from the signals
 * daemon (and each minute from cron:cluster), the nodes whose telemetry is
 * authoritative are judged by their silence (NodeHealth) and the result is
 * published for routing (Core\Cluster\ClusterHealth).
 *
 * Published states get worse at once and better only after steady health
 * (NodeHealth::settle), so a node near the 10 s threshold does not flap.
 *
 * The fleet guard: when more than half of those nodes, and at least two,
 * fall silent together, MAIN is more likely the one cut off (its network,
 * its API pool). So too when the cluster_ctl pool, which takes every
 * heartbeat, has had requests waiting for a worker for over 5 s
 * (ClusterPool::listenQueueMs(), read once a pass). The guard then holds
 * nodes at their last published state instead of marking them offline,
 * the orphan purge waits (Core\Cluster\HlsReaping), and each reason is
 * audited and shown on the Cluster Nodes page, until both clear. The
 * silence holds every node; the queue only those it may have silenced,
 * for a bounded time, and one offline window past its end (queueHold()).
 * A pool that cannot tell (down, no status page) never raises it.
 *
 * Each pass first reads the queue, then flushes the heartbeats the cluster
 * bus holds into MySQL (HeartbeatService::flush), and judges each node by
 * the later of the two, so a MySQL copy up to a flush behind never makes a
 * node look silent. When the bus is lost, or restarts empty, the heard
 * times the loop last read from it stand in for a flush's worth of time
 * (busHeard()).
 */
final class LivenessService {
	use DatabaseAware;

	/** A cluster_ctl listen queue lasting longer than this raises the fleet guard (ms). */
	public const QUEUE_GUARD_MS = 5000;

	/**
	 * Passes further apart than this (ms) do not join one queue run: the
	 * queue may have drained between them. A pass's own reader still counts
	 * the whole wait of a request it has kept waiting.
	 */
	public const QUEUE_GAP_MS = 5000;

	/**
	 * Longest a queue run holds its nodes, in offline windows
	 * (cluster_offline_after_sec) from its start: 2 min at the default. A
	 * queue whose requests each wait a moment can last while every live node
	 * is heard, and would otherwise keep a node that died meanwhile suspect,
	 * and routed viewers, for as long.
	 */
	public const QUEUE_HOLD_WINDOWS = 4;

	/** Tests: fn(): ?int, the queue's age as ClusterPool::listenQueueMs() says it (null: the real probe). */
	private static ?\Closure $rQueueReader = null;

	/**
	 * The heard times the last pass read from the bus: [its socket, when
	 * (MAIN's ms), server id => heard ms].
	 *
	 * @var array{0: ?string, 1: int, 2: array<int, int>}|null
	 */
	private static ?array $rBusHeard = null;

	/** Tests: read the cluster_ctl listen queue's age with fn(): ?int (null: ClusterPool's probe). */
	public static function useQueueReader(?\Closure $rReader): void {
		self::$rQueueReader = $rReader;
	}

	/**
	 * One pass. Returns the transitions it published, server id => [from, to];
	 * the caller rewrites the servers cache when there are any.
	 *
	 * @return array<int, array{0: ?string, 1: ?string}>
	 */
	public static function tick(int $rOfflineAfterSec): array {
		// The queue first: heartbeats that waited in it ahead of the probe's
		// request are then in this pass's flush.
		$rAge = self::queueAge();
		$rNow = ClusterClock::nowMs();
		$rHeard = HeartbeatService::flush();
		$rReady = ClusterMeta::readyAtMs();
		$rHeard = self::busHeard($rHeard, $rNow);
		self::db()->query("SELECT `server_id`, `last_seen_at` FROM `cluster_nodes` WHERE `state` = 'active' AND `mode` >= 1 AND (`flows` & ?) <> 0;", NodeRegistry::FLOW_TELEMETRY);
		$rRows = self::db()->get_rows();
		$rPrev = ClusterHealth::read();
		$rJudged = [];
		$rOkSince = [];
		$rSilentFrom = [];
		foreach ($rRows as $rRow) {
			$rID = (int) $rRow['server_id'];
			$rHeardAt = HeartbeatService::freshest($rRow['last_seen_at'], $rHeard[$rID] ?? null);
			// Where its silence counts from, as NodeHealth::state() counts it.
			$rSilentFrom[$rID] = max((int) $rHeardAt, $rReady);
			$rState = NodeHealth::state($rHeardAt, $rReady, $rNow, $rOfflineAfterSec);
			// A node that has not spoken since the flow went on is judged offline
			// only once the loop has been ready long enough to have heard it.
			if ($rState === 'unknown') {
				$rState = $rNow - $rReady > $rOfflineAfterSec * 1000 ? 'offline' : 'suspect';
			}
			[$rJudged[$rID], $rSince] = NodeHealth::settle($rPrev['states'][$rID] ?? null, $rState, $rPrev['ok_since'][$rID] ?? null, $rNow);
			if ($rSince !== null) {
				$rOkSince[$rID] = $rSince;
			}
		}

		$rSilent = count(array_filter($rJudged, static fn($rState) => $rState !== 'ok'));
		$rQueue = self::ctlQueue($rNow, $rAge, $rPrev['ctl_queue']);
		$rReasons = array_keys(array_filter([
			ClusterHealth::GUARD_SILENCE => count($rJudged) >= 2 && $rSilent >= 2 && $rSilent * 2 > count($rJudged),
			ClusterHealth::GUARD_CTL_QUEUE => $rQueue !== null && $rQueue['at'] - $rQueue['since'] > self::QUEUE_GUARD_MS,
		]));
		$rGuard = $rReasons !== [];
		$rWas = $rPrev['reasons'];
		$rHold = self::queueHold($rReasons, $rWas, $rQueue, $rPrev['ctl_queue_hold'], $rNow, $rOfflineAfterSec);
		foreach ($rJudged as $rID => $rState) {
			if ($rState !== 'offline' || ($rPrev['states'][$rID] ?? null) === 'offline') {
				continue;
			}
			if (in_array(ClusterHealth::GUARD_SILENCE, $rReasons, true) || ($rHold !== null && $rSilentFrom[$rID] >= $rHold['from'])) {
				$rJudged[$rID] = $rPrev['states'][$rID] ?? 'suspect';
			}
		}
		if (in_array(ClusterHealth::GUARD_SILENCE, $rReasons, true) !== in_array(ClusterHealth::GUARD_SILENCE, $rWas, true)) {
			ClusterAudit::log(in_array(ClusterHealth::GUARD_SILENCE, $rReasons, true) ? 'cluster.fleet_silence' : 'cluster.fleet_silence_clear', null, ['silent' => $rSilent, 'nodes' => count($rJudged)], 'liveness');
		}
		if (in_array(ClusterHealth::GUARD_CTL_QUEUE, $rReasons, true)) {
			if (!in_array(ClusterHealth::GUARD_CTL_QUEUE, $rWas, true)) {
				ClusterAudit::log('cluster.ctl_queue', null, ['queued_ms' => (int) $rQueue['at'] - (int) $rQueue['since']], 'liveness');
			}
		} elseif (in_array(ClusterHealth::GUARD_CTL_QUEUE, $rWas, true)) {
			$rLast = $rPrev['ctl_queue'];
			ClusterAudit::log('cluster.ctl_queue_clear', null, [
				'lasted_ms' => $rLast === null ? null : $rLast['at'] - $rLast['since'],
				// Drained: a request answered at once; else the pool could not tell, or the run broke.
				'queue' => $rAge === 0 ? 'drained' : 'unknown',
			], 'liveness');
		}

		$rTransitions = [];
		foreach ($rJudged + $rPrev['states'] as $rID => $rUnused) {
			$rFrom = $rPrev['states'][$rID] ?? null;
			$rTo = $rJudged[$rID] ?? null;
			if ($rFrom !== $rTo) {
				$rTransitions[$rID] = [$rFrom, $rTo];
				if ($rTo !== null && $rFrom !== null) {
					ClusterAudit::log('node.health', $rID, ['from' => $rFrom, 'to' => $rTo], 'liveness');
				}
			}
		}
		ksort($rOkSince);
		if ($rTransitions !== [] || $rGuard !== $rPrev['guard'] || $rOkSince !== $rPrev['ok_since'] || $rReasons !== $rWas || $rQueue !== $rPrev['ctl_queue'] || $rHold !== $rPrev['ctl_queue_hold']) {
			ksort($rJudged);
			ClusterHealth::write($rJudged, $rGuard, $rOkSince, $rReasons, $rQueue, $rHold);
		}
		return $rTransitions;
	}

	/**
	 * The cluster_ctl listen queue's age, as ClusterPool::listenQueueMs()
	 * says it: ms, 0 for none, null when the pool cannot tell (so does a
	 * reader that fails).
	 */
	private static function queueAge(): ?int {
		try {
			$rAge = self::$rQueueReader !== null ? (self::$rQueueReader)() : ClusterPool::listenQueueMs('cluster_ctl');
		} catch (\Throwable) {
			return null;
		}
		return is_int($rAge) ? max(0, $rAge) : null;
	}

	/**
	 * The cluster_ctl listen queue's run after this pass, [since, at] in
	 * MAIN's ms, or null. A run lasts from `now - age`. A pass within
	 * QUEUE_GAP_MS of the last one that saw it, before or after, joins that
	 * run: cron:cluster beside the signals daemon, each with its own request,
	 * and either may write health.json between the other's clock read and
	 * its read of the file. A pass that sees none, or cannot tell, ends it.
	 *
	 * @param array{since: int, at: int}|null $rPrev
	 * @return array{since: int, at: int}|null
	 */
	private static function ctlQueue(int $rNow, ?int $rAge, ?array $rPrev): ?array {
		if ($rAge === null || $rAge === 0) {
			return null;
		}
		$rRun = ['since' => $rNow - $rAge, 'at' => $rNow];
		if ($rPrev !== null && abs($rNow - $rPrev['at']) <= self::QUEUE_GAP_MS) {
			$rRun = ['since' => min($rRun['since'], $rPrev['since']), 'at' => max($rNow, $rPrev['at'])];
		}
		return $rRun;
	}

	/**
	 * The queue's hold on offline marking after this pass: nodes whose
	 * silence counts from `from` or later are not newly marked offline while
	 * it lasts, until `until` (MAIN's ms); or null.
	 *
	 * - While the ctl_queue reason is up, `from` is the run's start less
	 *   NodeHealth::SUSPECT_AFTER_MS: a node silent since before the queue
	 *   was not silenced by it (a live one is heard every few seconds, and the
	 *   probe may see the queue a pass late). `until` is the start plus
	 *   QUEUE_HOLD_WINDOWS offline windows.
	 * - When the reason clears (drained, or the pool can no longer tell), the
	 *   hold lasts one more offline window at most: heartbeats that waited in
	 *   the queue are heard late or not at all (CLOCK_SKEW, a pool that died),
	 *   and this pass may judge heard times from before the end, so each node
	 *   gets a full window to be heard, as ready_at gives it after MAIN's own
	 *   downtime.
	 *
	 * @param list<string>                      $rReasons this pass's
	 * @param list<string>                      $rWas     the last pass's
	 * @param array{since: int, at: int}|null   $rQueue   this pass's run
	 * @param array{from: int, until: int}|null $rPrev
	 * @return array{from: int, until: int}|null
	 */
	private static function queueHold(array $rReasons, array $rWas, ?array $rQueue, ?array $rPrev, int $rNow, int $rOfflineAfterSec): ?array {
		$rWindow = $rOfflineAfterSec * 1000;
		$rHold = $rPrev;
		if ($rQueue !== null && in_array(ClusterHealth::GUARD_CTL_QUEUE, $rReasons, true)) {
			$rHold = ['from' => $rQueue['since'] - NodeHealth::SUSPECT_AFTER_MS, 'until' => $rQueue['since'] + self::QUEUE_HOLD_WINDOWS * $rWindow];
		} elseif ($rHold !== null && in_array(ClusterHealth::GUARD_CTL_QUEUE, $rWas, true)) {
			$rHold['until'] = min($rHold['until'], $rNow + $rWindow);
		}
		// Past its end, or before its start (the clock stepped back): none.
		return $rHold !== null && $rNow >= $rHold['from'] && $rNow <= $rHold['until'] ? $rHold : null;
	}

	/**
	 * This pass's heard times from the bus. A pass that reads none (the bus
	 * lost, or restarted empty) gets the last ones read from the same bus
	 * within HeartbeatService::FLUSH_EVERY_MS. MySQL alone may then say a
	 * live node was last heard a flush, the 1 s loop and two heartbeat
	 * intervals ago (12 s at 3 s, over the 10 s suspect threshold); with
	 * them it is at most 5 s plus one interval, and by the end of that
	 * window each node's next heartbeat, one interval after the loss, has
	 * written MySQL itself.
	 *
	 * @param array<int, int> $rHeard HeartbeatService::flush()
	 * @return array<int, int>
	 */
	private static function busHeard(array $rHeard, int $rNow): array {
		$rSocket = ClusterBus::socket();
		if ($rHeard !== []) {
			self::$rBusHeard = [$rSocket, $rNow, $rHeard];
			return $rHeard;
		}
		[$rWas, $rAt, $rLast] = self::$rBusHeard ?? [null, 0, []];
		return $rWas === $rSocket && $rNow >= $rAt && $rNow - $rAt <= HeartbeatService::FLUSH_EVERY_MS ? $rLast : [];
	}
}
