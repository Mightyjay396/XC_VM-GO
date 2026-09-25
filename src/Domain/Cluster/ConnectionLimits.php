<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Infrastructure\Database\DatabaseAware;
use XcVm\Streaming\Auth\StreamAuth;

/**
 * MAIN enforcing `max_connections` for a CONNECTIONS node's viewers (cluster
 * plan, Phase 6). The node no longer reads MAIN's store on each request to
 * find the line's other connections: it sends `conn.limit {uuid, ip,
 * user_agent, owner}` after recording its viewer, and MAIN runs the same rule
 * the node used to (StreamAuth::validateConnections → ConnectionLimiter): the
 * line's connections past its limit are closed, oldest first and the same IP
 * and agent before others, never the viewer that asked. The closes reach the
 * nodes as signed commands.
 *
 * The cluster endpoint takes no legacy globals, so ingest only queues the
 * check (a file per check under TMP_PATH/cluster_limits/) and MAIN's 1 s loop
 * (cron:signals) drains it with the full bootstrap.
 *
 * A node can ask only about its own viewer: the uuid must be in MAIN's store
 * as that node's, for the same line or HMAC identity. A line's limit and pair
 * come from `lines`, never from the node; an HMAC identity's limit is signed
 * into the client's request and stored nowhere, so it comes from the node.
 */
final class ConnectionLimits {
	use DatabaseAware;

	private static ?string $rDir = null;

	/** @var (callable(array<string, mixed>, mixed, string, string, string, string): mixed)|null */
	private static $rEnforce = null;

	/** Tests: another queue directory and enforcer; null restores the defaults. */
	public static function useQueue(?string $rDir, ?callable $rEnforce = null): void {
		self::$rDir = $rDir;
		self::$rEnforce = $rEnforce;
	}

	public static function dir(): string {
		return self::$rDir ?? ((defined('TMP_PATH') ? TMP_PATH : sys_get_temp_dir() . '/') . 'cluster_limits/');
	}

	/**
	 * Queue a node's check (EventIngest).
	 *
	 * @param array<string, mixed> $rData {uuid, ip, user_agent, user_id | hmac_id + hmac_identifier + max_connections}
	 */
	public static function queue(int $rServerID, array $rData): bool {
		$rUUID = $rData['uuid'] ?? null;
		if (!is_string($rUUID) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $rUUID)) {
			return false;
		}
		$rCheck = ['server_id' => $rServerID, 'uuid' => $rUUID, 'ip' => substr((string) ($rData['ip'] ?? ''), 0, 64), 'user_agent' => substr((string) ($rData['user_agent'] ?? ''), 0, 512)];
		if (!empty($rData['user_id'])) {
			$rCheck['user_id'] = (int) $rData['user_id'];
		} elseif (!empty($rData['hmac_id'])) {
			$rCheck += ['hmac_id' => (int) $rData['hmac_id'], 'hmac_identifier' => substr((string) ($rData['hmac_identifier'] ?? ''), 0, 255), 'max_connections' => max(0, (int) ($rData['max_connections'] ?? 0))];
		} else {
			return false;
		}
		$rDir = self::dir();
		if (!is_dir($rDir) && !@mkdir($rDir, 0750, true) && !is_dir($rDir)) {
			return false;
		}
		$rName = sprintf('%019d-%d-%04x.json', hrtime(true), getmypid(), random_int(0, 0xffff));
		return @file_put_contents($rDir . '.' . $rName, json_encode($rCheck)) !== false && @rename($rDir . '.' . $rName, $rDir . $rName);
	}

	/**
	 * Run the queued checks, oldest first (MAIN's loop).
	 *
	 * @return int How many were enforced.
	 */
	public static function drain(int $rMax = 200): int {
		$rFiles = glob(self::dir() . '*.json') ?: [];
		sort($rFiles);
		$rDone = 0;
		foreach (array_slice($rFiles, 0, $rMax) as $rFile) {
			$rCheck = json_decode((string) @file_get_contents($rFile), true);
			@unlink($rFile);
			if (is_array($rCheck) && self::enforce($rCheck)) {
				$rDone++;
			}
		}
		return $rDone;
	}

	/** @param array<string, mixed> $rCheck */
	private static function enforce(array $rCheck): bool {
		$rOwner = self::owner((int) $rCheck['server_id'], (string) $rCheck['uuid']);
		if ($rOwner === null) {
			return false; // not that node's viewer (or gone already)
		}
		if (isset($rCheck['user_id'])) {
			if ((int) ($rOwner['user_id'] ?? 0) !== (int) $rCheck['user_id']) {
				return false;
			}
			self::db()->query('SELECT `id`, `max_connections`, `pair_id`, `is_restreamer` FROM `lines` WHERE `id` = ?;', (int) $rCheck['user_id']);
			if (self::db()->num_rows() !== 1) {
				return false;
			}
			$rLine = self::db()->get_row();
			$rUserInfo = ['id' => (int) $rLine['id'], 'max_connections' => (int) $rLine['max_connections'], 'pair_id' => $rLine['pair_id'] ?: null, 'is_restreamer' => (int) $rLine['is_restreamer']];
			$rHMAC = null;
			$rIdentifier = '';
		} else {
			if ((int) ($rOwner['hmac_id'] ?? 0) !== (int) $rCheck['hmac_id'] || (string) ($rOwner['hmac_identifier'] ?? '') !== (string) $rCheck['hmac_identifier']) {
				return false;
			}
			$rUserInfo = ['id' => null, 'max_connections' => (int) $rCheck['max_connections'], 'pair_id' => null, 'is_restreamer' => 0];
			$rHMAC = (int) $rCheck['hmac_id'];
			$rIdentifier = (string) $rCheck['hmac_identifier'];
		}
		if ($rUserInfo['max_connections'] <= 0) {
			return true; // unlimited
		}
		if (self::$rEnforce !== null) {
			(self::$rEnforce)($rUserInfo, $rHMAC, $rIdentifier, (string) $rCheck['ip'], (string) $rCheck['user_agent'], (string) $rCheck['uuid']);
			return true;
		}
		// ConnectionLimiter prefers closing the connections from the viewer's
		// own IP, which it reads from REMOTE_ADDR, as on the node.
		$rWas = $_SERVER['REMOTE_ADDR'] ?? null;
		$_SERVER['REMOTE_ADDR'] = (string) $rCheck['ip'];
		try {
			StreamAuth::validateConnections($rUserInfo, $rHMAC, $rIdentifier, (string) $rCheck['ip'], (string) $rCheck['user_agent'], (string) $rCheck['uuid']);
		} finally {
			if ($rWas === null) {
				unset($_SERVER['REMOTE_ADDR']);
			} else {
				$_SERVER['REMOTE_ADDR'] = $rWas;
			}
		}
		return true;
	}

	/**
	 * The viewer as MAIN's store holds it, when it is that node's.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function owner(int $rServerID, string $rUUID): ?array {
		if (SettingsManager::get('redis_handler')) {
			$rConnection = ConnectionTracker::getConnection($rUUID);
		} else {
			self::db()->query('SELECT `server_id`, `user_id`, `hmac_id`, `hmac_identifier` FROM `lines_live` WHERE `uuid` = ? AND `hls_end` = 0;', $rUUID);
			$rConnection = self::db()->num_rows() > 0 ? self::db()->get_row() : null;
		}
		return is_array($rConnection) && (int) ($rConnection['server_id'] ?? 0) === $rServerID ? $rConnection : null;
	}
}
