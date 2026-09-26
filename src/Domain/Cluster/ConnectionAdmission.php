<?php

namespace XcVm\Domain\Cluster;

use XcVm\Infrastructure\Database\DatabaseAware;
use XcVm\Infrastructure\Redis\RedisManager;
use XcVm\Streaming\Protection\ConnectionLimiter;

/**
 * Admission when MAIN mints a stream token (plan, Phase 6, "Global
 * max_connections and kills"): the line's limit is applied before the viewer
 * reaches a node, instead of a second after it opened there.
 *
 * It applies to targets whose agent holds the viewers (the node that records
 * the connection, the originator behind a proxy, is active in mode ≥ 1 with
 * CONNECTIONS on) and to limited lines. Thumbnails and subtitles are not
 * admitted; auth.php does not call it for them.
 *
 * 1. The viewer's uuid is reserved for the identity (the line, or an HMAC
 *    identity) for the token's life plus 10 s, and the identity's other
 *    reservations still in flight are counted. Insert-then-count needs no
 *    lock: of two concurrent mints at least one sees the other.
 *    - On the cluster bus when it runs (ClusterBus): a Lua script on
 *      `RESV#<identity>` (score = expiry), in either store mode.
 *    - Without it, Redis mode: the same script on the shared Redis.
 *    - Without it, MySQL mode: `cluster_reservations` (migration 032), keyed
 *      by the uuid.
 * 2. The identity's open connections are cut, in ConnectionLimiter's order
 *    (the requesting device first, oldest first), to leave room for this
 *    viewer and the other reservations. The viewer is never evicted: it is not
 *    open yet. Evictions on CONNECTIONS nodes go out as conn.close / conn.drop
 *    commands, as every close MAIN makes does.
 * 3. When the node reports the connection (ConnectionIngest), the reservation
 *    is released. The node's conn.limit still follows, and is the re-check that
 *    settles a race between two nodes.
 *
 * Admission never refuses a viewer and never fails the request: when the
 * store or the registry cannot be read it does nothing, and conn.limit
 * enforces the limit once the viewer opens.
 */
final class ConnectionAdmission {
	use DatabaseAware;

	/** Seconds a reservation outlives the token's own life (create_expiration). */
	public const PAD_SEC = 10;

	private const LUA = <<<'LUA'
redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', ARGV[1])
redis.call('ZADD', KEYS[1], ARGV[2], ARGV[3])
redis.call('EXPIRE', KEYS[1], ARGV[4])
return redis.call('ZCARD', KEYS[1]) - 1
LUA;

	/** @var null|callable(?int, int, ?int, string, ?string, ?string): mixed */
	private static $rEnforce = null;

	/** @var null|callable(): int */
	private static $rClock = null;

	/**
	 * Admit the viewer a token is being minted for. Returns whether admission
	 * applied (the viewer was reserved and the limit applied).
	 *
	 * @param array<string, mixed> $rSettings
	 * @param array<string, mixed> $rTokenData the token auth.php is about to mint
	 */
	public static function admit(array $rSettings, array $rTokenData, ?string $rIP, ?string $rUserAgent): bool {
		try {
			$rUser = is_array($rTokenData['user_info'] ?? null) ? $rTokenData['user_info'] : [];
			$rMax = (int) ($rUser['max_connections'] ?? 0);
			$rUUID = (string) ($rTokenData['uuid'] ?? '');
			if ($rMax <= 0 || empty($rSettings['cluster_api_enabled']) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $rUUID)) {
				return false;
			}
			$rNode = self::nodeOf($rTokenData);
			if (!self::takesConnections($rNode)) {
				return false;
			}
			$rHMAC = (int) ($rTokenData['hmac_id'] ?? 0);
			$rIdentifier = (string) ($rTokenData['identifier'] ?? '');
			$rLineID = (int) ($rUser['id'] ?? 0);
			if ($rHMAC === 0 && $rLineID === 0) {
				return false;
			}
			$rIdentity = $rHMAC !== 0 ? $rHMAC . '_' . $rIdentifier : (string) $rLineID;
			$rTtl = max(1, (int) ($rSettings['create_expiration'] ?? 0) ?: 5) + self::PAD_SEC;
			$rStreamID = (int) ($rTokenData['stream_id'] ?? $rTokenData['stream'] ?? 0);
			$rOthers = self::reserve(!empty($rSettings['redis_handler']), $rIdentity, $rUUID, $rTtl, $rNode, $rStreamID);
			if ($rOthers === null) {
				return false;
			}
			// Room left for open connections: the limit, less this viewer and
			// the others still on their way to a node.
			$rRoom = max(0, $rMax - $rOthers - 1);
			$rEnforce = self::$rEnforce ?? [ConnectionLimiter::class, 'closeConnections'];
			if ($rHMAC !== 0) {
				$rEnforce(null, $rRoom, $rHMAC, $rIdentifier, $rIP, $rUserAgent);
			} else {
				if (!empty($rUser['pair_id'])) {
					$rEnforce((int) $rUser['pair_id'], $rRoom, null, '', $rIP, $rUserAgent);
				}
				$rEnforce($rLineID, $rRoom, null, '', $rIP, $rUserAgent);
			}
			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	/** The node that records the viewer: the originator behind a proxy, else the redirect target. */
	public static function nodeOf(array $rTokenData): int {
		$rTarget = is_array($rTokenData['channel_info'] ?? null) ? $rTokenData['channel_info'] : $rTokenData;
		return (int) (($rTarget['originator_id'] ?? null) ?: ($rTarget['redirect_id'] ?? 0));
	}

	/** Does this node's agent hold its viewers (active, mode ≥ 1, CONNECTIONS on)? */
	public static function takesConnections(int $rServerID): bool {
		if ($rServerID <= 0) {
			return false;
		}
		$rNode = NodeRegistry::byServer($rServerID);
		return $rNode !== null && $rNode['state'] === 'active' && (int) $rNode['mode'] >= 1
			&& ((int) $rNode['flows'] & NodeRegistry::FLOW_CONNECTIONS) !== 0;
	}

	/**
	 * Reserve the uuid for the identity and count the identity's other live
	 * reservations; null when the store cannot be reached.
	 */
	public static function reserve(bool $rRedisMode, string $rIdentity, string $rUUID, int $rTtl, int $rServerID = 0, int $rStreamID = 0): ?int {
		$rNow = self::now();
		// The cluster bus when MAIN runs it (plan: reservations live there),
		// whatever the store mode; else the shared Redis or the table.
		$rBus = ClusterBus::client();
		if ($rBus !== null) {
			try {
				$rOthers = $rBus->eval(self::LUA, ['RESV#' . $rIdentity, $rNow, $rNow + $rTtl, $rUUID, $rTtl], 1);
				if (is_int($rOthers)) {
					return max(0, $rOthers);
				}
			} catch (\Throwable) {
				// Fall through to the store.
			}
		}
		if ($rRedisMode) {
			$rRedis = RedisManager::instance();
			if (!$rRedis instanceof \Redis) {
				return null;
			}
			$rOthers = $rRedis->eval(self::LUA, ['RESV#' . $rIdentity, $rNow, $rNow + $rTtl, $rUUID, $rTtl], 1);
			return is_int($rOthers) ? max(0, $rOthers) : null;
		}
		if (strlen($rUUID) > 32) {
			return null; // the table's id is char(32), the size auth.php mints
		}
		$db = self::db();
		$db->query('DELETE FROM `cluster_reservations` WHERE `exp` < ?;', $rNow);
		$db->query('REPLACE INTO `cluster_reservations` (`id`, `identity`, `server_id`, `stream_id`, `created_at`, `exp`) VALUES (?, ?, ?, ?, ?, ?);', $rUUID, $rIdentity, $rServerID, $rStreamID ?: null, $rNow, $rNow + $rTtl);
		if (!$db->query('SELECT COUNT(*) AS `n` FROM `cluster_reservations` WHERE `identity` = ? AND `id` <> ? AND `exp` >= ?;', $rIdentity, $rUUID, $rNow)) {
			return null;
		}
		return (int) ($db->get_row()['n'] ?? 0);
	}

	/** The node reported the connection: it is open now, no longer reserved. */
	public static function release(bool $rRedisMode, string $rIdentity, string $rUUID): void {
		try {
			ClusterBus::client()?->zRem('RESV#' . $rIdentity, $rUUID);
			if ($rRedisMode) {
				$rRedis = RedisManager::instance();
				if ($rRedis instanceof \Redis) {
					$rRedis->zRem('RESV#' . $rIdentity, $rUUID);
				}
				return;
			}
			self::db()->query('DELETE FROM `cluster_reservations` WHERE `id` = ?;', $rUUID);
		} catch (\Throwable) {
			// It expires on its own.
		}
	}

	/**
	 * Tests: the enforcer (ConnectionLimiter::closeConnections's arguments)
	 * and the clock.
	 */
	public static function useEnforcer(?callable $rEnforce, ?callable $rClock = null): void {
		self::$rEnforce = $rEnforce;
		self::$rClock = $rClock;
	}

	private static function now(): int {
		return self::$rClock !== null ? (int) (self::$rClock)() : time();
	}
}
