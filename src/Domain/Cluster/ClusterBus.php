<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Process\ProcessManager;

/**
 * The cluster bus (plan, section 2): MAIN's own Redis instance for the
 * cluster API, separate from the shared Redis the panel and legacy LBs use,
 * and reachable only through a unix socket (`bin/cluster_bus/cluster.sock`,
 * mode 0700, owned by xc_vm). It holds nothing that must survive a restart,
 * so it is not persisted, and `disable_handler` / `clear_redis` never touch
 * it.
 *
 * Today it carries wake-ups:
 *
 * - `wake:<sid>`: a command was queued for the node, so its `commands`
 *   long-poll returns at once instead of re-reading `cluster_commands` every
 *   250 ms for 20 s;
 * - `ack:<cmd_id>`: the node acked a command, so an RPC waiting for it returns
 *   at once instead of polling every 100 ms.
 *
 * A wake is a one-element list with a short TTL, taken with BLPOP: one pushed
 * just before the waiter blocks is not lost, and a stale one costs one extra
 * query. Without the bus (not started, an LB, a test) every method reports
 * that it could not help, and the callers poll as they did before.
 */
final class ClusterBus {
	/** Seconds a wake waits for its reader. */
	private const WAKE_TTL = 60;

	/** Seconds before a failed connect is tried again. */
	private const RETRY_AFTER = 5;

	private static ?\Redis $rClient = null;

	private static float $rFailedAt = 0.0;

	private static ?string $rSocket = null;

	/** The bus socket, or null where there is none (an LB build). */
	public static function socket(): ?string {
		if (self::$rSocket !== null) {
			return self::$rSocket;
		}
		return defined('MAIN_HOME') ? MAIN_HOME . 'bin/cluster_bus/cluster.sock' : null;
	}

	/** Tests: another socket (null: the default), and forget the connection. */
	public static function useSocket(?string $rSocket): void {
		self::$rSocket = $rSocket;
		self::$rClient = null;
		self::$rFailedAt = 0.0;
	}

	/** A connected client, or null when the bus is not reachable. */
	public static function client(): ?\Redis {
		if (self::$rClient !== null) {
			return self::$rClient;
		}
		$rSocket = self::socket();
		if ($rSocket === null || !class_exists(\Redis::class) || !file_exists($rSocket) || microtime(true) - self::$rFailedAt < self::RETRY_AFTER) {
			return null;
		}
		try {
			$rRedis = new \Redis();
			if (!$rRedis->connect($rSocket, 0, 0.2, null, 0, 1.0)) {
				throw new \RuntimeException('connect');
			}
			self::$rClient = $rRedis;
			return $rRedis;
		} catch (\Throwable) {
			self::$rFailedAt = microtime(true);
			return null;
		}
	}

	/** A command was queued for this node: wake its long-poll. */
	public static function wakeNode(int $rServerID): bool {
		return self::push('wake:' . $rServerID);
	}

	/**
	 * Wait until the node is woken, up to $rSeconds. True when woken, false on
	 * timeout, null when the bus is not there (the caller polls instead).
	 */
	public static function waitNode(int $rServerID, float $rSeconds): ?bool {
		return self::pop('wake:' . $rServerID, $rSeconds);
	}

	/** A command was acked: wake whoever awaits its outcome. */
	public static function wakeAck(string $rCmdID): bool {
		return self::push('ack:' . $rCmdID);
	}

	/** Wait for a command's ack, as waitNode(). */
	public static function waitAck(string $rCmdID, float $rSeconds): ?bool {
		return self::pop('ack:' . $rCmdID, $rSeconds);
	}

	/** Is the bus's redis-server running here? */
	public static function running(): bool {
		return ProcessManager::isAnyProcessRunning(['redis-server unixsocket:', 'bin/cluster_bus/cluster.conf']);
	}

	/**
	 * Start the bus when MAIN ships it and it is not running (boot, and the
	 * servers cron as a watchdog). As root, it runs as xc_vm.
	 */
	public static function ensureRunning(): bool {
		if (!defined('MAIN_HOME') || !file_exists(MAIN_HOME . 'bin/cluster_bus/cluster.conf') || !is_executable(MAIN_HOME . 'bin/redis/redis-server')) {
			return false;
		}
		if (self::running()) {
			return true;
		}
		// The server daemonizes (cluster.conf), so this returns at once.
		$rArgv = [MAIN_HOME . 'bin/redis/redis-server', MAIN_HOME . 'bin/cluster_bus/cluster.conf'];
		if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
			$rArgv = array_merge(['sudo', '-u', 'xc_vm'], $rArgv);
		}
		$rNull = ['file', '/dev/null', 'w'];
		$rProc = proc_open($rArgv, [0 => ['file', '/dev/null', 'r'], 1 => $rNull, 2 => $rNull], $rPipes);
		return is_resource($rProc) && proc_close($rProc) === 0;
	}

	private static function push(string $rKey): bool {
		$rRedis = self::client();
		if ($rRedis === null) {
			return false;
		}
		try {
			$rRedis->multi(\Redis::PIPELINE)->lPush($rKey, '1')->lTrim($rKey, 0, 0)->expire($rKey, self::WAKE_TTL)->exec();
			return true;
		} catch (\Throwable) {
			self::drop();
			return false;
		}
	}

	private static function pop(string $rKey, float $rSeconds): ?bool {
		$rRedis = self::client();
		if ($rRedis === null) {
			return null;
		}
		$rSeconds = max(0.01, $rSeconds);
		try {
			// The read must outlast the block.
			$rRedis->setOption(\Redis::OPT_READ_TIMEOUT, (string) ($rSeconds + 1.0));
			$rOut = $rRedis->blPop([$rKey], $rSeconds);
			return is_array($rOut) && $rOut !== [];
		} catch (\Throwable) {
			self::drop();
			return null;
		}
	}

	private static function drop(): void {
		self::$rClient = null;
		self::$rFailedAt = microtime(true);
	}
}
