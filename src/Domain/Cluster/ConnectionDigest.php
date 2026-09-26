<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Config\SettingsManager;
use XcVm\Infrastructure\Database\DatabaseAware;
use XcVm\Infrastructure\Redis\RedisManager;

/**
 * A node's open connections in one short value (cluster plan, Phase 6,
 * "Connection digest"): the agent sends its registry's in every heartbeat, and
 * MAIN compares it with its own store for that node. A drift that outlives
 * the events in flight (two checks in a row) makes MAIN ask the node for a
 * `conn_snapshot` (ConnectionSnapshot).
 *
 * ```text
 * {count, users, xor64}
 * count   open connections (hls_end not set)
 * users   distinct owners: "u:<user_id>", or "h:<hmac_id>:<hmac_identifier>"
 * xor64   XOR of the first 8 bytes of SHA-256(uuid "\n" owner), 16 hex digits
 * ```
 *
 * The Go agent computes the same value (clusteragent.Digest); both sides pin
 * one vector in their tests.
 */
final class ConnectionDigest {
	use DatabaseAware;

	/** Least time between two checks of one node (ms). */
	public const EVERY_MS = 4000;

	/** Checks in a row that must disagree before MAIN asks for a snapshot. */
	public const MISSES = 2;

	/** Least time between two snapshot requests to one node (ms). */
	public const COOLDOWN_MS = 30000;

	private static ?string $rDir = null;

	private static ?int $rEvery = null;

	/** Tests: another state directory and check interval; null restores the defaults. */
	public static function useState(?string $rDir, ?int $rEveryMs = null): void {
		self::$rDir = $rDir;
		self::$rEvery = $rEveryMs;
	}

	/**
	 * @param iterable<array<string, mixed>> $rRecords
	 * @return array{count: int, users: int, xor64: string}
	 */
	public static function of(iterable $rRecords): array {
		$rCount = 0;
		$rUsers = [];
		$rXor = str_repeat("\0", 8);
		foreach ($rRecords as $rRecord) {
			if (!is_array($rRecord) || !empty($rRecord['hls_end']) || !isset($rRecord['uuid'])) {
				continue;
			}
			$rOwner = self::owner($rRecord);
			$rCount++;
			$rUsers[$rOwner] = true;
			$rXor ^= substr(hash('sha256', $rRecord['uuid'] . "\n" . $rOwner, true), 0, 8);
		}
		return ['count' => $rCount, 'users' => count($rUsers), 'xor64' => bin2hex($rXor)];
	}

	/** @param array<string, mixed> $rRecord */
	public static function owner(array $rRecord): string {
		if (!empty($rRecord['user_id'])) {
			return 'u:' . intval($rRecord['user_id']);
		}
		return 'h:' . intval($rRecord['hmac_id'] ?? 0) . ':' . strval($rRecord['hmac_identifier'] ?? '');
	}

	/**
	 * The node's open connections in MAIN's store, by uuid.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function stored(int $rServerID): array {
		$rOut = [];
		if (SettingsManager::get('redis_handler')) {
			$rRedis = RedisManager::instance();
			if (!$rRedis instanceof \Redis) {
				throw new \RuntimeException('redis unavailable');
			}
			$rKeys = $rRedis->zRangeByScore('SERVER#' . $rServerID, '-inf', '+inf');
			if (!is_array($rKeys)) {
				throw new \RuntimeException('redis unavailable');
			}
			foreach (array_chunk($rKeys, 1000) as $rChunk) {
				$rData = $rRedis->mGet($rChunk);
				foreach (is_array($rData) ? $rData : [] as $rRaw) {
					$rRecord = is_string($rRaw) ? igbinary_unserialize($rRaw) : null;
					if (is_array($rRecord) && isset($rRecord['uuid']) && (int) ($rRecord['server_id'] ?? 0) === $rServerID) {
						$rOut[(string) $rRecord['uuid']] = $rRecord;
					}
				}
			}
			return $rOut;
		}
		$rDb = self::db();
		$rDb->query('SELECT `uuid`, `user_id`, `hmac_id`, `hmac_identifier`, `hls_end` FROM `lines_live` WHERE `server_id` = ? AND `hls_end` = 0;', $rServerID);
		foreach ($rDb->get_rows() as $rRow) {
			if ($rRow['uuid'] !== null && $rRow['uuid'] !== '') {
				$rOut[(string) $rRow['uuid']] = $rRow;
			}
		}
		return $rOut;
	}

	/**
	 * A heartbeat's digest against MAIN's store: whether MAIN wants the node's
	 * snapshot now. Checked at most every EVERY_MS per node; a disagreement
	 * must hold for MISSES checks in a row (the events in flight catch up in
	 * well under that), and a node is asked at most once per COOLDOWN_MS.
	 */
	public static function check(int $rServerID, mixed $rDigest): bool {
		if (!is_array($rDigest) || !is_int($rDigest['count'] ?? null) || !is_int($rDigest['users'] ?? null) || !is_string($rDigest['xor64'] ?? null)) {
			return false;
		}
		$rNow = ClusterClock::nowMs();
		$rFile = self::dir() . $rServerID . '.json';
		$rState = json_decode((string) @file_get_contents($rFile), true);
		$rState = is_array($rState) ? $rState + ['at' => 0, 'miss' => 0, 'asked' => 0] : ['at' => 0, 'miss' => 0, 'asked' => 0];
		if ($rNow - (int) $rState['at'] < (self::$rEvery ?? self::EVERY_MS)) {
			return false;
		}
		$rOurs = self::of(self::stored($rServerID));
		$rSame = $rOurs['count'] === $rDigest['count'] && $rOurs['users'] === $rDigest['users'] && hash_equals($rOurs['xor64'], strtolower($rDigest['xor64']));
		$rState['at'] = $rNow;
		$rState['miss'] = $rSame ? 0 : (int) $rState['miss'] + 1;
		$rWant = $rState['miss'] >= self::MISSES && $rNow - (int) $rState['asked'] >= self::COOLDOWN_MS;
		if ($rWant) {
			$rState['miss'] = 0;
			$rState['asked'] = $rNow;
		}
		if (!is_dir(self::dir())) {
			@mkdir(self::dir(), 0750, true);
		}
		@file_put_contents($rFile, json_encode($rState), LOCK_EX);
		return $rWant;
	}

	private static function dir(): string {
		return self::$rDir ?? ((defined('TMP_PATH') ? TMP_PATH : sys_get_temp_dir() . '/') . 'cluster_digest/');
	}
}
