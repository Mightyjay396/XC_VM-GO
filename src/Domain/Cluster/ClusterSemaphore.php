<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\ClusterSettings;
use XcVm\Core\Cluster\Crypto\ClusterCrypto;

/**
 * Per-op semaphores on the cluster bus (plan, section 8, "MAIN capacity"):
 * after a MAIN restart every agent says hello, re-keys and fetches its
 * replica at once, so each of these ops runs at most PERMITS at a time.
 *
 * And the ingest permits (section 8, "Ordering and backpressure"): the ingest
 * ops (ClusterPool::INGEST_OPS) run at most `cluster_ingest_concurrency` at a
 * time, and ceil(n / 2) of those permits are kept for P0 `events` batches, so
 * bulk transfers (P1/P2 events, config, snapshots, content) never hold up
 * P0. One is kept for bulk, so P0 never holds up bulk entirely either. Each
 * lane always gets its reserve; the rest are shared (ingestPermits()).
 *
 * A permit is a member of the sorted set `sem:<op>` (an ingest permit, of
 * `sem:ingest:p0` or `sem:ingest:bulk`), scored by when it expires: taken
 * after the request is authenticated and before its handler runs (an ingest
 * permit once the BOX is open), given back when the handler ends (finally),
 * and dropped after the op's worst case (OPS; INGEST_LIFE) if its holder died
 * without giving it back. The set has no TTL, so the bus's volatile-ttl
 * policy never evicts a permit.
 *
 * Its times are the bus's own (TIME, read inside the script). Scripts run one
 * at a time, so each sees a time no earlier than the permits already held. A
 * worker's own clock, read before its script reached the bus, could lag them
 * and drop them as taken before a clock step.
 *
 * Without the bus nothing is limited, as before it.
 */
final class ClusterSemaphore {
	/** Permits per op. */
	public const PERMITS = 4;

	/**
	 * The limited ops, each with its worst case in seconds: its lane's pool
	 * timeout (ctl 60 s, ingest 90 s).
	 */
	public const OPS = ['hello' => 60, 'token_rekey' => 60, 'config' => 90, 'conn_snapshot' => 90, 'streams' => 90];

	/** The refusal's retry_after_ms is drawn from this range, to spread a fleet out. */
	public const RETRY_MIN_MS = 1000;
	public const RETRY_MAX_MS = 3000;

	/**
	 * A P0 batch refused an ingest permit is asked back sooner: P0 goes out
	 * within 250 ms (the agent flushes it every 200 ms), and its permits are
	 * held only by other P0 batches then.
	 */
	public const P0_RETRY_MIN_MS = 250;
	public const P0_RETRY_MAX_MS = 750;

	/** The ingest permits' lanes (plan section 3): P0 `events` batches, and every other ingest request. */
	public const LANE_P0 = 'p0';
	public const LANE_BULK = 'bulk';

	/**
	 * The ingest permits kept for bulk: P0 may take every other free one, never
	 * these, so a busy P0 never shuts out config, snapshots, logs and
	 * recordings.
	 */
	public const BULK_RESERVE = 1;

	/** An ingest permit's worst case in seconds: the cluster_ingest pool's timeout. */
	public const INGEST_LIFE = ClusterPool::POOLS['cluster_ingest'];

	/**
	 * Milliseconds past the bus's now plus the op's lifetime that a permit may
	 * expire before it counts as taken before the clock stepped back.
	 */
	public const STEP_MS = 1000;

	/**
	 * KEYS sem:<op>; ARGV lifetime ms, permits, id, STEP_MS => 1 taken, 0 none
	 * free. Expired permits go, and so do any expiring past now + the lifetime
	 * + STEP_MS (taken before the clock stepped back), so one can never be
	 * held forever.
	 *
	 * The ingest permits pass a second key, the other lane's set (same
	 * lifetime), pruned alike, and ARGV[5] total, ARGV[6] this lane's reserve,
	 * ARGV[7] the other lane's. Past its own reserve, a lane takes a permit
	 * only while the permits held, and the other lane's reserve it does not
	 * hold yet, leave one of the total free. So each lane always gets its
	 * reserve, even while the other holds permits taken at a higher total.
	 */
	private const ACQUIRE_LUA = <<<'LUA'
		local t = redis.call('TIME')
		local now = tonumber(t[1]) * 1000 + math.floor(tonumber(t[2]) / 1000)
		local exp = now + tonumber(ARGV[1])
		local held = 0
		for i = 1, #KEYS do
			redis.call('ZREMRANGEBYSCORE', KEYS[i], '-inf', '(' .. now)
			redis.call('ZREMRANGEBYSCORE', KEYS[i], '(' .. (exp + tonumber(ARGV[4])), '+inf')
			held = held + redis.call('ZCARD', KEYS[i])
		end
		local own = redis.call('ZCARD', KEYS[1])
		if own >= tonumber(ARGV[2]) then
			return 0
		end
		if #KEYS > 1 and own >= tonumber(ARGV[6]) and held + math.max(0, tonumber(ARGV[7]) - (held - own)) >= tonumber(ARGV[5]) then
			return 0
		end
		redis.call('ZADD', KEYS[1], exp, ARGV[3])
		return 1
		LUA;

	private const RELEASE_LUA = "return redis.call('ZREM', KEYS[1], ARGV[1])";

	/** KEYS the permit sets => how many permits each holds now (unexpired, on the bus's clock). Read-only. */
	private const HELD_LUA = <<<'LUA'
		local t = redis.call('TIME')
		local now = tonumber(t[1]) * 1000 + math.floor(tonumber(t[2]) / 1000)
		local out = {}
		for i = 1, #KEYS do
			out[i] = redis.call('ZCOUNT', KEYS[i], now, '+inf')
		end
		return out
		LUA;

	/**
	 * Run an op's handler holding one of its permits. When none is free, the
	 * handler does not run and the node gets a panel-signed 503 RATE_LIMITED
	 * with retry_after_ms, naming it and the request.
	 *
	 * @param array{node: string, nonce: string} $rH The request's headers (Canonical::parseHeaders).
	 * @param callable(): array{status: int, headers: array<string, string>, body: string} $rHandler
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	public static function run(ClusterCrypto $rCrypto, string $rOp, array $rH, callable $rHandler): array {
		return self::holding($rCrypto, 'sem:' . $rOp, self::acquire($rOp), ['retry_after_ms' => random_int(self::RETRY_MIN_MS, self::RETRY_MAX_MS), 'op' => $rOp], $rH, $rHandler);
	}

	/**
	 * Run an ingest op's handler holding one of its lane's ingest permits
	 * (ingestLane()). When none is free, the handler does not run and the node
	 * gets a panel-signed 503 RATE_LIMITED with retry_after_ms, the op and the
	 * lane, naming it and the request.
	 *
	 * @param 'p0'|'bulk' $rLane
	 * @param mixed $rConcurrency `cluster_ingest_concurrency` (ingestPermits())
	 * @param array{node: string, nonce: string} $rH The request's headers (Canonical::parseHeaders).
	 * @param callable(): array{status: int, headers: array<string, string>, body: string} $rHandler
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	public static function runIngest(ClusterCrypto $rCrypto, string $rOp, string $rLane, mixed $rConcurrency, array $rH, callable $rHandler): array {
		$rRetry = $rLane === self::LANE_P0 ? random_int(self::P0_RETRY_MIN_MS, self::P0_RETRY_MAX_MS) : random_int(self::RETRY_MIN_MS, self::RETRY_MAX_MS);
		return self::holding($rCrypto, 'sem:ingest:' . $rLane, self::acquireIngest($rLane, $rConcurrency), ['retry_after_ms' => $rRetry, 'op' => $rOp, 'lane' => $rLane], $rH, $rHandler);
	}

	/**
	 * The ingest lane whose permit a request holds: `p0` for a P0 `events`
	 * batch, `bulk` for any other ingest op (ClusterPool::INGEST_OPS), null
	 * for a control op. The lane of a batch is in its BOX, so this is known
	 * only once the BOX is open.
	 *
	 * @param array<mixed> $rPayload The opened BOX.
	 * @return 'p0'|'bulk'|null
	 */
	public static function ingestLane(string $rOp, array $rPayload): ?string {
		if (!in_array($rOp, ClusterPool::INGEST_OPS, true)) {
			return null;
		}
		return $rOp === 'events' && ($rPayload['lane'] ?? null) === 'p0' ? self::LANE_P0 : self::LANE_BULK;
	}

	/**
	 * The ingest permits for a `cluster_ingest_concurrency` of n (clamped to
	 * the setting's range, its default when unset): `p0`, ceil(n / 2), kept
	 * for P0; `bulk`, the rest (at least BULK_RESERVE: at n = 1 one P0 and one
	 * bulk, as the pool's floor), the most bulk may hold, BULK_RESERVE of them
	 * kept for it and the others shared with P0; `total` is their sum, n from
	 * 2 up.
	 *
	 * @return array{p0: int, bulk: int, total: int}
	 */
	public static function ingestPermits(mixed $rConcurrency): array {
		$rN = ClusterSettings::int('cluster_ingest_concurrency', $rConcurrency);
		$rP0 = intdiv($rN + 1, 2);
		$rBulk = max(self::BULK_RESERVE, $rN - $rP0);
		return ['p0' => $rP0, 'bulk' => $rBulk, 'total' => $rP0 + $rBulk];
	}

	/**
	 * Take one of a lane's ingest permits: its id, false when none is free,
	 * null without the bus. P0 takes any free permit but those kept for bulk,
	 * bulk any of its share not kept for P0; each gets its reserve even when
	 * the other holds permits taken before the concurrency was lowered.
	 *
	 * @param 'p0'|'bulk' $rLane
	 */
	public static function acquireIngest(string $rLane, mixed $rConcurrency): string|false|null {
		$rPermits = self::ingestPermits($rConcurrency);
		$rP0 = $rLane === self::LANE_P0;
		$rKeys = $rP0 ? ['sem:ingest:' . self::LANE_P0, 'sem:ingest:' . self::LANE_BULK] : ['sem:ingest:' . self::LANE_BULK, 'sem:ingest:' . self::LANE_P0];
		$rID = bin2hex(random_bytes(8));
		$rArgs = [self::INGEST_LIFE * 1000, $rP0 ? $rPermits['total'] : $rPermits['bulk'], $rID, self::STEP_MS, $rPermits['total'], $rP0 ? $rPermits['p0'] : self::BULK_RESERVE, $rP0 ? self::BULK_RESERVE : $rPermits['p0']];
		return match (ClusterBus::script(self::ACQUIRE_LUA, $rKeys, $rArgs)) {
			1 => $rID,
			0 => false,
			default => null,
		};
	}

	/**
	 * How many ingest permits each lane holds now, against what it may hold
	 * (ingestPermits()), for the Cluster Nodes page: MAIN's ingest saturation.
	 * Null without the bus, where nothing is limited.
	 *
	 * @return array{p0: int, bulk: int, permits: array{p0: int, bulk: int, total: int}}|null
	 */
	public static function ingestInUse(mixed $rConcurrency): ?array {
		$rHeld = ClusterBus::script(self::HELD_LUA, ['sem:ingest:' . self::LANE_P0, 'sem:ingest:' . self::LANE_BULK], []);
		if (!is_array($rHeld) || count($rHeld) !== 2) {
			return null;
		}
		return ['p0' => (int) $rHeld[0], 'bulk' => (int) $rHeld[1], 'permits' => self::ingestPermits($rConcurrency)];
	}

	/**
	 * Give an ingest permit back.
	 *
	 * @param 'p0'|'bulk' $rLane
	 */
	public static function releaseIngest(string $rLane, string $rID): void {
		ClusterBus::script(self::RELEASE_LUA, ['sem:ingest:' . $rLane], [$rID]);
	}

	/**
	 * Run a handler holding a permit of the set $rKey: none free (false) is the
	 * signed 503 RATE_LIMITED with $rDenial's fields; null (no bus, or not
	 * limited) runs it without one. The permit goes back however it ends.
	 *
	 * @param array<string, mixed> $rDenial
	 * @param array{node: string, nonce: string} $rH
	 * @param callable(): array{status: int, headers: array<string, string>, body: string} $rHandler
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	private static function holding(ClusterCrypto $rCrypto, string $rKey, string|false|null $rPermit, array $rDenial, array $rH, callable $rHandler): array {
		if ($rPermit === false) {
			return DenialFactory::deny($rCrypto, 503, 'RATE_LIMITED', $rH['node'], $rH['nonce'], $rDenial);
		}
		try {
			return $rHandler();
		} finally {
			if ($rPermit !== null) {
				ClusterBus::script(self::RELEASE_LUA, [$rKey], [$rPermit]);
			}
		}
	}

	/**
	 * Take one of the op's permits: its id, false when none is free, null when
	 * the op takes none (not limited, or no bus).
	 */
	public static function acquire(string $rOp): string|false|null {
		$rLife = self::OPS[$rOp] ?? null;
		if ($rLife === null) {
			return null;
		}
		$rID = bin2hex(random_bytes(8));
		return match (ClusterBus::script(self::ACQUIRE_LUA, ['sem:' . $rOp], [$rLife * 1000, self::PERMITS, $rID, self::STEP_MS])) {
			1 => $rID,
			0 => false,
			default => null,
		};
	}

	/** Give a permit back. One the bus lost, or already expired, is gone anyway. */
	public static function release(string $rOp, string $rID): void {
		ClusterBus::script(self::RELEASE_LUA, ['sem:' . $rOp], [$rID]);
	}
}
