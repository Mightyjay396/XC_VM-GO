<?php

namespace XcVm\Domain\Cluster;

use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * Close MAIN's MariaDB (3306) and Redis (6379) to the network (plan, section
 * 10, step 5; ADR 0004, Phase 9): `cluster:lockdown`, and `--undo`.
 *
 * Run by hand only — nothing in the panel calls it (the operator's decision):
 * it is the last step of the cutover, and a node that still needs either port
 * loses MAIN when it runs. It refuses while one might:
 *
 * - a load balancer below mode 2 (a legacy node, or an enrolled one in mode 0
 *   or 1) still reads MAIN's database and Redis;
 * - a proxy may still hold a `db_grant` — which ones do is not recorded, so
 *   every proxy counts (ADR 0004, "Shared MariaDB and Redis before lockdown").
 *
 * `--force` locks down regardless, and says whom it cuts off.
 *
 * What it does, each step undone by `--undo`:
 *
 * 1. MariaDB binds to 127.0.0.1: a drop-in (MYSQL_DROPIN) sorted after the
 *    installer's 99-custom.cnf, so it wins over its `bind-address = 0.0.0.0`.
 * 2. Redis binds to `127.0.0.1 -::1` in bin/redis/redis.conf; the line it
 *    replaced is kept for `--undo`.
 * 3. The XCVM_DB chain (DbAllowlist) is kept on and narrowed to loopback,
 *    MAIN's own addresses and `cluster_db_allowlist_extra`, whatever the
 *    allowlist setting says; the root cron keeps it so every minute.
 *
 * With `cluster_db_allowlist_extra` set, the two services keep listening on
 * every interface (the extra hosts could not reach a loopback bind) and the
 * firewall alone is the allowlist. The binds take effect when MariaDB and
 * Redis restart (`--restart` restarts MariaDB now); the firewall at once.
 * The state lives in `cluster_meta` (DbAllowlist::LOCKDOWN_META).
 */
final class ClusterLockdown {
	use DatabaseAware;

	/** The MariaDB drop-in's name: after the installer's 99-custom.cnf. */
	public const MYSQL_DROPIN = '99-xcvm-lockdown.cnf';

	/** Redis' bind while locked down. */
	public const REDIS_BIND = 'bind 127.0.0.1 -::1';

	/** @var callable(list<string>, ?string): array{0: int, 1: string} */
	private $rExec;

	private DbAllowlist $rAllowlist;

	private string $rMysqlDir;

	private string $rRedisConf;

	/**
	 * @param (callable(list<string>, ?string): array{0: int, 1: string})|null $rExec   argv runner (DbAllowlist::run)
	 * @param string|null $rMysqlDir  MariaDB's drop-in directory (found when null)
	 * @param string|null $rRedisConf redis.conf
	 */
	public function __construct(?DbAllowlist $rAllowlist = null, ?callable $rExec = null, ?string $rMysqlDir = null, ?string $rRedisConf = null) {
		$this->rExec = $rExec ?? [DbAllowlist::class, 'run'];
		$this->rAllowlist = $rAllowlist ?? new DbAllowlist($this->rExec);
		$this->rMysqlDir = $rMysqlDir ?? self::mysqlDir();
		$this->rRedisConf = $rRedisConf ?? (defined('MAIN_HOME') ? MAIN_HOME . 'bin/redis/redis.conf' : '');
	}

	/**
	 * Who would lose MAIN's database or Redis: load balancers below mode 2
	 * and every proxy.
	 *
	 * @return array{nodes: list<int>, proxies: list<int>}
	 */
	public static function blockers(): array {
		self::db()->query('SELECT `id`, `server_type`, `proxy_signed` FROM `servers` WHERE `is_main` = 0 ORDER BY `id`;');
		$rOut = ['nodes' => [], 'proxies' => []];
		foreach (self::db()->get_rows() as $rRow) {
			$rID = (int) $rRow['id'];
			if ((int) $rRow['server_type'] === 1) {
				// A proxy that signs its channel (ProxyKey, D8) uses MAIN's API only.
				if (empty($rRow['proxy_signed'])) {
					$rOut['proxies'][] = $rID;
				}
				continue;
			}
			try {
				$rNode = NodeRegistry::byServer($rID);
			} catch (\Throwable) {
				$rNode = null;
			}
			if ($rNode === null || (int) $rNode['mode'] < 2 || !in_array($rNode['state'], ['active', 'quarantined'], true)) {
				$rOut['nodes'][] = $rID;
			}
		}
		return $rOut;
	}

	/** @return array<string, mixed>|null the lockdown in force, or null */
	public static function state(): ?array {
		$rState = json_decode((string) ClusterMeta::get(DbAllowlist::LOCKDOWN_META), true);
		return is_array($rState) ? $rState : null;
	}

	/**
	 * Lock down. Returns what was done, one line per step; refuses (throws)
	 * with the blockers when there are any and $rForce is false, before
	 * anything changes.
	 *
	 * @return list<string>
	 */
	public function apply(bool $rForce, bool $rRestart = false, string $rActor = 'cli'): array {
		$rBlockers = self::blockers();
		if (($rBlockers['nodes'] !== [] || $rBlockers['proxies'] !== []) && !$rForce) {
			throw new LockdownRefused($rBlockers);
		}
		self::db()->query('SELECT `cluster_db_allowlist_extra` FROM `settings` LIMIT 1;');
		[$rExtra] = DbAllowlist::parseExtra((string) (self::db()->get_row()['cluster_db_allowlist_extra'] ?? ''));
		$rLoopback = $rExtra === [];
		$rState = self::state() ?? [];
		$rOut = [];

		if ($rLoopback) {
			if ($this->rMysqlDir === '' || @file_put_contents($this->rMysqlDir . self::MYSQL_DROPIN, "# cluster:lockdown — removed by cluster:lockdown --undo\n[mysqld]\nbind-address = 127.0.0.1\n") === false) {
				throw new \RuntimeException('cannot write the MariaDB drop-in in ' . ($this->rMysqlDir !== '' ? $this->rMysqlDir : 'the MariaDB configuration directory'));
			}
			$rOut[] = 'MariaDB: bind-address 127.0.0.1 (' . $this->rMysqlDir . self::MYSQL_DROPIN . ')';
			$rWas = $this->redisBind(self::REDIS_BIND);
			if ($rWas !== null && !isset($rState['redis_bind'])) {
				$rState['redis_bind'] = $rWas;
			}
			$rOut[] = 'Redis: ' . self::REDIS_BIND . ' (' . $this->rRedisConf . ')';
		} else {
			$rOut[] = 'MariaDB and Redis keep their binds: cluster_db_allowlist_extra names hosts a loopback bind would cut off; the firewall alone admits them';
		}
		$rState += ['at' => time(), 'loopback' => $rLoopback, 'forced' => $rBlockers];
		ClusterMeta::set(DbAllowlist::LOCKDOWN_META, (string) json_encode($rState, JSON_UNESCAPED_SLASHES));
		$rResult = $this->rAllowlist->sync($rActor);
		$rOut[] = $rResult === null ? 'Firewall: not applied (the servers table could not be read); the root cron retries every minute' : 'Firewall: IPv4 ' . $rResult[4] . ', IPv6 ' . $rResult[6];
		if ($rLoopback && $rRestart) {
			[$rCode] = ($this->rExec)(['systemctl', 'restart', 'mariadb'], null);
			$rOut[] = 'MariaDB restarted: ' . ($rCode === 0 ? 'ok' : 'exit ' . $rCode);
		}
		ClusterAudit::log('cluster.lockdown', null, ['on' => true, 'loopback' => $rLoopback, 'forced' => $rBlockers], $rActor);
		return $rOut;
	}

	/**
	 * Undo it: the drop-in goes, Redis' bind comes back, and the chain follows
	 * the allowlist setting again (removed when it is off).
	 *
	 * @return list<string>
	 */
	public function undo(bool $rRestart = false, string $rActor = 'cli'): array {
		$rState = self::state();
		$rOut = [];
		if ($this->rMysqlDir !== '' && is_file($this->rMysqlDir . self::MYSQL_DROPIN)) {
			if (!@unlink($this->rMysqlDir . self::MYSQL_DROPIN)) {
				throw new \RuntimeException('cannot remove ' . $this->rMysqlDir . self::MYSQL_DROPIN);
			}
			$rOut[] = 'MariaDB: drop-in removed';
		}
		if (is_string($rState['redis_bind'] ?? null)) {
			$this->redisBind($rState['redis_bind']);
			$rOut[] = 'Redis: ' . $rState['redis_bind'];
		}
		self::db()->query('DELETE FROM `cluster_meta` WHERE `name` = ?;', DbAllowlist::LOCKDOWN_META);
		$rResult = $this->rAllowlist->sync($rActor);
		$rOut[] = $rResult === null ? 'Firewall: left as it is (the servers table could not be read)' : 'Firewall: IPv4 ' . $rResult[4] . ', IPv6 ' . $rResult[6];
		if ($rRestart) {
			[$rCode] = ($this->rExec)(['systemctl', 'restart', 'mariadb'], null);
			$rOut[] = 'MariaDB restarted: ' . ($rCode === 0 ? 'ok' : 'exit ' . $rCode);
		}
		ClusterAudit::log('cluster.lockdown', null, ['on' => false], $rActor);
		return $rOut;
	}

	/**
	 * Set redis.conf's bind line; returns the one it replaced (null when the
	 * file has none, or there is no file).
	 */
	private function redisBind(string $rLine): ?string {
		if ($this->rRedisConf === '' || !is_file($this->rRedisConf)) {
			return null;
		}
		$rConf = (string) file_get_contents($this->rRedisConf);
		$rWas = preg_match('/^bind .*$/m', $rConf, $rM) ? $rM[0] : null;
		$rNew = $rWas !== null ? (string) preg_replace('/^bind .*$/m', $rLine, $rConf, 1) : $rLine . "\n" . $rConf;
		if ($rNew !== $rConf && @file_put_contents($this->rRedisConf, $rNew) === false) {
			throw new \RuntimeException('cannot write ' . $this->rRedisConf);
		}
		return $rWas;
	}

	/** MariaDB's drop-in directory (the installer's), with its slash; '' when there is none. */
	private static function mysqlDir(): string {
		foreach (['/etc/mysql/mariadb.conf.d/', '/etc/my.cnf.d/'] as $rDir) {
			if (is_dir($rDir)) {
				return $rDir;
			}
		}
		return '';
	}
}
