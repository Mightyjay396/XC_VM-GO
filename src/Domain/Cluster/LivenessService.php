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
 * every node at its last published state instead of marking any offline,
 * the orphan purge waits (Core\Cluster\HlsReaping), and each reason is
 * audited and shown on the Cluster Nodes page, until both clear. A pool
 * that cannot tell (down, no status page) never raises it.
 *
 * Each pass first flushes the heartbeats the cluster bus holds into MySQL
 * (HeartbeatService::flush), and judges each node by the later of the two,
 * so a MySQL copy up to a flush behind never makes a node look silent.
 * When the bus is lost, or restarts empty, the heard times the loop last
 * read from it stand in for a flush's worth of time (busHeard()).
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
		$rHeard = HeartbeatService::flush();
		$rReady = ClusterMeta::readyAtMs();
		$rNow = ClusterClock::nowMs();
		$rHeard = self::busHeard($rHeard, $rNow);
		self::db()->query("SELECT `server_id`, `last_seen_at` FROM `cluster_nodes` WHERE `state` = 'active' AND `mode` >= 1 AND (`flows` & ?) <> 0;", NodeRegistry::FLOW_TELEMETRY);
		$rRows = self::db()->get_rows();
		$rPrev = ClusterHealth::read();
		$rJudged = [];
		$rOkSince = [];
		foreach ($rRows as $rRow) {
			$rID = (int) $rRow['server_id'];
			$rState = NodeHealth::state(HeartbeatService::freshest($rRow['last_seen_at'], $rHeard[$rID] ?? null), $rReady, $rNow, $rOfflineAfterSec);
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
		[$rQueue, $rQueueAge] = self::ctlQueue($rNow, $rPrev['ctl_queue']);
		$rReasons = array_keys(array_filter([
			ClusterHealth::GUARD_SILENCE => count($rJudged) >= 2 && $rSilent >= 2 && $rSilent * 2 > count($rJudged),
			ClusterHealth::GUARD_CTL_QUEUE => $rQueue !== null && $rNow - $rQueue['since'] > self::QUEUE_GUARD_MS,
		]));
		$rGuard = $rReasons !== [];
		if ($rGuard) {
			foreach ($rJudged as $rID => $rState) {
				if ($rState === 'offline' && ($rPrev['states'][$rID] ?? null) !== 'offline') {
					$rJudged[$rID] = $rPrev['states'][$rID] ?? 'suspect';
				}
			}
		}
		$rWas = $rPrev['reasons'];
		if (in_array(ClusterHealth::GUARD_SILENCE, $rReasons, true) !== in_array(ClusterHealth::GUARD_SILENCE, $rWas, true)) {
			ClusterAudit::log(in_array(ClusterHealth::GUARD_SILENCE, $rReasons, true) ? 'cluster.fleet_silence' : 'cluster.fleet_silence_clear', null, ['silent' => $rSilent, 'nodes' => count($rJudged)], 'liveness');
		}
		if (in_array(ClusterHealth::GUARD_CTL_QUEUE, $rReasons, true)) {
			if (!in_array(ClusterHealth::GUARD_CTL_QUEUE, $rWas, true)) {
				ClusterAudit::log('cluster.ctl_queue', null, ['queued_ms' => $rNow - (int) $rQueue['since']], 'liveness');
			}
		} elseif (in_array(ClusterHealth::GUARD_CTL_QUEUE, $rWas, true)) {
			$rLast = $rPrev['ctl_queue'];
			ClusterAudit::log('cluster.ctl_queue_clear', null, [
				'lasted_ms' => $rLast === null ? null : $rLast['at'] - $rLast['since'],
				// Drained: a request answered at once; else the pool could not tell, or the run broke.
				'queue' => $rQueueAge === 0 ? 'drained' : 'unknown',
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
		if ($rTransitions !== [] || $rGuard !== $rPrev['guard'] || $rOkSince !== $rPrev['ok_since'] || $rReasons !== $rWas || $rQueue !== $rPrev['ctl_queue']) {
			ksort($rJudged);
			ClusterHealth::write($rJudged, $rGuard, $rOkSince, $rReasons, $rQueue);
		}
		return $rTransitions;
	}

	/**
	 * The cluster_ctl listen queue's run after this pass, [since, at] in
	 * MAIN's ms or null, and what the reader said (its age in ms, 0 for
	 * none, null when the pool cannot tell). A run lasts from `now - age`;
	 * a pass within QUEUE_GAP_MS of the last one that saw it joins that run
	 * (cron:cluster beside the signals daemon, each with its own request).
	 * A pass that sees none, or cannot tell, ends it.
	 *
	 * @param array{since: int, at: int}|null $rPrev
	 * @return array{0: array{since: int, at: int}|null, 1: ?int}
	 */
	private static function ctlQueue(int $rNow, ?array $rPrev): array {
		try {
			$rAge = self::$rQueueReader !== null ? (self::$rQueueReader)() : ClusterPool::listenQueueMs('cluster_ctl');
		} catch (\Throwable) {
			$rAge = null;
		}
		if (!is_int($rAge) || $rAge <= 0) {
			return [null, is_int($rAge) ? 0 : null];
		}
		$rSince = $rNow - $rAge;
		if ($rPrev !== null && $rNow >= $rPrev['at'] && $rNow - $rPrev['at'] <= self::QUEUE_GAP_MS) {
			$rSince = min($rSince, $rPrev['since']);
		}
		return [['since' => $rSince, 'at' => $rNow], $rAge];
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
