<?php

namespace XcVm\Infrastructure\Redis;

use XcVm\Core\Cluster\ConnectAudit;
use XcVm\Core\Cluster\LbDatabaseAccessException;
use XcVm\Infrastructure\Signal\SignalQueue;

/**
 * RedisManager — \Redis connection lifecycle management.
 *
 * Singleton that holds the active \Redis instance. Provides health-check
 * via ping (debounced to 30s), auto-reconnect on failure, and low-level
 * connect/close helpers for non-singleton usage.
 *
 * @package XC_VM_Infrastructure_Redis
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class RedisManager {
	/** @var \Redis|null Singleton instance */
	private static $instance;

	/** @var int Last ping health-check timestamp */
	private static $lastPingCheck = 0;

	/** A failed connect is not tried again for this long in the process (seconds). */
	public const RETRY_AFTER = 5;

	/** The longest a connect may take (seconds): phpredis's default is default_socket_timeout, 60 s. */
	public const CONNECT_TIMEOUT = 2;

	/** When this process's last connect failed (unix seconds); 0: none since. */
	private static int $rFailedAt = 0;

	/** @var (callable(): mixed)|null Tests: stands in for \XC_VM::redis_connect() */
	private static $rConnector = null;

	// ──────── Singleton API ────────

	/**
	 * Get the active \Redis instance, connecting if necessary.
	 *
	 * Performs a ping health-check no more than once every 30 seconds.
	 * If the connection is dead, attempts to reconnect automatically.
	 *
	 * @return \Redis|null Active \Redis instance, or null on connection failure.
	 */
	public static function instance(): ?\Redis {
		if (is_object(self::$instance)) {
			$rNow = time();
			if ($rNow - self::$lastPingCheck > 30) {
				try {
					$rPong = self::$instance->ping();
					if (!in_array($rPong, [true, '+PONG', 'PONG'], true)) {
						throw new \RedisException('unhealthy ping reply');
					}
					self::$lastPingCheck = $rNow;
				} catch (\RedisException $e) {
					self::$instance = null;
				}
			}
		}
		if (!is_object(self::$instance)) {
			// Redis down: one try per RETRY_AFTER, not one per call (each could
			// block for the connect timeout, and a request makes many calls).
			if (self::$rFailedAt > 0 && time() - self::$rFailedAt < self::RETRY_AFTER) {
				return null;
			}
			self::ensureConnected();
			self::$lastPingCheck = time();
			self::$rFailedAt = is_object(self::$instance) ? 0 : time();
		}
		return self::$instance;
	}

	/**
	 * Connect to \Redis if not already connected.
	 *
	 * @return bool True if connected, false otherwise.
	 */
	public static function ensureConnected(): bool {
		self::$instance = self::connect(self::$instance);
		return is_object(self::$instance);
	}

	/**
	 * Drop the singleton and establish a fresh, authenticated connection.
	 *
	 * phpredis can transparently reconnect a broken socket without replaying
	 * AUTH, so a previously healthy connection may suddenly answer NOAUTH.
	 * A full reconnect through \XC_VM::redis_connect() re-authenticates.
	 *
	 * @return \Redis|null Fresh instance, or null when Redis is unreachable.
	 */
	public static function reconnect(): ?\Redis {
		self::closeInstance();
		self::$rFailedAt = 0; // asked for: tried now, whatever failed before
		return self::instance();
	}

	/** Tests: another connector than \XC_VM::redis_connect(); null restores it, and forgets a failure. */
	public static function useConnector(?callable $rConnector): void {
		self::$rConnector = $rConnector;
		self::$rFailedAt = 0;
	}

	/**
	 * Close the singleton connection.
	 *
	 * @return bool Always returns true.
	 */
	public static function closeInstance(): bool {
		self::$instance = self::close(self::$instance);
		return true;
	}

	/**
	 * Check whether the singleton is connected.
	 *
	 * @return bool True if connected.
	 */
	public static function isConnected(): bool {
		return is_object(self::$instance);
	}

	/**
	 * @deprecated Signals now live in {@see SignalQueue}.
	 * Kept as a thin back-compat alias; call SignalQueue::push() directly.
	 *
	 * @param string $rKey  Signal key.
	 * @param mixed  $rData Signal payload.
	 */
	public static function setSignal(string $rKey, mixed $rData): void {
		SignalQueue::push($rKey, $rData);
	}

	/**
	 * Connect to \Redis (low-level, non-singleton).
	 *
	 * If $rRedis is already a live connection, returns it as-is.
	 * Otherwise creates a new connection via \XC_VM::redis_connect(), past
	 * ConnectAudit::guard(): counted on a node in cluster mode 1 or 2,
	 * refused in mode 2.
	 *
	 * @param \Redis|null $rRedis Existing \Redis instance or null.
	 * @return \Redis|null Connected \Redis instance, or null on failure.
	 * @throws LbDatabaseAccessException on a node in cluster mode 2 (api)
	 */
	public static function connect(?\Redis $rRedis = null): ?\Redis {
		if (is_object($rRedis)) {
			try {
				$rRedis->ping();
				return $rRedis;
			} catch (\RedisException $e) {
				$rRedis = null;
			}
		}

		ConnectAudit::guard(ConnectAudit::REDIS);
		// The extension connects with phpredis's default timeout, which is
		// default_socket_timeout (60 s): bounded here for the connect.
		$rTimeout = ini_get('default_socket_timeout');
		ini_set('default_socket_timeout', (string) self::CONNECT_TIMEOUT);
		try {
			$rRedis = self::$rConnector !== null ? (self::$rConnector)() : \XC_VM::redis_connect();
			if (!is_object($rRedis)) {
				return null;
			}
			$rRedis->setOption(\Redis::OPT_READ_TIMEOUT, 2.0);
			$rRedis->setOption(\Redis::OPT_TCP_KEEPALIVE, 60);
			// Validate the fresh connection: surfaces NOAUTH/WRONGPASS here
			// instead of on the first real command at a call site.
			$rRedis->ping();
			return $rRedis;
		} catch (\Exception $e) {
			return null;
		} finally {
			ini_set('default_socket_timeout', $rTimeout === false ? '60' : $rTimeout);
		}
	}

	/**
	 * Close a \Redis connection.
	 *
	 * @param \Redis|null $rRedis \Redis instance to close.
	 * @return null Always returns null (for assignment: $redis = close($redis)).
	 */
	public static function close(?\Redis $rRedis): ?\Redis {
		if (is_object($rRedis)) {
			try {
				$rRedis->close();
			} catch (\Throwable $e) {
				// A half-dead socket can throw on close ("read error on
				// connection"). We are discarding the instance anyway, so
				// swallow it — teardown must never fatal (this runs from
				// reconnect(), inside the watchdog capacity path).
			}
		}
		return null;
	}

	/**
	 * The panel's own redis-server (bin/redis/redis.conf), by the pidfile that
	 * config names: 0 when there is none, or when that pid is not a
	 * redis-server any more, or is the cluster bus's (it runs on a unix
	 * socket, `redis-server unixsocket:…`, as the same user). Never "the
	 * first redis-server of the user", which can be the bus.
	 */
	public static function panelServerPid(?string $rPidFile = null): int {
		$rPidFile ??= MAIN_HOME . 'bin/redis/redis-server.pid';
		$rPid = (int) trim((string) @file_get_contents($rPidFile));
		if ($rPid <= 0) {
			return 0;
		}
		$rTitle = str_replace("\0", ' ', (string) @file_get_contents('/proc/' . $rPid . '/cmdline'));
		return str_starts_with($rTitle, 'redis-server') && !str_contains($rTitle, 'unixsocket') ? $rPid : 0;
	}

}
