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
 * 3. The token carries the admission as its `adm` claim {exp, sid}: when the
 *    reservation expires (MAIN's unix seconds) and the node it was made for.
 *    The reservation's id is the token's own uuid. The token is sealed with
 *    MAIN's stream secret, so the node trusts the claim and admits the viewer
 *    without asking MAIN.
 * 4. When the node reports the connection (ConnectionIngest), the reservation
 *    is released. The node's conn.limit still follows, and is the re-check that
 *    settles a race between two nodes.
 *
 * A node whose viewer's token has no claim (minted before this, or while
 * admission could not apply, or expired) asks MAIN with the `conn_admit` op
 * (forNode()): the same reservation for the authenticated node, with the line
 * read on MAIN, and the cut queued for MAIN's 1 s loop.
 *
 * Admission never refuses a valid viewer and never fails the request: when the
 * store or the registry cannot be read it does nothing, and conn.limit
 * enforces the limit once the viewer opens. conn_admit refuses only a line
 * auth.php would refuse (unknown, banned, disabled, expired) or an unknown or
 * disabled HMAC key.
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

	/** @var null|callable(?int, int, ?int, string, ?string, ?string, ?string): mixed */
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
		return self::claim($rSettings, $rTokenData, $rIP, $rUserAgent) !== null;
	}

	/**
	 * admit(), and the token data to mint: with its `adm` claim when admission
	 * applied (auth.php's viewer mint sites).
	 *
	 * @param array<string, mixed> $rSettings
	 * @param array<string, mixed> $rTokenData
	 * @return array<string, mixed>
	 */
	public static function admitToken(array $rSettings, array $rTokenData, ?string $rIP, ?string $rUserAgent): array {
		$rClaim = self::claim($rSettings, $rTokenData, $rIP, $rUserAgent);
		if ($rClaim !== null) {
			$rTokenData['adm'] = $rClaim;
		}
		return $rTokenData;
	}

	/**
	 * Admit the viewer a token is being minted for; its `adm` claim when
	 * admission applied, else null.
	 *
	 * @param array<string, mixed> $rSettings
	 * @param array<string, mixed> $rTokenData
	 * @return array{exp: int, sid: int}|null
	 */
	public static function claim(array $rSettings, array $rTokenData, ?string $rIP, ?string $rUserAgent): ?array {
		try {
			$rUser = is_array($rTokenData['user_info'] ?? null) ? $rTokenData['user_info'] : [];
			$rMax = (int) ($rUser['max_connections'] ?? 0);
			$rUUID = (string) ($rTokenData['uuid'] ?? '');
			if ($rMax <= 0 || empty($rSettings['cluster_api_enabled']) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $rUUID)) {
				return null;
			}
			$rNode = self::nodeOf($rTokenData);
			if (!self::takesConnections($rNode)) {
				return null;
			}
			$rHMAC = (int) ($rTokenData['hmac_id'] ?? 0);
			$rIdentifier = (string) ($rTokenData['identifier'] ?? '');
			$rLineID = (int) ($rUser['id'] ?? 0);
			if ($rHMAC === 0 && $rLineID === 0) {
				return null;
			}
			$rIdentity = $rHMAC !== 0 ? $rHMAC . '_' . $rIdentifier : (string) $rLineID;
			$rTtl = self::ttl($rSettings);
			$rStreamID = (int) ($rTokenData['stream_id'] ?? $rTokenData['stream'] ?? 0);
			$rOthers = self::reserve(!empty($rSettings['redis_handler']), $rIdentity, $rUUID, $rTtl, $rNode, $rStreamID);
			if ($rOthers === null) {
				return null;
			}
			// Room left for open connections: the limit, less this viewer and
			// the others still on their way to a node.
			$rRoom = max(0, $rMax - $rOthers - 1);
			self::enforce($rHMAC !== 0 ? null : $rLineID, (int) ($rUser['pair_id'] ?? 0), $rRoom, $rHMAC !== 0 ? $rHMAC : null, $rIdentifier, $rIP, $rUserAgent, $rUUID);
			return ['exp' => self::now() + $rTtl, 'sid' => $rNode];
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * `conn_admit`: admission for a viewer the node is about to record whose
	 * token carries no `adm` claim (or an expired one). The node is the
	 * authenticated one, and MAIN never takes a limit from it: the line is read
	 * here. The answer:
	 *
	 * - `{admit: true, exp}`: reserved for the node until exp (MAIN's unix
	 *   seconds), as at mint. The cut that leaves room for the viewer is queued
	 *   for MAIN's 1 s loop (ConnectionLimits), which has the legacy globals
	 *   ConnectionLimiter needs and this endpoint does not.
	 * - `{admit: false, exp: 0, reason}`: a line auth.php would refuse
	 *   (UNKNOWN_LINE, BANNED, DISABLED, EXPIRED), or an HMAC key that is
	 *   unknown or disabled (UNKNOWN_HMAC).
	 *
	 * A limited line is reserved; an unlimited one needs nothing. An HMAC
	 * identity is reserved but not cut: its limit is signed into the client's
	 * request and stored nowhere, so the node's conn.limit, which carries it,
	 * enforces it. A repeated uuid refreshes its own reservation, and the cut
	 * never evicts the viewer asking.
	 *
	 * @param array<string, mixed> $rSettings
	 * @param array<string, mixed> $rRequest {uuid, line_id | hmac_id + identifier, stream_id, ip, ua}
	 * @return array{admit: bool, exp: int, reason?: string}|null null for a malformed request
	 */
	public static function forNode(array $rSettings, int $rServerID, array $rRequest): ?array {
		$rUUID = $rRequest['uuid'] ?? null;
		$rLineID = $rRequest['line_id'] ?? null;
		$rHMAC = $rRequest['hmac_id'] ?? null;
		$rIdentifier = $rRequest['identifier'] ?? null;
		$rStreamID = $rRequest['stream_id'] ?? 0;
		$rIP = $rRequest['ip'] ?? '';
		$rUserAgent = $rRequest['ua'] ?? '';
		$rIsLine = is_int($rLineID) && $rLineID > 0 && $rHMAC === null;
		$rIsHMAC = is_int($rHMAC) && $rHMAC > 0 && is_string($rIdentifier) && strlen($rIdentifier) <= 255 && $rLineID === null;
		$rValid = is_string($rUUID) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $rUUID) && is_int($rStreamID) && $rStreamID >= 0 && is_string($rIP) && is_string($rUserAgent);
		if (!$rValid || $rIsLine === $rIsHMAC) {
			return null;
		}
		$rIP = substr($rIP, 0, 64);
		$rUserAgent = substr($rUserAgent, 0, 512);
		$rDb = self::db();
		if ($rIsLine) {
			if ($rDb->query('SELECT `max_connections`, `enabled`, `admin_enabled`, `exp_date` FROM `lines` WHERE `id` = ?;', $rLineID) === false) {
				throw new \RuntimeException('db');
			}
			$rLine = $rDb->num_rows() === 1 ? $rDb->get_row() : null;
			$rReason = match (true) {
				$rLine === null => 'UNKNOWN_LINE',
				(int) $rLine['admin_enabled'] === 0 => 'BANNED',
				(int) $rLine['enabled'] === 0 => 'DISABLED',
				$rLine['exp_date'] !== null && (int) $rLine['exp_date'] <= self::now() => 'EXPIRED',
				default => null,
			};
			if ($rReason !== null) {
				return ['admit' => false, 'exp' => 0, 'reason' => $rReason];
			}
			$rIdentity = (string) $rLineID;
			$rMax = (int) $rLine['max_connections'];
		} else {
			if ($rDb->query('SELECT `id` FROM `hmac_keys` WHERE `id` = ? AND `enabled` = 1;', $rHMAC) === false) {
				throw new \RuntimeException('db');
			}
			if ($rDb->num_rows() !== 1) {
				return ['admit' => false, 'exp' => 0, 'reason' => 'UNKNOWN_HMAC'];
			}
			$rIdentity = $rHMAC . '_' . $rIdentifier;
			$rMax = null;
		}
		$rTtl = self::ttl($rSettings);
		if ($rMax === null || $rMax > 0) {
			try {
				$rOthers = self::reserve(!empty($rSettings['redis_handler']), $rIdentity, $rUUID, $rTtl, $rServerID, $rStreamID);
			} catch (\Throwable) {
				$rOthers = null; // a store that cannot be reached refuses no one: conn.limit follows the open
			}
			if ($rOthers !== null && $rIsLine) {
				ConnectionLimits::queueAdmission($rServerID, $rUUID, (int) $rLineID, $rOthers, $rIP, $rUserAgent);
			}
		}
		return ['admit' => true, 'exp' => self::now() + $rTtl];
	}

	/**
	 * A conn_admit's cut, run by MAIN's loop (ConnectionLimits::drain): the
	 * line's open connections, and its pair's, down to what leaves room for
	 * the viewer and the `others` reservations that were in flight. The limit
	 * and pair are read from `lines` now. A viewer that opened meanwhile is
	 * already counted among the open ones, so it takes no room of its own; it
	 * is never cut.
	 */
	public static function cut(int $rLineID, int $rOthers, bool $rOpen, string $rIP, string $rUserAgent, string $rUUID): bool {
		$rDb = self::db();
		$rDb->query('SELECT `max_connections`, `pair_id` FROM `lines` WHERE `id` = ?;', $rLineID);
		if ($rDb->num_rows() !== 1) {
			return false;
		}
		$rLine = $rDb->get_row();
		$rMax = (int) $rLine['max_connections'];
		if ($rMax <= 0) {
			return true;
		}
		$rRoom = max(0, $rMax - $rOthers - ($rOpen ? 0 : 1));
		self::enforce($rLineID, (int) ($rLine['pair_id'] ?? 0), $rRoom, null, '', $rIP, $rUserAgent, $rUUID);
		return true;
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

	/** A reservation's life: the token's (create_expiration, 5 s by default) plus PAD_SEC. */
	private static function ttl(array $rSettings): int {
		return max(1, (int) ($rSettings['create_expiration'] ?? 0) ?: 5) + self::PAD_SEC;
	}

	/**
	 * Cut an identity's open connections to $rRoom: a line's pair first, then
	 * the line; or an HMAC identity. The viewer $rUUID is never cut.
	 */
	private static function enforce(?int $rLineID, int $rPairID, int $rRoom, ?int $rHMAC, string $rIdentifier, ?string $rIP, ?string $rUserAgent, string $rUUID): void {
		if ($rHMAC !== null) {
			self::limit(null, $rRoom, $rHMAC, $rIdentifier, $rIP, $rUserAgent, $rUUID);
			return;
		}
		if ($rPairID !== 0) {
			self::limit($rPairID, $rRoom, null, '', $rIP, $rUserAgent, $rUUID);
		}
		self::limit($rLineID, $rRoom, null, '', $rIP, $rUserAgent, $rUUID);
	}

	/**
	 * ConnectionLimiter::closeConnections (or the tests' enforcer), with the
	 * viewer's IP as REMOTE_ADDR, which the limiter reads to prefer the
	 * requesting device: in MAIN's loop there is none.
	 */
	private static function limit(?int $rLineID, int $rRoom, ?int $rHMAC, string $rIdentifier, ?string $rIP, ?string $rUserAgent, string $rUUID): void {
		if (self::$rEnforce !== null) {
			(self::$rEnforce)($rLineID, $rRoom, $rHMAC, $rIdentifier, $rIP, $rUserAgent, $rUUID);
			return;
		}
		$rWas = $_SERVER['REMOTE_ADDR'] ?? null;
		if ($rIP !== null && $rIP !== '') {
			$_SERVER['REMOTE_ADDR'] = $rIP;
		}
		try {
			ConnectionLimiter::closeConnections($rLineID, $rRoom, $rHMAC, $rIdentifier, $rIP, $rUserAgent, $rUUID);
		} finally {
			if ($rWas === null) {
				unset($_SERVER['REMOTE_ADDR']);
			} else {
				$_SERVER['REMOTE_ADDR'] = $rWas;
			}
		}
	}

	private static function now(): int {
		return self::$rClock !== null ? (int) (self::$rClock)() : ClusterClock::now();
	}
}
