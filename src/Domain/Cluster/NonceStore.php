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
 * - The bus marks the second it takes a claim (BUS_MARK), before it runs the
 *   claim. Without the bus, a request stamped before the end of that second
 *   plus LEAD_MS is refused: the bus may hold it. A mark from this second or
 *   the one before means the bus may be taking claims right now, so the
 *   refusal then runs to the end of this second plus LEAD_MS. While other
 *   workers still reach the bus, this worker therefore refuses until it
 *   reaches the bus too.
 *
 * Those refusals, and the first rule's, are not replays: claim() reports the
 * wait after which a request stamped anew can pass.
 *
 * The marks are files beside the bus socket, on disk, so they outlive a
 * reboot. A mark only moves forward, unless it is more than a second ahead
 * of the clock (the clock stepped back). On the bus, nonces live in sorted
 * sets without a TTL, which the bus's volatile-ttl policy never evicts; they
 * are bounded by authenticated traffic alone.
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

	/** Milliseconds a refusal's wait adds past the first stamp that can pass. */
	public const RETRY_MARGIN_MS = 50;

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

	/**
	 * Record a nonce; false when it was already used (a replay), or when MAIN
	 * cannot vouch that it was not (see above).
	 *
	 * @param ?int $rTsMs The request's X-XCVM-Ts, MAIN ms; null for a value
	 *                    MAIN made itself (the re-key minute, a used challenge).
	 * @param ?int $rRetryMs Set on a refusal that is not a replay: the wait,
	 *                       in ms, after which a request stamped anew can
	 *                       pass. Null otherwise.
	 */
	public static function claim(string $rNode, string $rNonce, ?int $rTsMs = null, ?int &$rRetryMs = null): bool {
		$rRetryMs = null;
		$rNow = ClusterClock::nowMs();
		if (ClusterBus::client() === null) {
			return self::claimWithoutBus($rNode, $rNonce, $rTsMs, $rNow, $rRetryMs);
		}
		// Marked first: a worker without the bus never reads an older second
		// than a claim the bus holds.
		if (!self::mark(self::BUS_MARK, $rNow)) {
			return false;
		}
		$rOut = ClusterBus::script(self::CLAIM_LUA, ['nonce:' . $rNode, 'nonces_since'], [$rNow, $rNow + self::TTL * 1000, bin2hex($rNonce)]);
		if (!is_array($rOut) || count($rOut) !== 2) {
			return self::claimWithoutBus($rNode, $rNonce, $rTsMs, $rNow, $rRetryMs);
		}
		$rSince = (int) $rOut[1];
		if ((int) $rOut[0] !== 1) {
			return false;
		}
		if ($rTsMs !== null && $rTsMs <= $rSince + self::LEAD_MS) {
			$rRetryMs = self::waitFor($rSince + self::LEAD_MS + 1, $rNow);
			return false;
		}
		if ($rNow - $rSince < self::TTL * 1000 || self::markedWithinTtl(self::SQL_MARK, $rNow) || ($rTsMs !== null && $rTsMs > $rNow + self::LEAD_MS)) {
			return self::insert($rNode, $rNonce);
		}
		return true;
	}

	/**
	 * Keep a value MAIN hands out (a `challenge`) for consume() to take once
	 * within TTL. In MySQL, even with the bus: `GET challenge` is
	 * unauthenticated, and on the bus a flood of values would push out the
	 * keys that must stay there (volatile-ttl).
	 */
	public static function issue(string $rNode, string $rNonce): void {
		// Without the bus, marked: a bus that comes back records the value's
		// use in MySQL too, where a worker without the bus looks.
		if (ClusterBus::client() !== null || self::markSql(ClusterClock::nowMs())) {
			self::insert($rNode, $rNonce);
		}
	}

	/**
	 * Consume a value issue() handed out: true once, while it is live. Atomic
	 * without affected-row counts: the consumption is itself a claim under a
	 * sibling key, so of two concurrent callers exactly one wins.
	 */
	public static function consume(string $rNode, string $rNonce): bool {
		self::db()->query('SELECT `exp` FROM `cluster_nonces` WHERE `node` = ? AND `nonce` = ? AND `exp` >= ?;', $rNode, $rNonce, ClusterClock::now());
		if (self::db()->num_rows() === 0) {
			return false;
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
	private static function claimWithoutBus(string $rNode, string $rNonce, ?int $rTsMs, int $rNow, ?int &$rRetryMs): bool {
		$rBusAt = self::markedAt(self::BUS_MARK);
		if ($rTsMs !== null && $rBusAt !== null) {
			// Marked this second or the one before (or ahead): the bus may be
			// taking claims right now.
			$rSec = intdiv($rNow, 1000);
			$rPass = (($rBusAt >= $rSec - 1 ? $rSec : $rBusAt) + 1) * 1000 + self::LEAD_MS;
			if ($rTsMs < $rPass) {
				$rRetryMs = self::waitFor($rPass, $rNow);
				return false;
			}
		}
		return self::markSql($rNow) && self::insert($rNode, $rNonce);
	}

	/** The wait, from now, after which a request stamped anew is stamped at or past $rPass. */
	private static function waitFor(int $rPass, int $rNow): int {
		return max(0, $rPass - $rNow) + self::RETRY_MARGIN_MS;
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

	/**
	 * Record the current second in a mark, at most one write a second; false
	 * when it cannot be written. A mark never moves back (a worker that read
	 * the clock a second earlier writes late), unless it is more than a second
	 * ahead: the clock stepped back.
	 */
	private static function mark(string $rName, int $rNow): bool {
		$rPath = self::markPath($rName);
		if ($rPath === null) {
			return true;
		}
		$rSec = intdiv($rNow, 1000);
		$rAt = self::markedAt($rName);
		return ($rAt !== null && $rAt >= $rSec && $rAt <= $rSec + 1) || @touch($rPath, $rSec);
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
