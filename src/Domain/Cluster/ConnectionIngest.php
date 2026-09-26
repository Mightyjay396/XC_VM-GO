<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Infrastructure\Database\DatabaseAware;
use XcVm\Infrastructure\Redis\RedisManager;

/**
 * MAIN's side of a node's connection registry (cluster plan, Phase 6): the
 * node's agent mirrors every viewer it records as P0 events, and they land in
 * MAIN's store (Redis or `lines_live`, as redis_handler says) exactly as the
 * node's PHP used to write them, so the reaper, the limits and the admin read
 * what they always read.
 *
 * ```text
 * conn.upsert {record}   create, or update and re-open (hls_end 0) / end (hls_end 1)
 * conn.remove {uuid}     drop it from the store
 * ```
 *
 * A node writes only its own connections: `server_id` is always the sender,
 * the line identity is recomputed from the record's owner, and a uuid another
 * node holds is refused.
 */
final class ConnectionIngest {
	use DatabaseAware;

	/** The record keys a node may set. */
	public const KEYS = ['user_id', 'hmac_id', 'hmac_identifier', 'stream_id', 'proxy_id', 'user_agent', 'user_ip', 'container', 'pid', 'uuid', 'date_start', 'geoip_country_code', 'isp', 'external_device', 'hls_last_read', 'hls_end', 'on_demand'];

	/** The `lines_live` columns among them. */
	private const COLUMNS = ['user_id', 'hmac_id', 'hmac_identifier', 'stream_id', 'server_id', 'proxy_id', 'user_agent', 'user_ip', 'container', 'pid', 'uuid', 'date_start', 'geoip_country_code', 'isp', 'external_device', 'hls_last_read', 'hls_end'];

	/**
	 * Apply a node's conn.upsert. A connection that opened on the node is no
	 * longer reserved (ConnectionAdmission): it is counted as open from here.
	 *
	 * @param array<string, mixed> $rRecord
	 */
	public static function upsert(int $rServerID, array $rRecord): bool {
		$rOk = self::write($rServerID, $rRecord);
		if ($rOk) {
			$rUUID = (string) ($rRecord['uuid'] ?? '');
			$rIdentity = !empty($rRecord['user_id']) ? (string) (int) $rRecord['user_id'] : (int) ($rRecord['hmac_id'] ?? 0) . '_' . ($rRecord['hmac_identifier'] ?? '');
			ConnectionAdmission::release((bool) SettingsManager::get('redis_handler'), $rIdentity, $rUUID);
		}
		return $rOk;
	}

	/** @param array<string, mixed> $rRecord */
	private static function write(int $rServerID, array $rRecord): bool {
		$rRecord = array_filter(array_intersect_key($rRecord, array_flip(self::KEYS)), static fn($rValue) => is_scalar($rValue) || $rValue === null);
		$rUUID = (string) ($rRecord['uuid'] ?? '');
		if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $rUUID) || (empty($rRecord['user_id']) && empty($rRecord['hmac_id']))) {
			return false;
		}
		$rRecord += ['user_id' => null, 'proxy_id' => null]; // the store reads both
		$rRecord['server_id'] = $rServerID;
		$rRecord['hls_end'] = empty($rRecord['hls_end']) ? 0 : 1;
		$rRecord['identity'] = !empty($rRecord['user_id']) ? $rRecord['user_id'] : $rRecord['hmac_id'] . '_' . ($rRecord['hmac_identifier'] ?? '');

		if (SettingsManager::get('redis_handler')) {
			$rRedis = RedisManager::instance();
			if (!$rRedis instanceof \Redis) {
				throw new \RuntimeException('redis unavailable'); // the batch is not applied; the node resends it
			}
			$rExisting = ConnectionTracker::getConnection($rUUID);
			if (is_array($rExisting)) {
				if ((int) ($rExisting['server_id'] ?? 0) !== $rServerID) {
					return false; // another node's connection
				}
				return ConnectionTracker::updateConnection($rExisting, $rRecord, $rRecord['hls_end'] ? 'close' : 'open') !== null;
			}
			return (bool) ConnectionTracker::createConnection($rRecord);
		}

		$rDb = self::db();
		$rDb->query('SELECT `activity_id`, `server_id` FROM `lines_live` WHERE `uuid` = ?;', $rUUID);
		$rRow = $rDb->num_rows() > 0 ? $rDb->get_row() : null;
		$rColumns = array_intersect_key($rRecord, array_flip(self::COLUMNS));
		if ($rRow !== null) {
			if ((int) $rRow['server_id'] !== $rServerID) {
				return false;
			}
			unset($rColumns['uuid']);
			return (bool) $rDb->query('UPDATE `lines_live` SET ' . implode(', ', array_map(static fn($rColumn) => '`' . $rColumn . '` = ?', array_keys($rColumns))) . ' WHERE `activity_id` = ?;', ...array_values($rColumns), ...[(int) $rRow['activity_id']]);
		}
		return (bool) $rDb->query('INSERT INTO `lines_live` (`' . implode('`,`', array_keys($rColumns)) . '`) VALUES(' . implode(',', array_fill(0, count($rColumns), '?')) . ');', ...array_values($rColumns));
	}

	public static function remove(int $rServerID, string $rUUID): bool {
		if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $rUUID)) {
			return false;
		}
		if (SettingsManager::get('redis_handler')) {
			$rRedis = RedisManager::instance();
			if (!$rRedis instanceof \Redis) {
				throw new \RuntimeException('redis unavailable');
			}
			$rExisting = ConnectionTracker::getConnection($rUUID);
			if (!is_array($rExisting)) {
				return true; // already gone
			}
			if ((int) ($rExisting['server_id'] ?? 0) !== $rServerID) {
				return false;
			}
			return ConnectionTracker::removeRecord($rRedis, $rExisting);
		}
		return (bool) self::db()->query('DELETE FROM `lines_live` WHERE `uuid` = ? AND `server_id` = ?;', $rUUID, $rServerID);
	}
}
