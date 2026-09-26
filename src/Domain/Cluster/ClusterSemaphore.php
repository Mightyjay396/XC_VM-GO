<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\Crypto\ClusterCrypto;

/**
 * Per-op semaphores on the cluster bus (plan, section 8, "MAIN capacity"):
 * after a MAIN restart every agent says hello, re-keys and fetches its
 * replica at once, so each of these ops runs at most PERMITS at a time.
 *
 * A permit is a member of the sorted set `sem:<op>`, scored by when it
 * expires: taken after the request is authenticated and before its handler
 * runs, given back when the handler ends (finally), and dropped after the
 * op's worst case (OPS) if its holder died without giving it back. The set
 * has no TTL, so the bus's volatile-ttl policy never evicts a permit.
 *
 * Without the bus nothing is limited, as before it.
 */
final class ClusterSemaphore {
	/** Permits per op. */
	public const PERMITS = 4;

	/**
	 * The limited ops (the plan's `streams` has no op yet), each with its worst
	 * case in seconds: its lane's pool timeout (ctl 60 s, ingest 90 s).
	 */
	public const OPS = ['hello' => 60, 'token_rekey' => 60, 'config' => 90, 'conn_snapshot' => 90];

	/** The refusal's retry_after_ms is drawn from this range, to spread a fleet out. */
	public const RETRY_MIN_MS = 1000;
	public const RETRY_MAX_MS = 3000;

	/**
	 * KEYS sem:<op>; ARGV now, exp, permits, id => 1 taken, 0 none free.
	 * Expired permits go, and so do any expiring past now + the op's lifetime
	 * (taken before the clock stepped back), so one can never be held forever.
	 */
	private const ACQUIRE_LUA = <<<'LUA'
		redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', '(' .. ARGV[1])
		redis.call('ZREMRANGEBYSCORE', KEYS[1], '(' .. ARGV[2], '+inf')
		if redis.call('ZCARD', KEYS[1]) >= tonumber(ARGV[3]) then
			return 0
		end
		redis.call('ZADD', KEYS[1], ARGV[2], ARGV[4])
		return 1
		LUA;

	private const RELEASE_LUA = "return redis.call('ZREM', KEYS[1], ARGV[1])";

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
		$rPermit = self::acquire($rOp);
		if ($rPermit === false) {
			return DenialFactory::deny($rCrypto, 503, 'RATE_LIMITED', $rH['node'], $rH['nonce'], ['retry_after_ms' => random_int(self::RETRY_MIN_MS, self::RETRY_MAX_MS), 'op' => $rOp]);
		}
		try {
			return $rHandler();
		} finally {
			if ($rPermit !== null) {
				self::release($rOp, $rPermit);
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
		$rNow = ClusterClock::nowMs();
		$rID = bin2hex(random_bytes(8));
		return match (ClusterBus::script(self::ACQUIRE_LUA, ['sem:' . $rOp], [$rNow, $rNow + $rLife * 1000, self::PERMITS, $rID])) {
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
