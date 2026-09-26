<?php

namespace XcVm\Core\Cluster;

use XcVm\Core\Cache\FileCache;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Config\SettingsRepository;
use XcVm\Domain\Security\BlocklistService;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * cluster:apply's work (plan, section 9, Phase 7): turn the replica the agent
 * verified into this node's caches. The agent writes
 * `config/cluster/replica/blocklist.json` from the sealed, panel-signed
 * records it holds (PHP holds no key to open them) and runs cluster:apply.
 *
 * ```text
 * cache            from the replica's blocklist
 * blocked_ips      ip   (a list of addresses)
 * blocked_servers  asn  (a list of blocked ASNs)
 * blocked_ua       ua   ([id => {id, exact_match, blocked_ua (lower case)}])
 * blocked_isp      isp  ([{id, isp, blocked}])
 * rtmp_ips         rtmp ([resolved ip => {password, push, pull}]), which
 *                  only MAIN's cron:cache builds; an LB read its database
 * ```
 *
 * The shapes are the ones cron:cache writes from MAIN's database, so every
 * reader (LegacyInitializer, BlocklistService) keeps its contract.
 *
 * - CONFIG off (shadow): nothing is written; the report counts, per cache,
 *   entries the database has that the replica lacks (`missing`) and entries
 *   only the replica has (`extra`). Both at 0 is what lets CONFIG go on.
 * - CONFIG on: the replica is authoritative. It writes the caches, and
 *   cron:cache stops writing them from the database.
 *
 * The `settings` section (`replica/settings.json`) is only compared: the keys
 * whose decoded value differs from the settings cache go to the report. It
 * becomes authoritative with the `secrets` section, which carries what the
 * allowlist withholds.
 *
 * The other whole sections (`replica/<name>.json`, `{etag, data}`, written
 * by the agent from the verified `rep` record; see ReplicaSections):
 *
 * ```text
 * servers + node  the `servers` cache, in ServerRepository::getAll's shape:
 *                 every server's routing fields, this node's own
 *                 configuration, the URLs built here (api_url with this
 *                 node's own live_streaming_pass), liveness unknown (null;
 *                 server_online: enabled)
 * crontab         the jobs this node's crontab runs (CRON_CACHE), read by
 *                 LegacyInitializer::generateCron and cron:root_signals
 * cluster         compared with the agent's own policy (agent.json); no PHP
 *                 reads it, the agent does
 * ```
 *
 * In shadow they are only compared with what the node uses today; with
 * CONFIG on they are the caches, and their readers stop reading MAIN's
 * database (owns()). A section that is missing, names another node or is
 * malformed never writes, and never clears, a cache.
 *
 * Either way the report goes to `replica/apply.json`.
 */
final class ReplicaApply {
	public const CACHES = ['blocked_ips', 'blocked_servers', 'blocked_ua', 'blocked_isp', 'rtmp_ips'];

	/** The crontab section's cache: the jobs the node's crontab runs. */
	public const CRON_CACHE = 'cron_jobs';

	/** Differing fields a shadow report names at most. */
	private const MAX_DIFFER = 100;

	private static ?string $rDir = null;

	/** Tests: another replica directory; null restores the default. */
	public static function useDir(?string $rDir): void {
		self::$rDir = $rDir;
	}

	public static function dir(): string {
		return self::$rDir ?? ((defined('CONFIG_PATH') ? CONFIG_PATH : '/home/xc_vm/config/') . 'cluster/replica/');
	}

	/**
	 * Does the replica own this section's cache? Only with the CONFIG flow on
	 * and once the agent has stored the section (`servers` needs `node` too);
	 * until then the readers keep MAIN's database, so a node whose agent does
	 * not fetch a section is not left with a cache nothing refreshes.
	 */
	public static function owns(string $rSection): bool {
		if (!NodeFlows::on(NodeFlows::CONFIG)) {
			return false;
		}
		$rFiles = $rSection === ReplicaSections::SERVERS ? [ReplicaSections::SERVERS, ReplicaSections::NODE] : [$rSection];
		foreach ($rFiles as $rFile) {
			if (!is_file(self::dir() . $rFile . '.json')) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The jobs this node's crontab runs: the replica's once it owns the
	 * crontab (null until cluster:apply wrote them: leave the crontab as it
	 * is), else MAIN's `crontab` table as before.
	 *
	 * @return list<array{filename: string, time: string}>|null
	 */
	public static function cronJobs(?object $rDb): ?array {
		if (self::owns(ReplicaSections::CRONTAB)) {
			$rJobs = FileCache::getCache(self::CRON_CACHE);
			return is_array($rJobs) ? array_values($rJobs) : null;
		}
		if ($rDb === null) {
			return null;
		}
		$rDb->query('SELECT * FROM `crontab` WHERE `enabled` = 1;');
		$rOut = [];
		foreach ($rDb->get_rows() ?: [] as $rRow) {
			$rOut[] = ['filename' => (string) $rRow['filename'], 'time' => (string) $rRow['time']];
		}
		return $rOut;
	}

	/**
	 * Apply the materialised replica. Null when there is nothing the agent
	 * wrote to apply.
	 *
	 * @param int|null $rServerID this node (SERVER_ID)
	 * @return array<string, mixed>|null
	 */
	public static function run(bool $rAuthoritative, ?int $rNow = null, ?int $rServerID = null): ?array {
		$rServerID ??= defined('SERVER_ID') ? (int) SERVER_ID : 0;
		$rReport = ['at' => $rNow ?? time()];
		$rDoc = json_decode((string) @file_get_contents(self::dir() . 'blocklist.json'), true);
		$rCaches = is_array($rDoc) && is_array($rDoc['data'] ?? null) ? self::caches($rDoc['data']) : null;
		if ($rCaches !== null) {
			$rReport += ['seq' => (int) ($rDoc['seq'] ?? 0), 'etag' => (string) ($rDoc['etag'] ?? ''), 'mode' => $rAuthoritative ? 'applied' : 'shadow'];
			if ($rAuthoritative) {
				foreach ($rCaches as $rKey => $rValue) {
					FileCache::setCache($rKey, $rValue);
				}
			} else {
				$rReport['diff'] = [];
				foreach ($rCaches as $rKey => $rValue) {
					$rReport['diff'][$rKey] = self::diff(self::current($rKey), $rValue);
				}
			}
		}
		$rSettings = self::settings();
		if ($rSettings !== null) {
			$rReport['settings'] = $rSettings;
		}
		foreach (['servers' => self::servers($rAuthoritative, $rServerID), 'crontab' => self::crontab($rAuthoritative), 'cluster' => self::cluster()] as $rKey => $rPart) {
			if ($rPart !== null) {
				$rReport[$rKey] = $rPart;
			}
		}
		if (count($rReport) === 1) {
			return null;
		}
		$rTmp = self::dir() . '.apply.json.tmp';
		if (@file_put_contents($rTmp, (string) json_encode($rReport)) !== false) {
			@rename($rTmp, self::dir() . 'apply.json');
		}
		return $rReport;
	}

	/**
	 * The replica's `settings` section, in shadow whatever the flow: the keys
	 * whose value differs from the settings cache cron:cache built from MAIN's
	 * database. It becomes authoritative with the `secrets` section, which
	 * carries what it withholds.
	 *
	 * @return array{etag: string, mode: string, keys: int, differ: list<string>}|null
	 */
	public static function settings(): ?array {
		$rDoc = json_decode((string) @file_get_contents(self::dir() . 'settings.json'), true);
		if (!is_array($rDoc) || !is_array($rDoc['data'] ?? null)) {
			return null;
		}
		foreach ($rDoc['data'] as $rValue) {
			if (!is_string($rValue) && $rValue !== null) {
				return null;
			}
		}
		$rReplica = SettingsRepository::decode($rDoc['data']);
		$rCurrent = FileCache::getCache('settings');
		$rCurrent = is_array($rCurrent) ? $rCurrent : [];
		$rDiffer = [];
		foreach (array_keys($rDoc['data']) as $rKey) {
			if (json_encode(self::loose($rReplica[$rKey] ?? null)) !== json_encode(self::loose($rCurrent[$rKey] ?? null))) {
				$rDiffer[] = (string) $rKey;
			}
		}
		return ['etag' => (string) ($rDoc['etag'] ?? ''), 'mode' => 'shadow', 'keys' => count($rDoc['data']), 'differ' => $rDiffer];
	}

	/**
	 * A whole section the agent stored: `replica/<name>.json`, `{etag, data}`.
	 * Null when there is none; false when it cannot be read.
	 *
	 * @return array{etag: string, data: array<mixed>}|false|null
	 */
	public static function whole(string $rName): array|false|null {
		$rFile = self::dir() . $rName . '.json';
		if (!is_file($rFile)) {
			return null;
		}
		$rDoc = json_decode((string) @file_get_contents($rFile), true);
		if (!is_array($rDoc) || !is_array($rDoc['data'] ?? null) || !is_string($rDoc['etag'] ?? null)) {
			return false;
		}
		return ['etag' => $rDoc['etag'], 'data' => $rDoc['data']];
	}

	/**
	 * The `servers` and `node` sections: the servers cache (CONFIG on), or
	 * what differs from the one cron:cache built from MAIN's database.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function servers(bool $rAuthoritative, int $rServerID): ?array {
		$rServers = self::whole(ReplicaSections::SERVERS);
		$rNode = self::whole(ReplicaSections::NODE);
		if ($rServers === null && $rNode === null) {
			return null;
		}
		$rReport = ['etag' => is_array($rServers) ? $rServers['etag'] : '', 'node_etag' => is_array($rNode) ? $rNode['etag'] : ''];
		if ($rServers === null || $rNode === null) {
			return $rReport + ['mode' => 'incomplete'];
		}
		$rRows = is_array($rServers) && is_array($rNode) ? self::serverRows($rServers['data'], $rNode['data'], $rServerID, SettingsManager::getAll()) : null;
		if ($rRows === null) {
			return $rReport + ['mode' => 'refused'];
		}
		if ($rAuthoritative) {
			FileCache::setCache('servers', $rRows);
			return $rReport + ['mode' => 'applied', 'rows' => count($rRows)];
		}
		$rCurrent = FileCache::getCache('servers');
		$rCurrent = is_array($rCurrent) ? $rCurrent : [];
		$rDiffer = [];
		foreach (array_intersect_key($rRows, $rCurrent) as $rID => $rRow) {
			$rFields = array_keys(ReplicaSections::SERVER_FIELDS + ($rID === $rServerID ? ReplicaSections::NODE_FIELDS : []));
			foreach ($rFields as $rField) {
				if (json_encode(self::loose($rRow[$rField] ?? null)) !== json_encode(self::loose($rCurrent[$rID][$rField] ?? null))) {
					$rDiffer[] = $rID . '.' . $rField;
				}
			}
		}
		return $rReport + [
			'mode' => 'shadow', 'rows' => count($rRows),
			'missing' => array_values(array_map('intval', array_keys(array_diff_key($rCurrent, $rRows)))),
			'extra' => array_values(array_map('intval', array_keys(array_diff_key($rRows, $rCurrent)))),
			'differ' => array_slice($rDiffer, 0, self::MAX_DIFFER),
		];
	}

	/**
	 * The servers cache from the `servers` and `node` sections, keyed by id as
	 * ServerRepository::getAll builds it; null unless both are MAIN's for this
	 * node (the node section names it, and the list holds its row).
	 *
	 * @param array<mixed> $rServers the `servers` section's data
	 * @param array<mixed> $rNode the `node` section's data
	 * @param array<string, mixed> $rSettings this node's settings (live_streaming_pass)
	 * @return array<int, array<string, mixed>>|null
	 */
	public static function serverRows(array $rServers, array $rNode, int $rServerID, array $rSettings): ?array {
		if ($rServerID <= 0 || ($rNode['id'] ?? null) !== $rServerID || !self::listOf($rServers['servers'] ?? null, 'is_array') || !self::listOf($rServers['nodes'] ?? [], 'is_array')) {
			return null;
		}
		foreach ($rServers['nodes'] ?? [] as $rEntry) {
			if (!is_int($rEntry['sid'] ?? null) || !is_int($rEntry['gen'] ?? null) || !in_array($rEntry['state'] ?? null, ReplicaSections::NODE_STATES, true) || !is_string($rEntry['ed_pub'] ?? null)) {
				return null;
			}
		}
		$rColumns = array_fill_keys(array_keys(ReplicaSections::SERVER_FIELDS + ReplicaSections::NODE_FIELDS), null) + array_fill_keys(ReplicaSections::SERVER_LOCAL, null);
		$rOut = [];
		foreach ($rServers['servers'] as $rServer) {
			$rID = $rServer['id'] ?? null;
			if (!is_int($rID) || $rID <= 0 || isset($rOut[$rID])) {
				return null;
			}
			$rRow = ReplicaSections::typed($rServer, ReplicaSections::SERVER_FIELDS);
			if ($rID === $rServerID) {
				$rRow = ReplicaSections::typed($rNode, ReplicaSections::NODE_FIELDS) + $rRow;
			}
			$rRow = ServerRepository::decorate($rRow + $rColumns, $rSettings);
			// Liveness is MAIN's to judge: this node tries every enabled server.
			$rRow['server_online'] = !empty($rRow['enabled']) || $rID === $rServerID;
			$rRow['cluster_health'] = null;
			if (!isset($rRow['order'])) {
				$rRow['order'] = 0;
			}
			unset($rRow['php_pids']);
			$rOut[$rID] = $rRow;
		}
		return isset($rOut[$rServerID]) ? $rOut : null;
	}

	/**
	 * The `crontab` section: the jobs this node's crontab runs (CONFIG on), or
	 * which of MAIN's rows it leaves out and adds.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function crontab(bool $rAuthoritative): ?array {
		$rDoc = self::whole(ReplicaSections::CRONTAB);
		if ($rDoc === null) {
			return null;
		}
		$rJobs = is_array($rDoc) ? self::jobs($rDoc['data']) : null;
		$rReport = ['etag' => is_array($rDoc) ? $rDoc['etag'] : ''];
		if ($rJobs === null) {
			return $rReport + ['mode' => 'refused'];
		}
		if ($rAuthoritative) {
			FileCache::setCache(self::CRON_CACHE, $rJobs);
			return $rReport + ['mode' => 'applied', 'jobs' => count($rJobs)];
		}
		try {
			$rCurrent = self::cronJobs(DatabaseFactory::get()) ?? [];
		} catch (\Throwable) {
			$rCurrent = [];
		}
		$rLine = static fn(array $rJob): string => $rJob['time'] . ' ' . $rJob['filename'];
		$rHave = array_combine(array_map($rLine, $rCurrent), array_column($rCurrent, 'filename')) ?: [];
		$rWant = array_combine(array_map($rLine, $rJobs), array_column($rJobs, 'filename')) ?: [];
		return $rReport + ['mode' => 'shadow', 'jobs' => count($rJobs), 'missing' => array_values(array_diff_key($rHave, $rWant)), 'extra' => array_values(array_diff_key($rWant, $rHave))];
	}

	/**
	 * @param array<mixed> $rData the `crontab` section's data
	 * @return list<array{filename: string, time: string}>|null
	 */
	private static function jobs(array $rData): ?array {
		if (!self::listOf($rData['jobs'] ?? null, 'is_array')) {
			return null;
		}
		$rOut = [];
		foreach ($rData['jobs'] as $rJob) {
			$rJob = ReplicaSections::cronJob($rJob);
			if ($rJob === null) {
				return null;
			}
			$rOut[] = $rJob;
		}
		return $rOut;
	}

	/**
	 * The `cluster` section, compared with the policy the agent holds
	 * (`agent.json`, beside the replica): the agent adopts the policy itself,
	 * and no PHP on the node reads it.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function cluster(): ?array {
		$rDoc = self::whole(ReplicaSections::CLUSTER);
		if ($rDoc === null) {
			return null;
		}
		$rData = is_array($rDoc) ? $rDoc['data'] : [];
		$rReport = ['etag' => is_array($rDoc) ? $rDoc['etag'] : ''];
		if (!self::listOf($rData['main_urls'] ?? null, 'is_string') || !is_int($rData['policy_ver'] ?? null) || !is_string($rData['panel_sign_pub'] ?? null)) {
			return $rReport + ['mode' => 'refused'];
		}
		$rAgent = json_decode((string) @file_get_contents(dirname(self::dir()) . '/agent.json'), true);
		$rAgent = is_array($rAgent) ? $rAgent : [];
		$rDiffer = [];
		foreach (['main_urls', 'panel_sign_pub', 'policy_ver'] as $rKey) {
			if (($rAgent[$rKey] ?? null) !== $rData[$rKey]) {
				$rDiffer[] = $rKey;
			}
		}
		return $rReport + ['mode' => 'shadow', 'policy_ver' => $rData['policy_ver'], 'differ' => $rDiffer];
	}

	/** Scalars as strings, as a driver reads them, at any depth. */
	private static function loose(mixed $rValue): mixed {
		if (is_array($rValue)) {
			return array_map([self::class, 'loose'], $rValue);
		}
		return $rValue === null ? null : (is_bool($rValue) ? (string) (int) $rValue : (string) $rValue);
	}

	/**
	 * The caches, in cron:cache's shapes, from the replica's blocklist data;
	 * null when the data is not what MAIN sends.
	 *
	 * @param array<string, mixed> $rData
	 * @return array<string, mixed>|null
	 */
	public static function caches(array $rData): ?array {
		$rIPs = $rData['ip'] ?? [];
		$rASNs = $rData['asn'] ?? [];
		$rUAs = $rData['ua'] ?? [];
		$rISPs = $rData['isp'] ?? [];
		$rRTMPs = $rData['rtmp'] ?? [];
		if (!self::listOf($rIPs, 'is_string') || !self::listOf($rASNs, 'is_int') || !self::listOf($rUAs, 'is_array') || !self::listOf($rISPs, 'is_array') || !self::listOf($rRTMPs, 'is_array')) {
			return null;
		}
		$rUA = [];
		foreach ($rUAs as $rRow) {
			$rID = (int) ($rRow['id'] ?? 0);
			$rUA[$rID] = ['id' => $rID, 'exact_match' => (int) ($rRow['exact_match'] ?? 0), 'blocked_ua' => strtolower((string) ($rRow['user_agent'] ?? ''))];
		}
		$rISP = [];
		foreach ($rISPs as $rRow) {
			$rISP[] = ['id' => (int) ($rRow['id'] ?? 0), 'isp' => (string) ($rRow['isp'] ?? ''), 'blocked' => (int) ($rRow['blocked'] ?? 0)];
		}
		// As cron:cache on MAIN: keyed by the resolved address.
		$rRTMP = [];
		foreach ($rRTMPs as $rRow) {
			$rRTMP[gethostbyname((string) ($rRow['ip'] ?? ''))] = ['password' => (string) ($rRow['password'] ?? ''), 'push' => (bool) ($rRow['push'] ?? false), 'pull' => (bool) ($rRow['pull'] ?? false)];
		}
		return ['blocked_ips' => array_values($rIPs), 'blocked_servers' => array_values($rASNs), 'blocked_ua' => $rUA, 'blocked_isp' => $rISP, 'rtmp_ips' => $rRTMP];
	}

	/**
	 * What the node uses today, for the shadow diff: the cache cron:cache built
	 * from MAIN's database, or for RTMP (which an LB never cached) the database.
	 */
	private static function current(string $rKey): mixed {
		if ($rKey === 'rtmp_ips' && class_exists(BlocklistService::class)) {
			try {
				return BlocklistService::getAllowedRTMP();
			} catch (\Throwable) {
				return [];
			}
		}
		return FileCache::getCache($rKey);
	}

	/**
	 * Entries of the current cache the replica lacks, and the reverse, compared
	 * by value (rows by their normalised content; the drivers type them apart),
	 * and by key where the cache is keyed by something outside its rows.
	 *
	 * @return array{missing: int, extra: int}
	 */
	private static function diff(mixed $rCurrent, mixed $rReplica): array {
		$rEntries = static function (mixed $rCache): array {
			$rCache = is_array($rCache) ? $rCache : [];
			$rOut = [];
			foreach ($rCache as $rKey => $rEntry) {
				$rValue = is_array($rEntry) ? (string) json_encode(array_map('strval', $rEntry)) : (string) $rEntry;
				$rOut[] = array_is_list($rCache) ? $rValue : $rKey . '=' . $rValue;
			}
			return array_count_values($rOut);
		};
		$rHave = $rEntries($rCurrent);
		$rWant = $rEntries($rReplica);
		return ['missing' => array_sum(array_diff_key($rHave, $rWant)), 'extra' => array_sum(array_diff_key($rWant, $rHave))];
	}

	private static function listOf(mixed $rList, callable $rIs): bool {
		if (!is_array($rList) || !array_is_list($rList)) {
			return false;
		}
		foreach ($rList as $rEntry) {
			if (!$rIs($rEntry)) {
				return false;
			}
		}
		return true;
	}
}
