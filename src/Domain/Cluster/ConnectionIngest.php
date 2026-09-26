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
 * conn.close {uuid}      the viewer left the node's fanout: its activity row,
 *                        then drop it from the store
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

	/**
	 * A daemon-served viewer the node's fanout reports gone (conn.close, the
	 * fanout's conn_close): closed as fanout_sync's reconcile closes it, with
	 * its activity row, but without the kill and the conn.close command back
	 * that ConnectionTracker::closeConnection sends: the viewer is already
	 * gone and the node's registry already dropped it.
	 */
	public static function close(int $rServerID, string $rUUID): bool {
		if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $rUUID)) {
			return false;
		}
		if (SettingsManager::get('redis_handler')) {
			$rRow = ConnectionTracker::getConnection($rUUID);
		} else {
			self::db()->query('SELECT * FROM `lines_live` WHERE `uuid` = ?;', $rUUID);
			$rRow = self::db()->num_rows() > 0 ? self::db()->get_row() : null;
		}
		if (!is_array($rRow)) {
			return true; // already closed (fanout_sync, a kick)
		}
		if ((int) ($rRow['server_id'] ?? 0) !== $rServerID) {
			return false;
		}
		ConnectionTracker::writeOfflineActivity(
			SettingsManager::getAll() + ['save_closed_connection' => 0],
			$rServerID,
			(int) ($rRow['proxy_id'] ?? 0),
			(int) ($rRow['user_id'] ?? 0),
			(int) ($rRow['stream_id'] ?? 0),
			(int) ($rRow['date_start'] ?? 0),
			(string) ($rRow['user_agent'] ?? ''),
			(string) ($rRow['user_ip'] ?? ''),
			(string) ($rRow['container'] ?? ''),
			(string) ($rRow['geoip_country_code'] ?? ''),
			(string) ($rRow['isp'] ?? ''),
			(string) ($rRow['external_device'] ?? ''),
			(int) ($rRow['divergence'] ?? 0),
			isset($rRow['hmac_id']) ? (int) $rRow['hmac_id'] : null,
			(string) ($rRow['hmac_identifier'] ?? '')
		);
		return self::remove($rServerID, $rUUID);
	}

	/**
	 * The orphan purge (plan, "Liveness"): every connection MAIN's store holds
	 * for a node silent past cluster_orphan_conn_ttl_sec is dropped, so its
	 * viewers stop counting toward their lines' limits. Store only: no kill or
	 * command goes to the node, whose own registry still holds them; if it
	 * comes back, its digest disagrees and a snapshot puts them back.
	 *
	 * @return int How many were dropped.
	 */
	public static function purgeNode(int $rServerID): int {
		if (SettingsManager::get('redis_handler')) {
			$rRedis = RedisManager::instance();
			if (!$rRedis instanceof \Redis) {
				return 0;
			}
			$rUUIDs = $rRedis->zRange('SERVER#' . $rServerID, 0, -1);
			$rCount = 0;
			foreach (is_array($rUUIDs) ? $rUUIDs : [] as $rUUID) {
				$rConnection = ConnectionTracker::getConnection((string) $rUUID);
				if (is_array($rConnection) && (int) ($rConnection['server_id'] ?? 0) === $rServerID && ConnectionTracker::removeRecord($rRedis, $rConnection)) {
					$rCount++;
				} elseif (!is_array($rConnection)) {
					$rRedis->zRem('SERVER#' . $rServerID, $rUUID); // a dangling index entry
				}
			}
			return $rCount;
		}
		$rDb = self::db();
		$rDb->query('SELECT COUNT(*) AS `n` FROM `lines_live` WHERE `server_id` = ?;', $rServerID);
		$rCount = (int) ($rDb->get_row()['n'] ?? 0);
		if ($rCount > 0) {
			$rDb->query('DELETE FROM `lines_live` WHERE `server_id` = ?;', $rServerID);
		}
		return $rCount;
	}
}
