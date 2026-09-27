<?php

namespace XcVm\Domain\Cluster;

/**
 * What ClusterApi's request authentication reads from MySQL, kept on the
 * cluster bus (plan, section 8, "MAIN capacity": heartbeats and hot ops do
 * not hit MySQL): the node's `cluster_nodes` row and the sealed record of the
 * epoch the request names (`cluster_node_epochs.record` and `exp`, what the
 * extension opens into the session keys; never the sealed token). A request
 * whose handler needs no MySQL then asks it nothing.
 *
 * - `cl:auth:<uuid>` holds the row and `cl:auth:<uuid>:<epoch>` the record,
 *   each as `<sid>:<version>:<filled at ms>:<json>`, for TTL_MS.
 * - `cl:auth_ver` (a field per server id, no TTL) holds each node's version.
 *   An entry counts only while it carries its node's current version.
 * - Every writer of what load() reads calls forget() after its MySQL write:
 *   NodeRegistry (update(), startEnrolment(), revoke()) and TokenService
 *   (issue(), rekey()). It raises the node's version, so the next request
 *   reads MySQL, and `cl:auth_seq`, so a fill of a row read before the write
 *   goes nowhere: a fill is kept only while that sequence is the one read
 *   before MySQL was. The sequence starts at a random value, so a bus that
 *   restarted or was flushed in between never matches either.
 * - The columns written without forget() (LAGGING: the heartbeat flush's,
 *   the event cursors, the command high-water) may be up to TTL_MS behind in
 *   an entry. What needs them current reads MySQL (hello's cursors,
 *   EventIngest's cursor, CommandBus::enqueue()).
 * - A writer that finds the bus's socket but cannot reach the bus marks the
 *   second (STALE_MARK, beside the socket): nothing filled before the end of
 *   the second after it counts.
 *
 * Without the bus, or when a script fails, every request reads MySQL, as
 * before. An unknown node, or an epoch MySQL does not hold (live), is never
 * kept: each such request reads MySQL.
 */
final class NodeAuthCache {
	/** Milliseconds an entry lives on the bus; a write that bypasses the writers above is seen within it. */
	public const TTL_MS = 30000;

	/** Bus: `cl:auth:<uuid>` (the node's row) and `cl:auth:<uuid>:<epoch>` (an epoch's record). */
	public const PREFIX = 'cl:auth:';

	/** Bus: server id => the version of the node's entries. */
	public const KEY_VERSIONS = 'cl:auth_ver';

	/** Bus: raised by every forget(); a fill is kept only while it is the one read before MySQL was. */
	public const KEY_SEQ = 'cl:auth_seq';

	/** The second a writer could not reach the bus (a file's mtime, beside the socket). */
	public const STALE_MARK = 'auth.stale';

	/** Columns written without forget(): an entry's may be up to TTL_MS behind MySQL. */
	public const LAGGING = ['last_seen_at', 'clock_offset_ms', 'root_ready', 'updated_at', 'useq_p0', 'useq_p1', 'cmd_seq'];

	/** Columns never put on the bus: no request reads them. */
	private const SKIPPED = ['row_mac', 'attest'];

	/**
	 * KEYS cl:auth:<uuid>, cl:auth:<uuid>:<epoch>, cl:auth_ver, cl:auth_seq;
	 * ARGV a random start for the sequence => {sequence, row entry, epoch
	 * entry, the version of the node they name ('' none)}. Never nil.
	 */
	private const READ_LUA = <<<'LUA'
		local seq = redis.call('GET', KEYS[4])
		if not seq then
			seq = ARGV[1]
			redis.call('SET', KEYS[4], seq)
		end
		local node = redis.call('GET', KEYS[1]) or ''
		local ep = redis.call('GET', KEYS[2]) or ''
		local sid = string.match(node, '^(%d+):') or string.match(ep, '^(%d+):')
		local ver = ''
		if sid then
			ver = redis.call('HGET', KEYS[3], sid) or '0'
		end
		return {seq, node, ep, ver}
		LUA;

	/**
	 * KEYS as READ_LUA; ARGV the sequence read before MySQL, sid, filled at
	 * ms, TTL ms, row JSON ('' keep), epoch JSON ('' none) => 1 kept, 0 a
	 * writer came in between.
	 */
	private const FILL_LUA = <<<'LUA'
		if redis.call('GET', KEYS[4]) ~= ARGV[1] then
			return 0
		end
		local pre = ARGV[2] .. ':' .. (redis.call('HGET', KEYS[3], ARGV[2]) or '0') .. ':' .. ARGV[3] .. ':'
		if ARGV[5] ~= '' then
			redis.call('SET', KEYS[1], pre .. ARGV[5], 'PX', ARGV[4])
		end
		if ARGV[6] ~= '' then
			redis.call('SET', KEYS[2], pre .. ARGV[6], 'PX', ARGV[4])
		end
		return 1
		LUA;

	/** KEYS cl:auth_ver, cl:auth_seq; ARGV sid, a random start for the sequence. */
	private const FORGET_LUA = <<<'LUA'
		redis.call('HINCRBY', KEYS[1], ARGV[1], 1)
		if not redis.call('GET', KEYS[2]) then
			redis.call('SET', KEYS[2], ARGV[2])
		end
		redis.call('INCR', KEYS[2])
		return 1
		LUA;

	/**
	 * The node a request names and the epoch it authenticates with, as
	 * NodeRegistry::byUuid() and TokenService::epoch() read them: [the row,
	 * or null for an unknown node; {record, exp} of the live epoch, or null].
	 * The epoch is not read for a revoked node, as before. From the bus when
	 * it holds them for the node's version, else from MySQL, then kept there.
	 *
	 * @return array{0: array<string, mixed>|null, 1: array{record: string, exp: int}|null}
	 */
	public static function load(string $rUuid, int $rEpoch): array {
		$rNow = ClusterClock::nowMs();
		$rKeys = [self::PREFIX . $rUuid, self::PREFIX . $rUuid . ':' . $rEpoch, self::KEY_VERSIONS, self::KEY_SEQ];
		$rRead = ClusterBus::script(self::READ_LUA, $rKeys, [self::seed()]);
		$rBus = is_array($rRead) && count($rRead) === 4 ? array_map('strval', array_values($rRead)) : null;
		// Nothing filled before the end of the second after a writer's mark counts.
		$rMark = $rBus === null ? null : ClusterBus::markedAt(self::STALE_MARK);
		$rFloor = $rMark === null ? 0 : ($rMark + 2) * 1000;

		$rNode = $rBus === null ? null : self::entry($rBus[1], $rBus[3], $rFloor);
		$rNode = $rNode === null ? null : self::decodeRow($rNode[1]);
		$rFillNode = '';
		if ($rNode === null || strcasecmp((string) ($rNode['node_uuid'] ?? ''), $rUuid) !== 0) {
			$rNode = NodeRegistry::byUuid($rUuid);
			if ($rNode === null) {
				return [null, null];
			}
			$rFillNode = self::encodeRow($rNode);
			foreach (self::SKIPPED as $rColumn) {
				unset($rNode[$rColumn]);
			}
		}
		$rServerID = (int) $rNode['server_id'];

		$rRow = null;
		$rFillEpoch = '';
		if ($rNode['state'] !== 'revoked') {
			$rEntry = $rBus === null ? null : self::entry($rBus[2], $rBus[3], $rFloor);
			$rRow = $rEntry === null || $rEntry[0] !== $rServerID ? null : self::decodeEpoch($rEntry[1]);
			if ($rRow !== null && $rRow['exp'] <= ClusterClock::now()) {
				$rRow = null; // as MySQL: `exp` > now
			}
			if ($rRow === null) {
				$rLive = TokenService::epoch($rServerID, $rEpoch);
				$rRow = $rLive === null ? null : ['record' => (string) $rLive['record'], 'exp' => (int) $rLive['exp']];
				$rFillEpoch = $rRow === null ? '' : (string) json_encode(['record' => base64_encode($rRow['record']), 'exp' => $rRow['exp']]);
			}
		}

		if ($rBus !== null && ($rFillNode !== '' || $rFillEpoch !== '') && $rNow >= $rFloor) {
			ClusterBus::script(self::FILL_LUA, $rKeys, [$rBus[0], $rServerID, $rNow, self::TTL_MS, $rFillNode, $rFillEpoch]);
		}
		return [$rNode, $rRow];
	}

	/**
	 * A writer changed what load() reads of a node (its row, or one of its
	 * epochs): called after the MySQL write, so that the node's next request
	 * reads MySQL. Where there is no bus socket there is nothing to drop (a
	 * bus that starts is empty); where the bus cannot be reached, the second
	 * is marked instead (STALE_MARK).
	 */
	public static function forget(int $rServerID): void {
		$rSocket = ClusterBus::socket();
		if ($rSocket === null || !file_exists($rSocket)) {
			return;
		}
		if (ClusterBus::script(self::FORGET_LUA, [self::KEY_VERSIONS, self::KEY_SEQ], [$rServerID, self::seed()]) !== 1) {
			ClusterBus::mark(self::STALE_MARK, ClusterClock::nowMs());
		}
	}

	/**
	 * Does a write of these `cluster_nodes` columns change what an entry
	 * holds current? Not when it writes only LAGGING ones.
	 *
	 * @param list<string> $rColumns
	 */
	public static function changes(array $rColumns): bool {
		return array_diff($rColumns, self::LAGGING) !== [];
	}

	/**
	 * An entry's server id and JSON, when it carries its node's version and
	 * was filled at or after $rFloor (ms); else null.
	 *
	 * @return array{0: int, 1: string}|null
	 */
	private static function entry(string $rValue, string $rVersion, int $rFloor): ?array {
		if ($rValue === '' || !preg_match('/^(\d+):(\d+):(\d+):(.+)$/s', $rValue, $rM)) {
			return null;
		}
		return $rM[2] === $rVersion && (int) $rM[3] >= $rFloor ? [(int) $rM[1], $rM[4]] : null;
	}

	/**
	 * A row as JSON: binary values (the node's keys) as {"b64": …}, so they
	 * come back byte for byte; the SKIPPED columns left out.
	 *
	 * @param array<string, mixed> $rRow
	 */
	private static function encodeRow(array $rRow): string {
		$rOut = [];
		foreach ($rRow as $rColumn => $rValue) {
			if (!in_array($rColumn, self::SKIPPED, true)) {
				$rOut[$rColumn] = is_string($rValue) && preg_match('//u', $rValue) !== 1 ? ['b64' => base64_encode($rValue)] : $rValue;
			}
		}
		return (string) json_encode($rOut, JSON_UNESCAPED_SLASHES);
	}

	/** @return array<string, mixed>|null */
	private static function decodeRow(string $rJson): ?array {
		$rRow = json_decode($rJson, true);
		if (!is_array($rRow) || !isset($rRow['server_id'], $rRow['state'])) {
			return null;
		}
		foreach ($rRow as $rColumn => $rValue) {
			if (is_array($rValue)) {
				$rBytes = base64_decode((string) ($rValue['b64'] ?? ''), true);
				if ($rBytes === false) {
					return null;
				}
				$rRow[$rColumn] = $rBytes;
			}
		}
		return $rRow;
	}

	/** @return array{record: string, exp: int}|null */
	private static function decodeEpoch(string $rJson): ?array {
		$rRow = json_decode($rJson, true);
		$rRecord = is_array($rRow) && is_string($rRow['record'] ?? null) ? base64_decode($rRow['record'], true) : false;
		return $rRecord === false || !is_int($rRow['exp'] ?? null) ? null : ['record' => $rRecord, 'exp' => $rRow['exp']];
	}

	/** A random start for `cl:auth_seq`, so that a new bus's never matches an old one's. */
	private static function seed(): string {
		return (string) random_int(1, 1 << 52);
	}
}
