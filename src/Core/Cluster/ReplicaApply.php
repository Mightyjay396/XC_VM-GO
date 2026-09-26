<?php

namespace XcVm\Core\Cluster;

use XcVm\Core\Cache\FileCache;
use XcVm\Domain\Security\BlocklistService;

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
 * Either way the report goes to `replica/apply.json`.
 */
final class ReplicaApply {
	public const CACHES = ['blocked_ips', 'blocked_servers', 'blocked_ua', 'blocked_isp', 'rtmp_ips'];

	private static ?string $rDir = null;

	/** Tests: another replica directory; null restores the default. */
	public static function useDir(?string $rDir): void {
		self::$rDir = $rDir;
	}

	public static function dir(): string {
		return self::$rDir ?? ((defined('CONFIG_PATH') ? CONFIG_PATH : '/home/xc_vm/config/') . 'cluster/replica/');
	}

	/**
	 * Apply the materialised blocklist. Null when there is none, or it is not
	 * one the agent wrote.
	 *
	 * @return array{at: int, seq: int, etag: string, mode: string, diff?: array<string, array{missing: int, extra: int}>}|null
	 */
	public static function run(bool $rAuthoritative, ?int $rNow = null): ?array {
		$rDoc = json_decode((string) @file_get_contents(self::dir() . 'blocklist.json'), true);
		$rCaches = is_array($rDoc) && is_array($rDoc['data'] ?? null) ? self::caches($rDoc['data']) : null;
		if ($rCaches === null) {
			return null;
		}
		$rReport = ['at' => $rNow ?? time(), 'seq' => (int) ($rDoc['seq'] ?? 0), 'etag' => (string) ($rDoc['etag'] ?? ''), 'mode' => $rAuthoritative ? 'applied' : 'shadow'];
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
		$rTmp = self::dir() . '.apply.json.tmp';
		if (@file_put_contents($rTmp, (string) json_encode($rReport)) !== false) {
			@rename($rTmp, self::dir() . 'apply.json');
		}
		return $rReport;
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
