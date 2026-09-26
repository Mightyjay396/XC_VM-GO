<?php

namespace XcVm\Domain\Cluster;

use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * The replay cache for authenticated requests: a (node, nonce) pair is
 * accepted once within its 180 s lifetime. It is written only after the MAC
 * or signature has verified, so unauthenticated requests burn no nonce.
 *
 * On the cluster bus when it runs (`nonce:<node>`, a sorted set of nonces
 * scored by expiry, checked and added by one script), else in MySQL
 * (`cluster_nonces`, whose primary key makes the check-and-insert atomic).
 * Neither store may forget a nonce a request could still replay, so a switch
 * between them never opens a window:
 *
 * - The bus is not persisted. `nonces_since` is when it took its first claim,
 *   and it holds every claim since. A request stamped before that plus
 *   LEAD_MS may replay a claim a lost bus held, so it is refused. A request
 *   stamped more than LEAD_MS ahead of MAIN's clock, which that rule cannot
 *   cover, is also claimed in MySQL. For its first TTL a bus also claims in
 *   MySQL, where the claims from before it are.
 * - MySQL takes claims only while the bus is out of reach, and marks the
 *   second it did (SQL_MARK). The bus also claims in MySQL while that mark is
 *   under TTL old.
 * - The bus marks the second it took a claim (BUS_MARK). Without the bus, a
 *   request stamped before the end of that second plus LEAD_MS is refused:
 *   the bus may hold it. While other workers still reach the bus, this worker
 *   therefore refuses until it reaches the bus too.
 *
 * The marks are files beside the bus socket, on disk, so they outlive a
 * reboot. On the bus, nonces live in sorted sets without a TTL, which the
 * bus's volatile-ttl policy never evicts.
 */
final class NonceStore {
	use DatabaseAware;

	/** Seconds a nonce is remembered: twice the ±90 s window. */
	public const TTL = 180;

	/**
	 * Milliseconds a request may be stamped ahead of MAIN's clock and still be
	 * claimed on the bus alone; also how far past a bus's first claim, or past
	 * the second of its last one, a request is refused as possibly replayed.
	 */
	public const LEAD_MS = 250;

	/** The second the bus last took a claim (a file's mtime, beside the socket). */
	public const BUS_MARK = 'nonces.bus';

	/** The second MySQL last took a claim while the bus could come back. */
	public const SQL_MARK = 'nonces.sql';

	/**
	 * KEYS nonce:<node>, nonces_since; ARGV now, exp, nonce => {fresh, since}.
	 * A history start ahead of the clock (it stepped back) restarts the
	 * history, rather than refusing everything until the clock catches up.
	 */
	private const CLAIM_LUA = <<<'LUA'
		local now = tonumber(ARGV[1])
		local since = tonumber(redis.call('GET', KEYS[2]))
		if not since or since > now + 1000 then
			since = now
			redis.call('SET', KEYS[2], ARGV[1])
		end
		redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', '(' .. ARGV[1])
		if redis.call('ZSCORE', KEYS[1], ARGV[3]) then
			return {0, since}
		end
		redis.call('ZADD', KEYS[1], ARGV[2], ARGV[3])
		return {1, since}
		LUA;

	/** KEYS issued:<node>; ARGV now, exp, value, ttl ms. */
	private const ISSUE_LUA = <<<'LUA'
		redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', '(' .. ARGV[1])
		redis.call('ZADD', KEYS[1], ARGV[2], ARGV[3])
		redis.call('PEXPIRE', KEYS[1], ARGV[4])
		return 1
		LUA;

	/** KEYS issued:<node>; ARGV now, value => 1 while it is live. */
	private const ISSUED_LUA = <<<'LUA'
		local exp = redis.call('ZSCORE', KEYS[1], ARGV[2])
		if exp and tonumber(exp) >= tonumber(ARGV[1]) then
			return 1
		end
		return 0
		LUA;

	/**
	 * Record a nonce; false when it was already used (a replay), or when MAIN
	 * cannot vouch that it was not (see above).
	 *
	 * @param ?int $rTsMs The request's X-XCVM-Ts, MAIN ms; null for a value
	 *                    MAIN made itself (the re-key minute, a used challenge).
	 */
	public static function claim(string $rNode, string $rNonce, ?int $rTsMs = null): bool {
		$rNow = ClusterClock::nowMs();
		$rOut = ClusterBus::script(self::CLAIM_LUA, ['nonce:' . $rNode, 'nonces_since'], [$rNow, $rNow + self::TTL * 1000, bin2hex($rNonce)]);
		if (!is_array($rOut) || count($rOut) !== 2) {
			return self::claimWithoutBus($rNode, $rNonce, $rTsMs, $rNow);
		}
		$rSince = (int) $rOut[1];
		if ((int) $rOut[0] !== 1 || ($rTsMs !== null && $rTsMs <= $rSince + self::LEAD_MS) || !self::mark(self::BUS_MARK, $rNow)) {
			return false;
		}
		if ($rNow - $rSince < self::TTL * 1000 || self::markedWithinTtl(self::SQL_MARK, $rNow) || ($rTsMs !== null && $rTsMs > $rNow + self::LEAD_MS)) {
			return self::insert($rNode, $rNonce);
		}
		return true;
	}

	/**
	 * Keep a value MAIN hands out (a `challenge`) for consume() to take once
	 * within TTL: on the bus, else in MySQL. Losing one early only makes its
	 * consume() fail, so on the bus it may be evicted (its set has a TTL): an
	 * unauthenticated caller can ask for many.
	 */
	public static function issue(string $rNode, string $rNonce): void {
		$rNow = ClusterClock::nowMs();
		if (ClusterBus::script(self::ISSUE_LUA, ['issued:' . $rNode], [$rNow, $rNow + self::TTL * 1000, bin2hex($rNonce), self::TTL * 1000]) !== null) {
			return;
		}
		// Marked, so a bus that comes back records its use in MySQL too, where
		// a worker without the bus looks.
		if (self::markSql($rNow)) {
			self::insert($rNode, $rNonce);
		}
	}

	/**
	 * Consume a value issue() handed out, from whichever store holds it: true
	 * once, while it is live. Atomic without affected-row counts: the
	 * consumption is itself a claim under a sibling key, so of two concurrent
	 * callers exactly one wins.
	 */
	public static function consume(string $rNode, string $rNonce): bool {
		$rNow = ClusterClock::nowMs();
		if (ClusterBus::script(self::ISSUED_LUA, ['issued:' . $rNode], [$rNow, bin2hex($rNonce)]) !== 1) {
			self::db()->query('SELECT `exp` FROM `cluster_nonces` WHERE `node` = ? AND `nonce` = ? AND `exp` >= ?;', $rNode, $rNonce, intdiv($rNow, 1000));
			if (self::db()->num_rows() === 0) {
				return false;
			}
		}
		return self::claim('used:' . $rNode, $rNonce);
	}

	/** Drop what has expired: MySQL's rows, and on the bus the nonces of nodes gone quiet. */
	public static function purge(): void {
		$rRedis = ClusterBus::client();
		if ($rRedis !== null) {
			try {
				$rNow = ClusterClock::nowMs();
				$rIt = null;
				while (($rKeys = $rRedis->scan($rIt, 'nonce:*', 1000)) !== false) {
					foreach ($rKeys as $rKey) {
						$rRedis->zRemRangeByScore($rKey, '-inf', '(' . $rNow);
					}
				}
			} catch (\Throwable) {
				// The next minute tries again; each claim prunes its own node's set.
			}
		}
		self::db()->query('DELETE FROM `cluster_nonces` WHERE `exp` < ?;', ClusterClock::now());
	}

	/** Without the bus: MySQL, unless the bus may hold the nonce. */
	private static function claimWithoutBus(string $rNode, string $rNonce, ?int $rTsMs, int $rNow): bool {
		$rBusAt = self::markedAt(self::BUS_MARK);
		if ($rTsMs !== null && $rBusAt !== null && $rTsMs < (min($rBusAt, intdiv($rNow, 1000)) + 1) * 1000 + self::LEAD_MS) {
			return false;
		}
		return self::markSql($rNow) && self::insert($rNode, $rNonce);
	}

	/**
	 * Mark that MySQL took a value the bus does not hold. Needless when no bus
	 * socket is there: the next bus to start is new, and looks in MySQL for
	 * its first TTL anyway.
	 */
	private static function markSql(int $rNow): bool {
		$rSocket = ClusterBus::socket();
		return $rSocket === null || !file_exists($rSocket) || self::mark(self::SQL_MARK, $rNow);
	}

	/** Record the current second in a mark, at most one write a second; false when it cannot be written. */
	private static function mark(string $rName, int $rNow): bool {
		$rPath = self::markPath($rName);
		if ($rPath === null) {
			return true;
		}
		$rSec = intdiv($rNow, 1000);
		return self::markedAt($rName) === $rSec || @touch($rPath, $rSec);
	}

	private static function markedWithinTtl(string $rName, int $rNow): bool {
		$rAt = self::markedAt($rName);
		return $rAt !== null && $rAt >= intdiv($rNow, 1000) - self::TTL - 1;
	}

	/** The second a mark holds, or null without one. */
	private static function markedAt(string $rName): ?int {
		$rPath = self::markPath($rName);
		if ($rPath === null) {
			return null;
		}
		clearstatcache(true, $rPath);
		$rAt = @filemtime($rPath);
		return $rAt === false ? null : $rAt;
	}

	private static function markPath(string $rName): ?string {
		$rSocket = ClusterBus::socket();
		return $rSocket === null ? null : dirname($rSocket) . '/' . $rName;
	}

	private static function insert(string $rNode, string $rNonce): bool {
		try {
			return (bool) self::db()->query('INSERT INTO `cluster_nonces` (`node`, `nonce`, `exp`) VALUES (?, ?, ?);', $rNode, $rNonce, ClusterClock::now() + self::TTL);
		} catch (\Throwable) {
			return false; // duplicate key
		}
	}
}
