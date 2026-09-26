<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\ClusterSettings;
use XcVm\Core\Cluster\Crypto\Canonical;
use XcVm\Core\Cluster\Crypto\ClusterCrypto;
use XcVm\Core\Process\ProcessManager;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * MAIN's two PHP-FPM pools for the cluster API (plan, "Transport"), apart
 * from the panel's pools, so a fleet's long-polls and ingest never take the
 * workers that serve the panel and viewers:
 *
 * | Pool | Lanes | `pm.max_children` | Timeout |
 * | --- | --- | --- | --- |
 * | `cluster_ctl` | poll, ctl | 2·nodes + 24 | 60 s |
 * | `cluster_ingest` | p0, bulk | min(2·`cluster_ingest_concurrency` + 8, floor(0.25 · MariaDB `max_connections`)) | 90 s |
 *
 * `nodes` counts the streaming servers other than MAIN, enrolled or not, so
 * the pool is sized before a node's first long-poll holds a worker.
 *
 * Files, all MAIN only (the LB build strips Domain/Cluster):
 *
 * - configs in `bin/php/etc/cluster/<pool>.conf`, a directory of their own:
 *   `set_services` deletes and rewrites `bin/php/etc/*.conf` for the panel's
 *   pools;
 * - sockets in `bin/php/sockets/<pool>.sock`, where nginx's `cluster_ctl` and
 *   `cluster_ingest` upstreams point, with a panel pool as their backup;
 * - pid files in `bin/php/var/run/`, not `sockets/`, where `restart_php_fpm`
 *   counts the panel's pools.
 *
 * ensure() runs from `status` (at boot and after an update) and each minute
 * from cron:servers. It writes the configs, starts a pool that is not
 * running and reloads one whose size changed. The marker `tmp/cluster_ready`
 * exists only once both pools answer FPM's own ping. Until then nginx falls
 * back to the panel pool, and gate() answers every op but `health` with a
 * panel-signed 503 STARTING, so agents back off instead of holding panel
 * workers with their long-polls. The service removes the marker whenever it
 * (re)starts, and tmp/ is a tmpfs, so a reboot does too.
 */
final class ClusterPool {
	use DatabaseAware;

	/** pool => request_terminate_timeout (s) */
	public const POOLS = ['cluster_ctl' => 60, 'cluster_ingest' => 90];

	/** The plan's control ops (lanes poll and ctl), served by cluster_ctl; so is an unknown op, to be refused. */
	public const CTL_OPS = [
		'health', 'challenge', 'enrol_complete', 'enrol_code', 'enrol_code_status', 'token_refresh',
		'token_rekey', 'hello', 'heartbeat', 'conn_admit', 'commands', 'ack',
	];

	/**
	 * The plan's ingest ops (lanes p0 and bulk), served by cluster_ingest,
	 * including those the API does not serve yet. The /cluster/v1/ location in
	 * bin/nginx/conf/nginx.conf lists the same names.
	 */
	public const INGEST_OPS = [
		'events', 'config', 'streams', 'conn_snapshot', 'stream_bundle', 'rpc_result',
		'recording_complete', 'vod_analysis', 'queue_claim', 'queue_update', 'queue_enqueue', 'artefact',
	];

	/** The ingest pool's floor: one P0 and one bulk request, however small MariaDB is. */
	public const MIN_INGEST = 2;

	/** The back-off the STARTING denial asks for. */
	public const RETRY_AFTER_MS = 5000;

	/** Relative to the install root; tmp/ is TMP_PATH. */
	public const MARKER = 'tmp/cluster_ready';

	private const CONF_DIR = 'bin/php/etc/cluster/';

	private const RUN_DIR = 'bin/php/var/run/';

	private const PING_PATH = '/ping';

	private const PING_RESPONSE = 'pong';

	/** Seconds one ping may take. */
	private const PING_TIMEOUT = 2.0;

	private static ?string $rBase = null;

	private static string $rUser = 'xc_vm';

	private static ?\Closure $rProcs = null;

	/** Tests: another install root (null: MAIN_HOME), and the user the pools run as. */
	public static function useBase(?string $rBase, string $rUser = 'xc_vm'): void {
		self::$rBase = $rBase;
		self::$rUser = $rUser;
	}

	/**
	 * Tests: fake the pools' processes with fn(string $rAction, string $rPool): bool,
	 * for the actions alive, answers, start and reload (null: the real ones).
	 */
	public static function useProcs(?\Closure $rProcs): void {
		self::$rProcs = $rProcs;
	}

	/** The pool that serves an op: its lane's. */
	public static function poolFor(string $rOp): string {
		return in_array($rOp, self::INGEST_OPS, true) ? 'cluster_ingest' : 'cluster_ctl';
	}

	public static function ctlChildren(int $rNodes): int {
		return 2 * max(0, $rNodes) + 24;
	}

	/** @param int|null $rMaxConnections MariaDB's max_connections, null when unknown */
	public static function ingestChildren(int $rConcurrency, ?int $rMaxConnections): int {
		[, $rMin, $rMax] = ClusterSettings::INTS['cluster_ingest_concurrency'];
		$rChildren = 2 * max($rMin, min($rMax, $rConcurrency)) + 8;
		if ($rMaxConnections !== null && $rMaxConnections > 0) {
			$rChildren = min($rChildren, intdiv($rMaxConnections, 4));
		}
		return max(self::MIN_INGEST, $rChildren);
	}

	/**
	 * pool => pm.max_children, from the servers table, the settings and
	 * MariaDB's limit. What cannot be read falls back to its default.
	 *
	 * @return array<string, int>
	 */
	public static function sizes(): array {
		$rNodes = 0;
		$rConcurrency = (int) ClusterSettings::INTS['cluster_ingest_concurrency'][0];
		$rMaxConnections = null;
		$rRead = static function (string $rQuery): ?array {
			try {
				$rDb = self::db();
				if (!$rDb->query($rQuery)) {
					return null;
				}
				$rRow = $rDb->get_row();
				return is_array($rRow) && $rRow !== [] ? $rRow : null;
			} catch (\Throwable) {
				return null;
			}
		};
		$rRow = $rRead('SELECT COUNT(*) AS `nodes` FROM `servers` WHERE `is_main` = 0 AND `server_type` = 0;');
		if ($rRow !== null) {
			$rNodes = (int) $rRow['nodes'];
		}
		$rRow = $rRead('SELECT `cluster_ingest_concurrency` FROM `settings` LIMIT 1;');
		if ($rRow !== null && is_numeric($rRow['cluster_ingest_concurrency'])) {
			$rConcurrency = (int) $rRow['cluster_ingest_concurrency'];
		}
		$rRow = $rRead('SELECT @@GLOBAL.max_connections AS `max_connections`;');
		if ($rRow !== null && is_numeric($rRow['max_connections'])) {
			$rMaxConnections = (int) $rRow['max_connections'];
		}
		return ['cluster_ctl' => self::ctlChildren($rNodes), 'cluster_ingest' => self::ingestChildren($rConcurrency, $rMaxConnections)];
	}

	/** A pool's FPM config, in the panel template's style (bin/php/etc/template). */
	public static function render(string $rPool, int $rChildren, string $rBase, string $rUser = 'xc_vm'): string {
		return '; Generated by XC_VM (ClusterPool): the cluster API\'s ' . $rPool . " pool. Do not edit.\n"
			. "[global]\n"
			. 'pid = ' . $rBase . self::RUN_DIR . $rPool . ".pid\n"
			. "events.mechanism = epoll\n"
			. "daemonize = yes\n"
			. "rlimit_files = 4000\n"
			// A reload lets short requests finish; a held long-poll is cut and retried.
			. "process_control_timeout = 5s\n"
			. '[' . $rPool . "]\n"
			. 'listen = ' . self::socket($rPool, $rBase) . "\n"
			. 'listen.owner = ' . $rUser . "\n"
			. 'listen.group = ' . $rUser . "\n"
			. "listen.mode = 0660\n"
			. "pm = ondemand\n"
			. 'pm.max_children = ' . max(1, $rChildren) . "\n"
			. "pm.max_requests = 40000\n"
			. "pm.process_idle_timeout = 10s\n"
			. 'request_terminate_timeout = ' . self::POOLS[$rPool] . "s\n"
			. "security.limit_extensions = .php\n"
			. 'ping.path = ' . self::PING_PATH . "\n"
			. 'ping.response = ' . self::PING_RESPONSE . "\n"
			. "pm.status_path = /status\n";
	}

	public static function socket(string $rPool, ?string $rBase = null): string {
		return ($rBase ?? self::base()) . 'bin/php/sockets/' . $rPool . '.sock';
	}

	/** Both pools answered when ensure() last looked. */
	public static function ready(): bool {
		$rBase = self::base();
		return $rBase !== null && is_file($rBase . self::MARKER);
	}

	/** The service is (re)starting: not ready until ensure() finds both pools answering. */
	public static function unmark(): void {
		$rBase = self::base();
		if ($rBase !== null) {
			@unlink($rBase . self::MARKER);
		}
	}

	/**
	 * What the API answers while it is starting, or null to serve the request:
	 * until ready(), every op but `health` gets a panel-signed 503 STARTING,
	 * bound to the node and the nonce when the request names them.
	 *
	 * @param array{path: string, headers?: array<string, string>} $rReq
	 * @return array{status: int, headers: array<string, string>, body: string}|null
	 */
	public static function gate(ClusterCrypto $rCrypto, array $rReq): ?array {
		if ((string) $rReq['path'] === Canonical::PATH_PREFIX . 'health' || self::ready()) {
			return null;
		}
		$rH = Canonical::parseHeaders($rReq['headers'] ?? []);
		return DenialFactory::deny($rCrypto, 503, 'STARTING', $rH['node'] ?? null, $rH['nonce'] ?? null, ['retry_after_ms' => self::RETRY_AFTER_MS]);
	}

	/** Does the pool answer FPM's ping now? */
	public static function answers(string $rPool): bool {
		return self::proc('answers', $rPool);
	}

	/**
	 * Bring both pools to their current size: write the configs, start a pool
	 * that is not running, reload one whose config changed. The marker then
	 * exists exactly while both answer, waiting up to $rWaitSec for pools this
	 * pass started or reloaded. Creating the marker restarts the nodes'
	 * silence clock (ClusterMeta::markReady), since the API was not serving.
	 * Returns whether the API is ready.
	 *
	 * Serialised by a lock: `status` (root, at boot) and cron:servers (xc_vm)
	 * can run it at the same time.
	 */
	public static function ensure(float $rWaitSec = 10.0): bool {
		$rBase = self::base();
		if ($rBase === null) {
			return false;
		}
		foreach ([self::CONF_DIR, self::RUN_DIR] as $rDir) {
			if (!is_dir($rBase . $rDir)) {
				@mkdir($rBase . $rDir, 0755, true);
				self::own($rBase . $rDir);
			}
		}
		$rLockPath = $rBase . self::CONF_DIR . '.lock';
		$rLock = @fopen($rLockPath, 'c');
		if ($rLock !== false) {
			self::own($rLockPath);
			flock($rLock, LOCK_EX);
		}
		try {
			return self::ensureLocked($rBase, $rWaitSec);
		} finally {
			if ($rLock !== false) {
				flock($rLock, LOCK_UN);
				fclose($rLock);
			}
		}
	}

	private static function ensureLocked(string $rBase, float $rWaitSec): bool {
		$rMarked = self::ready();
		$rTouched = false;
		foreach (self::sizes() as $rPool => $rChildren) {
			$rChanged = self::write(self::conf($rPool), self::render($rPool, $rChildren, $rBase, self::$rUser));
			if (!self::proc('alive', $rPool)) {
				if ($rMarked) {
					self::unmark();
					$rMarked = false;
				}
				self::proc('start', $rPool);
				$rTouched = true;
			} elseif ($rChanged) {
				self::proc('reload', $rPool);
				$rTouched = true;
			}
		}
		if ($rMarked && !$rTouched) {
			return true;
		}

		$rDeadline = microtime(true) + max(0.0, $rWaitSec);
		while (true) {
			$rAnswer = self::proc('answers', 'cluster_ctl') && self::proc('answers', 'cluster_ingest');
			if ($rAnswer || microtime(true) >= $rDeadline) {
				break;
			}
			usleep(200000);
		}
		if (!$rAnswer) {
			self::unmark();
			return false;
		}
		if (!$rMarked) {
			$rMarker = $rBase . self::MARKER;
			if (@file_put_contents($rMarker, (string) ClusterClock::nowMs()) === false) {
				return false;
			}
			self::own($rMarker);
			try {
				ClusterMeta::markReady();
			} catch (\Throwable) {
				// Liveness then counts from the last ready_at; the pools serve regardless.
			}
		}
		return true;
	}

	private static function base(): ?string {
		return self::$rBase ?? (defined('MAIN_HOME') ? (string) MAIN_HOME : null);
	}

	private static function conf(string $rPool): string {
		return self::base() . self::CONF_DIR . $rPool . '.conf';
	}

	private static function proc(string $rAction, string $rPool): bool {
		if (self::$rProcs !== null) {
			return (bool) (self::$rProcs)($rAction, $rPool);
		}
		switch ($rAction) {
			case 'alive':
				return self::masterPid($rPool) !== null;
			case 'answers':
				return self::ping(self::socket($rPool));
			case 'start':
				return self::start($rPool);
			case 'reload':
				// FPM re-reads its config and replaces its workers, on the same socket.
				$rPid = self::masterPid($rPool);
				return $rPid !== null && posix_kill($rPid, defined('SIGUSR2') ? SIGUSR2 : 12);
		}
		return false;
	}

	/** The pool's FPM master, found by the config it was started with. */
	private static function masterPid(string $rPool): ?int {
		$rPIDs = ProcessManager::findProcessPIDs(['php-fpm: master process (' . self::conf($rPool) . ')'], 1);
		return $rPIDs === [] ? null : (int) $rPIDs[0];
	}

	/** Start the pool's FPM master, which daemonizes; as root, it runs as the pool user. */
	private static function start(string $rPool): bool {
		$rBin = self::base() . 'bin/php/sbin/php-fpm';
		if (!is_executable($rBin)) {
			return false;
		}
		$rArgv = [$rBin, '--daemonize', '--fpm-config', self::conf($rPool)];
		if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
			$rArgv = array_merge(['sudo', '-u', self::$rUser], $rArgv);
		}
		$rNull = ['file', '/dev/null', 'w'];
		$rProc = proc_open($rArgv, [0 => ['file', '/dev/null', 'r'], 1 => $rNull, 2 => $rNull], $rPipes);
		return is_resource($rProc) && proc_close($rProc) === 0;
	}

	/**
	 * FPM's own ping (`ping.path`), as one FastCGI request over the pool's
	 * socket: a worker answered when the body is the ping response. nginx
	 * never forwards it, since the API's location fixes SCRIPT_NAME.
	 */
	private static function ping(string $rSocket): bool {
		$rConn = @stream_socket_client('unix://' . $rSocket, $rErrNo, $rErrStr, self::PING_TIMEOUT);
		if ($rConn === false) {
			return false;
		}
		stream_set_timeout($rConn, (int) self::PING_TIMEOUT);
		$rRecord = static fn(int $rType, string $rBody): string => pack('CCnnCC', 1, $rType, 1, strlen($rBody), 0, 0) . $rBody;
		$rParams = '';
		foreach (['REQUEST_METHOD' => 'GET', 'SCRIPT_NAME' => self::PING_PATH, 'SCRIPT_FILENAME' => self::PING_PATH] as $rName => $rValue) {
			$rParams .= chr(strlen($rName)) . chr(strlen($rValue)) . $rName . $rValue;
		}
		// BEGIN_REQUEST (responder, close), PARAMS, end of PARAMS, empty STDIN.
		$rOut = '';
		$rEnded = false;
		if (@fwrite($rConn, $rRecord(1, pack('nCx5', 1, 0)) . $rRecord(4, $rParams) . $rRecord(4, '') . $rRecord(5, '')) !== false) {
			while (!$rEnded && strlen($rOut) < 65536) {
				$rHead = self::readExact($rConn, 8);
				if ($rHead === null) {
					break;
				}
				$rRec = (array) unpack('Cversion/Ctype/nid/nlength/Cpadding/Creserved', $rHead);
				$rBody = self::readExact($rConn, (int) $rRec['length'] + (int) $rRec['padding']);
				if ($rBody === null) {
					break;
				}
				if ((int) $rRec['type'] === 6) {
					$rOut .= substr($rBody, 0, (int) $rRec['length']);
				}
				$rEnded = (int) $rRec['type'] === 3;
			}
		}
		fclose($rConn);
		$rParts = preg_split('/\r?\n\r?\n/', $rOut, 2);
		return $rEnded && is_array($rParts) && count($rParts) === 2 && trim($rParts[1]) === self::PING_RESPONSE;
	}

	/** @param resource $rConn */
	private static function readExact($rConn, int $rLength): ?string {
		$rOut = '';
		while (strlen($rOut) < $rLength) {
			$rChunk = @fread($rConn, $rLength - strlen($rOut));
			if ($rChunk === false || $rChunk === '') {
				return null;
			}
			$rOut .= $rChunk;
		}
		return $rOut;
	}

	/** Write $rContent when it differs from the file; true when it changed. */
	private static function write(string $rPath, string $rContent): bool {
		if (@file_get_contents($rPath) === $rContent) {
			return false;
		}
		$rTmp = $rPath . '.tmp';
		if (@file_put_contents($rTmp, $rContent) === false || !@rename($rTmp, $rPath)) {
			@unlink($rTmp);
			return false;
		}
		@chmod($rPath, 0644);
		self::own($rPath);
		return true;
	}

	/** As root, hand a file to the pool user, who rewrites it from cron:servers. */
	private static function own(string $rPath): void {
		if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
			@chown($rPath, self::$rUser);
			@chgrp($rPath, self::$rUser);
		}
	}
}
