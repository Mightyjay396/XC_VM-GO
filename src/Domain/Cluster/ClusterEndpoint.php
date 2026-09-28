<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\ClusterSettings;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Config\SettingsRepository;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * MAIN endpoint changes (plan §3, "Endpoint changes"): when an address or a
 * port the nodes reach the cluster API on changes, the nodes must not lose
 * MAIN. What they use is the policy's URL list (ClusterPolicy): MAIN's
 * private_ip, server_ip and `cluster_main_host` on its HTTP broadcast port
 * (or on `cluster_api_port` when that is not 0), and its HTTPS broadcast
 * port when the policy lists HTTPS.
 *
 * - The change is announced: `cluster_policy_ver` goes up, so every agent
 *   refetches the policy within one heartbeat (the reply carries the
 *   version) and moves to the new URLs. A change of MAIN's server row is
 *   announced only while a node may use the URLs: the API is on and a node
 *   is in mode ≥ 1 and not revoked.
 * - An old plain-HTTP port stays up for the cluster API alone for GRACE (7
 *   days): MAIN's nginx gets a server block on it that serves `/cluster/v1/`
 *   and nothing else (`bin/nginx/conf/cluster.d/old_port.conf`, rendered by
 *   ClusterNginxConfig), and the policy lists it on MAIN's current addresses
 *   after the new URLs, so a node that was offline during the change still
 *   finds MAIN (`cluster_legacy_ports`: port => expiry).
 * - Every other URL the policy no longer lists (an old server_ip or
 *   private_ip, an old HTTPS port) stays in it, last, for GRACE too
 *   (`cluster_legacy_urls`: url => expiry, at most MAX_URLS, the latest
 *   change first). nginx listens on every address, so an old address MAIN
 *   still holds keeps answering; an old HTTPS port (the old row's HTTPS
 *   broadcast port, which nginx served) gets a TLS server of its own in
 *   old_port.conf, with the public server's certificate. The policy lists
 *   a kept https:// URL only while it lists HTTPS, and a kept http:// one
 *   never under `https_required` (ClusterPolicy).
 * - A settings save of `cluster_transport` or `cluster_main_host` moves the
 *   URLs too, and goes the same way (storeSettings()): an old DNS name's
 *   URLs are kept, in the save's own UPDATE. A transport change alone keeps
 *   nothing: it drops whole schemes, which no kept URL is listed with.
 * - cron:cluster drops expired ports and URLs, bumps the policy again and
 *   renders the nginx config again so nginx releases them.
 * - It releases a kept port sooner, once every node uses the new URLs
 *   (release()): every node that may use MAIN's URLs is heard, says in its
 *   hello and heartbeat that it dials the current policy
 *   (`cluster_nodes.policy_ver`, nodeUses()), and last reached MAIN on
 *   another port (`cluster_nodes.main_port`, nginx's `$server_port`), and
 *   no enrolment code may still dial an old URL. An agent that does not say
 *   which policy it dials keeps the 7 days, and so does a kept plain-HTTP
 *   port or URL under `https_required`.
 * - The admin drops a kept URL at once with `cluster:endpoint drop` (drop()),
 *   for an old DNS name or address MAIN gives up.
 */
final class ClusterEndpoint {
	use DatabaseAware;

	public const GRACE = 7 * 86400;

	/** At most this many old URLs are kept; the latest changes win. */
	public const MAX_URLS = 8;

	/** The largest policy version a node may say it dials (`cluster_nodes.policy_ver`, int unsigned). */
	private const MAX_VER = 4294967295;

	/**
	 * The settings columns of the endpoint state, read again from the
	 * database before use (stored()): the kept lists and the version, and
	 * what decides the policy's URLs besides MAIN's row.
	 */
	private const STATE = ['cluster_api_port', 'cluster_transport', 'cluster_main_host', 'cluster_policy_ver', 'cluster_legacy_ports', 'cluster_legacy_urls'];

	/** The settings that move the policy's URLs with MAIN's row unchanged: a save of them is announced (storeSettings()). */
	private const POLICY_SETTINGS = ['cluster_transport', 'cluster_main_host'];

	/** A write over the endpoint state as read is tried this many times (recordMainChange(), storeSettings()). */
	private const TRIES = 3;

	/**
	 * Old ports still served for the cluster API: port => expiry (unix time).
	 *
	 * @param array<string, mixed> $rSettings
	 * @return array<int, int>
	 */
	public static function legacyPorts(array $rSettings, ?int $rNow = null): array {
		$rNow ??= ClusterClock::now();
		$rOut = [];
		$rDoc = json_decode((string) ($rSettings['cluster_legacy_ports'] ?? ''), true);
		foreach (is_array($rDoc) ? $rDoc : [] as $rPort => $rUntil) {
			if ((int) $rPort >= 1 && (int) $rPort <= 65535 && (int) $rUntil > $rNow) {
				$rOut[(int) $rPort] = (int) $rUntil;
			}
		}
		ksort($rOut);
		return $rOut;
	}

	/**
	 * Old URLs still listed in the policy: url => expiry (unix time), the
	 * latest change first. An entry that is no cluster API URL is dropped.
	 *
	 * @param array<string, mixed> $rSettings
	 * @return array<string, int>
	 */
	public static function legacyUrls(array $rSettings, ?int $rNow = null): array {
		$rNow ??= ClusterClock::now();
		$rOut = [];
		$rDoc = json_decode((string) ($rSettings['cluster_legacy_urls'] ?? ''), true);
		foreach (is_array($rDoc) ? $rDoc : [] as $rUrl => $rUntil) {
			if (self::parseUrl((string) $rUrl) !== null && (int) $rUntil > $rNow) {
				$rOut[(string) $rUrl] = (int) $rUntil;
			}
		}
		arsort($rOut);
		return $rOut;
	}

	/**
	 * Old HTTPS ports: those of the kept https:// URLs, which nginx serves
	 * over TLS for the cluster API alone unless it serves the port otherwise
	 * (ClusterNginxConfig). port => expiry, the latest.
	 *
	 * @param array<string, mixed> $rSettings
	 * @return array<int, int>
	 */
	public static function legacyHttpsPorts(array $rSettings, ?int $rNow = null): array {
		$rOut = [];
		foreach (self::legacyUrls($rSettings, $rNow) as $rUrl => $rUntil) {
			$rParsed = self::parseUrl($rUrl);
			if ($rParsed !== null && $rParsed[0] === 'https') {
				$rOut[$rParsed[1]] = max($rOut[$rParsed[1]] ?? 0, $rUntil);
			}
		}
		ksort($rOut);
		return $rOut;
	}

	/**
	 * The scheme and port of a cluster API URL as the policy writes it
	 * (`http[s]://<host>:<port>/cluster/v1/`), or null.
	 *
	 * @return array{0: string, 1: int}|null
	 */
	public static function parseUrl(string $rUrl): ?array {
		if (!preg_match('#^(https?)://(?:\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9.-]+):(\d{1,5})/cluster/v1/$#', $rUrl, $rMatch) || (int) $rMatch[2] < 1 || (int) $rMatch[2] > 65535) {
			return null;
		}
		return [$rMatch[1], (int) $rMatch[2]];
	}

	/**
	 * MAIN's `servers` row went from $rOld to $rNew: an admin's save of its
	 * server page (ServerService), or cron:root_signals' automatic server_ip
	 * rewrite. When the URLs the policy lists change and a node may use them,
	 * the change is announced: an old HTTP broadcast port is kept (while the
	 * API has no port of its own), every other URL the policy no longer lists
	 * is kept (an https:// one only on the old row's HTTPS broadcast port),
	 * one it lists again is not, and the policy version goes up. Returns
	 * whether it was announced.
	 *
	 * The write goes over the kept lists as read. When another process
	 * stored them meanwhile (a settings save, the other of an admin's save
	 * and cron:root_signals, cron:cluster), they are read again, with the
	 * transport and the DNS name, and what the policy lists now is taken from
	 * MAIN's row as stored, which holds a change of the row made meanwhile;
	 * then it retries, so what the other kept is merged, not lost. The last
	 * of TRIES tries writes regardless, as before: a missed announcement can
	 * strand the nodes.
	 *
	 * @param array<string, mixed> $rOld
	 * @param array<string, mixed> $rNew
	 * @param array<string, mixed> $rSettings The loaded settings; the endpoint state is read again (stored()).
	 */
	public static function recordMainChange(array $rOld, array $rNew, array $rSettings, string $rActor = 'admin'): bool {
		if (empty($rSettings['cluster_api_enabled'])) {
			return false;
		}
		$rTry = 0;
		do {
			$rTry++;
			$rSettings = self::stored($rSettings);
			// The URLs the policy lists for each row, without the old ones kept.
			$rBase = ['cluster_legacy_ports' => '', 'cluster_legacy_urls' => ''] + $rSettings;
			$rFrom = ClusterPolicy::current($rBase, $rOld)['main_urls'];
			$rTo = ClusterPolicy::current($rBase, $rNew)['main_urls'];
			if ($rFrom === $rTo || !self::nodesListening()) {
				return false;
			}
			$rPorts = self::legacyPorts($rSettings);
			$rHttpFrom = intval($rOld['http_broadcast_port'] ?? 0);
			$rHttpTo = intval($rNew['http_broadcast_port'] ?? 0);
			if (intval($rSettings['cluster_api_port'] ?? 0) === 0 && $rHttpFrom >= 1 && $rHttpFrom !== $rHttpTo) {
				$rPorts = self::keep($rPorts, $rHttpFrom, $rHttpTo);
			}
			// What the policy lists with the old port kept: an old URL it no
			// longer lists is kept, the latest change first. An https:// URL only
			// on the HTTPS broadcast port the old row stored: nginx served it over
			// TLS until now and keeps the socket, so ClusterNginxConfig binds it
			// unchecked. The policy's 443 for a row with no HTTPS port (NULL) was
			// never nginx's, and another program may hold it.
			$rListed = ClusterPolicy::current(['cluster_legacy_ports' => (string) json_encode($rPorts)] + $rBase, self::mainRow($rNew))['main_urls'];
			$rTlsPort = intval($rOld['https_broadcast_port'] ?? 0);
			$rKept = [];
			foreach (array_diff($rFrom, $rListed) as $rUrl) {
				$rParsed = self::parseUrl($rUrl);
				if ($rParsed !== null && ($rParsed[0] !== 'https' || $rParsed[1] === $rTlsPort)) {
					$rKept[] = $rUrl;
				}
			}
			$rUrls = array_fill_keys($rKept, ClusterClock::now() + self::GRACE) + array_diff_key(self::legacyUrls($rSettings), array_flip($rListed));
			arsort($rUrls);
			// Over the lists as read, while there is a list of URLs to read
			// (migration 044) and tries left.
			$rWas = $rTry < self::TRIES && array_key_exists('cluster_legacy_urls', $rSettings) ? [(string) ($rSettings['cluster_legacy_ports'] ?? ''), (string) ($rSettings['cluster_legacy_urls'] ?? '')] : null;
		} while (!self::save($rPorts, array_slice($rUrls, 0, self::MAX_URLS, true), $rWas));
		ClusterAudit::log('cluster.endpoint_change', null, ['urls_from' => $rFrom, 'urls_to' => $rTo, 'kept_urls' => $rKept, 'kept_ports' => $rPorts], $rActor);
		return true;
	}

	/**
	 * Store a settings save that posts the transport or MAIN's DNS name in
	 * its URLs (`cluster_transport`, `cluster_main_host`; SettingsService::
	 * edit()): $rSet and $rData are its SET clause and values (QueryHelper::
	 * prepareArray()), $rSave the values it stores, $rMain MAIN's `servers`
	 * row as the form read it.
	 *
	 * When the save changes either, the policy version goes up in the same
	 * UPDATE, as before. When the policy's URLs change and a node may use them
	 * (the API on once the save is stored, a node in mode ≥ 1 and not
	 * revoked), the change is announced as recordMainChange() announces one:
	 * every URL the policy no longer lists is kept for GRACE, in that UPDATE
	 * too, where the listing rules allow it (afterSettingsChange()), one it
	 * lists again is not, and it is audited. A change of the DNS name keeps
	 * the old name's URLs; a change of the transport alone keeps nothing, as
	 * it drops only whole schemes.
	 *
	 * The transport and the name are compared with the settings row as
	 * stored, not with the caller's settings cache, which may predate another
	 * admin's save: a save that finds them stored already (posted unchanged,
	 * or stored by another save) is stored without raising the version, and
	 * one that moves them back is announced.
	 *
	 * The UPDATE goes over the endpoint state as read (the version and both
	 * kept lists), read again with MAIN's row from the database, and counts
	 * as stored only when it changed the row. A change another process stored
	 * meanwhile (an admin's save of MAIN's row, cron:root_signals' rewrite,
	 * cron:cluster, another settings save) makes it read again and retry, up
	 * to TRIES times, so neither is lost. After that, or when a changing save
	 * finds no `cluster_legacy_urls` (before migration 044), the save is
	 * stored as before: with the version raised and nothing kept. So is a
	 * save when the settings row cannot be read: a needless announcement
	 * costs each node a hello, a missed one can strand them. Returns whether
	 * the save was stored.
	 *
	 * @param list<mixed> $rData
	 * @param array<string, mixed> $rSave
	 * @param array<string, mixed> $rMain
	 */
	public static function storeSettings(string $rSet, array $rData, array $rSave, array $rMain, string $rActor = 'admin'): bool {
		$rDb = self::db();
		for ($rTry = 0; $rTry < self::TRIES; $rTry++) {
			$rRow = $rDb->query('SELECT * FROM `settings` LIMIT 1;') ? $rDb->get_row() : null;
			if (!is_array($rRow) || $rRow === []) {
				break;
			}
			$rNew = array_intersect_key($rSave, array_flip(['cluster_api_enabled', 'cluster_api_port', ...self::POLICY_SETTINGS])) + $rRow;
			$rFrom = $rTo = [];
			foreach (self::POLICY_SETTINGS as $rKey) {
				if ((string) ($rRow[$rKey] ?? '') !== (string) ($rNew[$rKey] ?? '')) {
					$rFrom[$rKey] = (string) ($rRow[$rKey] ?? '');
					$rTo[$rKey] = (string) ($rNew[$rKey] ?? '');
				}
			}
			if ($rTo === []) {
				// As stored: posted unchanged (the settings form posts both with
				// every save), or stored already by a save that announced it.
				return (bool) $rDb->query('UPDATE `settings` SET ' . $rSet . ';', ...$rData);
			}
			if (!array_key_exists('cluster_legacy_urls', $rRow)) {
				break;
			}
			$rWas = [(string) ($rRow['cluster_legacy_ports'] ?? ''), (string) ($rRow['cluster_legacy_urls'] ?? '')];
			$rKeep = self::afterSettingsChange($rRow, $rNew, self::mainRow($rMain));
			$rUrlDoc = $rKeep === null ? $rWas[1] : ($rKeep['urls'] === [] ? '' : (string) json_encode($rKeep['urls'], JSON_UNESCAPED_SLASHES));
			$rStored = $rDb->query(
				'UPDATE `settings` SET ' . $rSet . ($rKeep === null ? '' : ', `cluster_legacy_urls` = ?') . ", `cluster_policy_ver` = `cluster_policy_ver` + 1 WHERE `cluster_policy_ver` = ? AND COALESCE(`cluster_legacy_ports`, '') = ? AND COALESCE(`cluster_legacy_urls`, '') = ?;",
				...array_merge($rData, $rKeep === null ? [] : [$rUrlDoc], [intval($rRow['cluster_policy_ver'] ?? 1), $rWas[0], $rWas[1]])
			);
			// Stored when the UPDATE changed the row (the affected-row count),
			// which it always does once it matches: the version goes up. One
			// that matched nothing left another process's state, even one that
			// stored the same transport and name, and none of this save's
			// columns. The next try reads again; a write that did go through
			// though none was counted is found stored there.
			if ($rStored && $rDb->num_rows() > 0) {
				if ($rKeep !== null) {
					ClusterAudit::log('cluster.endpoint_change', null, ['urls_from' => $rKeep['from'], 'urls_to' => $rKeep['to'], 'kept_urls' => $rKeep['kept'], 'kept_ports' => self::legacyPorts($rRow), 'settings_from' => $rFrom, 'settings_to' => $rTo], $rActor);
				}
				return true;
			}
		}
		return (bool) $rDb->query('UPDATE `settings` SET ' . $rSet . ', `cluster_policy_ver` = `cluster_policy_ver` + 1;', ...$rData);
	}

	/**
	 * What a settings save keeps when it changes the policy's URLs: from the
	 * stored settings $rStored to $rNew (the save's values over them), with
	 * MAIN's row $rMain. Null when nothing is announced: the API is off once
	 * the save is stored, the URLs stay the same, or no node may use them.
	 *
	 * Both URL lists are taken on the API port the save stores: a new
	 * `cluster_api_port` is announced, and its old port kept, once it is
	 * stored (recordApiPortChange()). A URL the policy no longer lists is
	 * kept only where the listing rules list it (ClusterPolicy): an https://
	 * one only on the HTTPS broadcast port the row stores (nginx never held
	 * the policy's 443 for a row with none) and while the new transport lists
	 * HTTPS, an http:// one never under `https_required`. A transport change
	 * alone moves no host or port: what it drops is a scheme the new
	 * transport does not list, so it keeps nothing.
	 *
	 * @param array<string, mixed> $rStored
	 * @param array<string, mixed> $rNew
	 * @param array<string, mixed> $rMain
	 * @return array{urls: array<string, int>, from: list<string>, to: list<string>, kept: list<string>}|null
	 */
	private static function afterSettingsChange(array $rStored, array $rNew, array $rMain): ?array {
		if (empty($rNew['cluster_api_enabled'])) {
			return null;
		}
		// The URLs the policy lists before and after, without the old ones kept.
		$rBase = ['cluster_legacy_ports' => '', 'cluster_legacy_urls' => '', 'cluster_api_port' => $rNew['cluster_api_port'] ?? 0];
		$rFrom = ClusterPolicy::current($rBase + $rStored, $rMain)['main_urls'];
		$rTo = ClusterPolicy::current($rBase + $rNew, $rMain)['main_urls'];
		if ($rFrom === $rTo || !self::nodesListening()) {
			return null;
		}
		$rTlsPort = intval($rMain['https_broadcast_port'] ?? 0);
		$rGone = [];
		foreach (array_diff($rFrom, $rTo) as $rUrl) {
			$rParsed = self::parseUrl($rUrl);
			if ($rParsed !== null && ($rParsed[0] !== 'https' || $rParsed[1] === $rTlsPort)) {
				$rGone[$rUrl] = ClusterClock::now() + self::GRACE;
			}
		}
		// Only what the new policy lists as a kept URL.
		$rListed = $rGone === [] ? [] : ClusterPolicy::current(['cluster_legacy_urls' => (string) json_encode($rGone, JSON_UNESCAPED_SLASHES)] + $rNew, $rMain)['main_urls'];
		$rKept = array_values(array_intersect(array_keys($rGone), $rListed));
		$rUrls = array_intersect_key($rGone, array_flip($rKept)) + array_diff_key(self::legacyUrls($rStored), array_flip($rTo));
		arsort($rUrls);
		return ['urls' => array_slice($rUrls, 0, self::MAX_URLS, true), 'from' => $rFrom, 'to' => $rTo, 'kept' => $rKept];
	}

	/**
	 * MAIN's `servers` row as stored, or $rMain when it cannot be read. The
	 * servers cache a settings save reads may be 10 s old, and a URL the
	 * policy lists for a stale row (an old server_ip) would count as current
	 * and leave the kept list.
	 *
	 * @param array<string, mixed> $rMain
	 * @return array<string, mixed>
	 */
	private static function mainRow(array $rMain): array {
		try {
			$rDb = self::db();
			if ($rDb->query('SELECT * FROM `servers` WHERE `is_main` = 1 LIMIT 1;')) {
				$rRow = $rDb->get_row();
				if (is_array($rRow) && $rRow !== []) {
					return $rRow;
				}
			}
		} catch (\Throwable) {
			// The row the form read, then.
		}
		return $rMain;
	}

	/**
	 * The old ports to keep once `cluster_api_port` goes from $rOld to $rNew
	 * (0: the API is on the broadcast port), or null when there is nothing to
	 * announce: the API's port stays the same, or the API is off, so no node
	 * uses it. A settings save renders nginx with it before the new port is
	 * stored (ClusterNginxConfig), then records it (recordApiPortChange()).
	 *
	 * @param array<string, mixed> $rSettings The stored settings.
	 * @param array<string, mixed> $rMain The main server's `servers` row.
	 * @return array<int, int>|null port => expiry
	 */
	public static function afterApiPortChange(int $rOld, int $rNew, array $rSettings, array $rMain): ?array {
		$rBroadcast = intval($rMain['http_broadcast_port'] ?? 0);
		$rFrom = $rOld > 0 ? $rOld : $rBroadcast;
		$rTo = $rNew > 0 ? $rNew : $rBroadcast;
		if ($rFrom < 1 || $rFrom === $rTo || empty($rSettings['cluster_api_enabled'])) {
			return null;
		}
		return self::keep(self::legacyPorts($rSettings), $rFrom, $rTo);
	}

	/**
	 * `cluster_api_port` went from $rOld to $rNew and is stored: announce it
	 * and keep the old port (afterApiPortChange()). Returns whether it was
	 * recorded.
	 *
	 * @param array<string, mixed> $rSettings The settings before the change; the kept ports are read again (stored()).
	 * @param array<string, mixed> $rMain The main server's `servers` row.
	 */
	public static function recordApiPortChange(int $rOld, int $rNew, array $rSettings, array $rMain): bool {
		$rPorts = self::afterApiPortChange($rOld, $rNew, self::stored($rSettings), $rMain);
		if ($rPorts === null) {
			return false;
		}
		self::save($rPorts);
		ClusterAudit::log('cluster.endpoint_change', null, ['api_port_from' => $rOld, 'api_port_to' => $rNew, 'kept' => $rPorts], 'admin');
		return true;
	}

	/**
	 * Drop expired ports and URLs. Returns true when some were dropped: the
	 * caller then renders the nginx config again (ClusterNginxConfig) so
	 * nginx releases them. The write goes over the lists as read (once
	 * migration 044 added the URL list): when a change stored them meanwhile,
	 * nothing is written, and the next pass prunes.
	 *
	 * @param array<string, mixed> $rSettings
	 */
	public static function prune(array $rSettings): bool {
		$rSettings = self::stored($rSettings);
		$rPorts = self::legacyPorts($rSettings);
		$rUrls = self::legacyUrls($rSettings);
		$rPortDoc = json_decode((string) ($rSettings['cluster_legacy_ports'] ?? ''), true);
		$rUrlDoc = json_decode((string) ($rSettings['cluster_legacy_urls'] ?? ''), true);
		$rUrlsGone = is_array($rUrlDoc) && count($rUrlDoc) !== count($rUrls);
		if (!$rUrlsGone && (!is_array($rPortDoc) || count($rPortDoc) === count($rPorts))) {
			return false;
		}
		if (!array_key_exists('cluster_legacy_urls', $rSettings)) {
			self::save($rPorts);
		} elseif (!self::save($rPorts, $rUrls, [(string) ($rSettings['cluster_legacy_ports'] ?? ''), (string) ($rSettings['cluster_legacy_urls'] ?? '')])) {
			return false;
		}
		ClusterAudit::log('cluster.endpoint_expired', null, ['kept' => array_keys($rPorts), 'kept_urls' => array_keys($rUrls)], 'cron');
		return true;
	}

	/**
	 * Release kept ports before their 7 days once every node uses the new
	 * URLs (plan §3). Every node that may use MAIN's URLs (mode ≥ 1, not
	 * revoked, its server not deleted) must be heard, say it dials the
	 * current policy (which lists the new URLs first, at a version at or
	 * above the one that announced each change) and have reached MAIN on a
	 * known port, and no enrolment by code may be under way (portsInUse()).
	 * Then each kept port none of them, nor a node in mode 0 still heard,
	 * last reached MAIN on goes, from the kept ports and with every kept URL
	 * on it. The policy version goes up, and the caller renders the nginx
	 * config again (cron:cluster) so nginx closes the port. Returns whether
	 * one went.
	 *
	 * Under `https_required` a kept plain-HTTP port or http:// URL keeps its
	 * 7 days: the nodes reach MAIN over HTTPS alone, so none reports a plain
	 * port, yet a node's way back when HTTPS fails is the signed challenge
	 * over the plain-HTTP URLs it has known.
	 *
	 * @param array<string, mixed> $rSettings The loaded settings; the endpoint state and the transport are read again (stored()).
	 */
	public static function release(array $rSettings): bool {
		$rSettings = self::stored($rSettings);
		$rPorts = self::legacyPorts($rSettings);
		$rUrls = self::legacyUrls($rSettings);
		if ($rPorts === [] && $rUrls === []) {
			return false;
		}
		$rVer = intval($rSettings['cluster_policy_ver'] ?? 1);
		$rInUse = self::portsInUse($rVer, ClusterSettings::int('cluster_offline_after_sec', $rSettings['cluster_offline_after_sec'] ?? null));
		if ($rInUse === null) {
			return false;
		}
		// The ports that may go, and the kept URLs on each: under
		// https_required, only those of the kept https:// URLs.
		$rPlainStays = (string) ($rSettings['cluster_transport'] ?? 'auto') === 'https_required';
		$rCandidates = $rPlainStays ? [] : array_keys($rPorts);
		$rUrlPorts = [];
		foreach (array_keys($rUrls) as $rUrl) {
			$rParsed = self::parseUrl($rUrl) ?? ['', 0];
			if (!$rPlainStays || $rParsed[0] === 'https') {
				$rUrlPorts[$rUrl] = $rCandidates[] = $rParsed[1];
			}
		}
		$rFree = array_values(array_diff(array_unique($rCandidates), $rInUse, $rPlainStays ? array_keys($rPorts) : []));
		if ($rFree === []) {
			return false;
		}
		sort($rFree);
		$rKeptPorts = array_diff_key($rPorts, array_flip($rFree));
		$rKeptUrls = array_diff_key($rUrls, array_filter($rUrlPorts, static fn(int $rPort): bool => in_array($rPort, $rFree, true)));
		// Only over the lists as read: a change stored meanwhile keeps what it kept.
		if (!self::save($rKeptPorts, $rKeptUrls, [(string) ($rSettings['cluster_legacy_ports'] ?? ''), (string) ($rSettings['cluster_legacy_urls'] ?? '')])) {
			return false;
		}
		ClusterAudit::log('cluster.endpoint_released', null, ['ports' => $rFree, 'kept' => array_keys($rKeptPorts), 'kept_urls' => array_keys($rKeptUrls), 'policy_ver' => $rVer], 'cron');
		return true;
	}

	/**
	 * Drop kept URLs before their 7 days (cluster:endpoint drop): the one
	 * that is $rWhat, or every one whose host is $rWhat (a DNS name, or an
	 * address, an IPv6 one with or without brackets). For an old DNS name or
	 * address MAIN gives up: whoever holds it next answers the nodes that dial
	 * it. It can neither read their requests nor forge a reply they act on
	 * (MAC'd and sealed), but it can keep today's agent from MAIN for up to
	 * 10 minutes after each outage (ADR 0004, third endpoint increment). The
	 * policy version goes up with the write, over the kept lists as read
	 * (TRIES tries), and it is audited. nginx closes an old HTTPS port it
	 * served only for a dropped URL at the next cron:cluster render. Returns
	 * the URLs dropped (none when none matched), or null when they could not
	 * be written.
	 *
	 * @param array<string, mixed> $rSettings The loaded settings; the endpoint state is read again (stored()).
	 * @return list<string>|null
	 */
	public static function drop(string $rWhat, array $rSettings, string $rActor = 'admin'): ?array {
		$rHost = strtolower(trim($rWhat, '[]'));
		for ($rTry = 0; $rTry < self::TRIES; $rTry++) {
			$rSettings = self::stored($rSettings);
			$rUrls = self::legacyUrls($rSettings);
			$rGone = [];
			foreach (array_keys($rUrls) as $rUrl) {
				if ($rUrl === $rWhat || self::host($rUrl) === $rHost) {
					$rGone[] = $rUrl;
				}
			}
			if ($rGone === [] || !array_key_exists('cluster_legacy_urls', $rSettings)) {
				return [];
			}
			$rKept = array_diff_key($rUrls, array_flip($rGone));
			if (self::save(self::legacyPorts($rSettings), $rKept, [(string) ($rSettings['cluster_legacy_ports'] ?? ''), (string) ($rSettings['cluster_legacy_urls'] ?? '')])) {
				ClusterAudit::log('cluster.endpoint_dropped', null, ['urls' => $rGone, 'kept_urls' => array_keys($rKept)], $rActor);
				return $rGone;
			}
		}
		return null;
	}

	/** The host of a cluster API URL as the policy writes it, lower case and without brackets, or null. */
	private static function host(string $rUrl): ?string {
		if (self::parseUrl($rUrl) === null || !preg_match('#^https?://(?:\[([^\]]+)\]|([^:/]+)):#', $rUrl, $rMatch)) {
			return null;
		}
		return strtolower($rMatch[1] !== '' ? $rMatch[1] : $rMatch[2]);
	}

	/**
	 * What a node's hello or heartbeat says of the URL it uses, as the
	 * `cluster_nodes` fields that changed; none, so a heartbeat stays off
	 * MySQL, when neither did:
	 *
	 * - `policy_ver`: the version of the policy whose main_urls the agent
	 *   dials, as it says in the payload; 0, unknown, when it does not say
	 *   it as an integer (an older agent);
	 * - `main_port`: the MAIN port nginx took the request on (`$server_port`),
	 *   when nginx passed one, once migration 046 added the column.
	 *
	 * @param array<string, mixed> $rNode The node's row, as the request was authenticated against.
	 * @param array<string, mixed> $rPayload
	 * @return array<string, int>
	 */
	public static function nodeUses(array $rNode, array $rPayload, int $rPort): array {
		$rVer = $rPayload['policy_ver'] ?? 0;
		$rVer = is_int($rVer) && $rVer >= 0 && $rVer <= self::MAX_VER ? $rVer : 0;
		$rOut = [];
		if (array_key_exists('policy_ver', $rNode) && (int) $rNode['policy_ver'] !== $rVer) {
			$rOut['policy_ver'] = $rVer;
		}
		if ($rPort >= 1 && $rPort <= 65535 && array_key_exists('main_port', $rNode) && (int) $rNode['main_port'] !== $rPort) {
			$rOut['main_port'] = $rPort;
		}
		return $rOut;
	}

	/**
	 * The ports the nodes that may use MAIN's URLs last reached it on, or
	 * null while one of them cannot tell that it uses the new URLs: silent
	 * past the offline window (NodeHealth, MAIN's own downtime included) or
	 * never heard, on a policy version other than $rVer (0: its agent does not
	 * say), with no port recorded (before migration 046, or no SERVER_PORT
	 * from nginx), or the nodes cannot be read. An enrolment past its
	 * deadline can no longer complete, and a new one sends the current URLs.
	 * A row whose server was deleted is no node of MAIN's.
	 *
	 * Also null while an enrolment by code may still dial the one URL its
	 * code carries (ClusterEnrolCodeCommand: the policy's first when it was
	 * issued): a code that has not expired, or a request made with one that
	 * waits for the admin's approval, which the agent polls for as long as a
	 * code lives (EnrolCodeService::TTL). Once approved, the node's row holds
	 * the ports until its enrolment completes or expires.
	 *
	 * A node in mode 0 holds only the port it was last heard on, while it is
	 * heard: no change waits for it (nodesListening()), but its agent still
	 * heartbeats MAIN's URLs, and a later mode reaches it in the replies.
	 *
	 * @return list<int>|null
	 */
	private static function portsInUse(int $rVer, int $rOfflineAfterSec): ?array {
		$rNow = ClusterClock::now();
		try {
			$rDb = self::db();
			foreach ([
				['SELECT 1 FROM `cluster_enrol_codes` WHERE `exp` > ? LIMIT 1;', $rNow],
				["SELECT 1 FROM `cluster_enrol_requests` WHERE `state` = 'pending_approval' AND `created_at` > ? LIMIT 1;", $rNow - EnrolCodeService::TTL],
			] as [$rQuery, $rSince]) {
				if (!$rDb->query($rQuery, $rSince) || $rDb->num_rows() > 0) {
					return null;
				}
			}
			if (!$rDb->query("SELECT * FROM `cluster_nodes` WHERE `state` <> 'revoked' AND EXISTS (SELECT 1 FROM `servers` WHERE `servers`.`id` = `cluster_nodes`.`server_id`);")) {
				return null;
			}
			$rNodes = (array) $rDb->get_rows();
		} catch (\Throwable) {
			return null;
		}
		$rPorts = [];
		foreach ($rNodes as $rNode) {
			$rSeen = isset($rNode['last_seen_at']) ? (int) $rNode['last_seen_at'] : null;
			$rHeard = !in_array(NodeHealth::state($rSeen, 0, ClusterClock::nowMs(), $rOfflineAfterSec), ['offline', 'unknown'], true);
			if ((int) $rNode['mode'] < 1) {
				if ($rHeard && !empty($rNode['main_port'])) {
					$rPorts[] = (int) $rNode['main_port'];
				}
				continue;
			}
			if ($rNode['state'] === 'enrolling' && $rNow > (int) ($rNode['enrol_deadline'] ?? 0)) {
				continue;
			}
			if (!$rHeard || (int) ($rNode['policy_ver'] ?? 0) !== $rVer || empty($rNode['main_port'])) {
				return null;
			}
			$rPorts[] = (int) $rNode['main_port'];
		}
		return array_values(array_unique($rPorts));
	}

	/**
	 * $rSettings with the endpoint state read again from the database: a
	 * settings save, cron:cluster or cron:root_signals may have stored it
	 * after this process loaded its settings, and a list written back from a
	 * stale copy would lose what they kept. $rMore names other columns to
	 * read again.
	 *
	 * @param array<string, mixed> $rSettings
	 * @param list<string> $rMore
	 * @return array<string, mixed>
	 */
	public static function stored(array $rSettings, array $rMore = []): array {
		try {
			$rDb = self::db();
			if ($rDb->query('SELECT * FROM `settings` LIMIT 1;')) {
				$rRow = $rDb->get_row();
				if (is_array($rRow)) {
					$rSettings = array_intersect_key($rRow, array_flip(array_merge(self::STATE, $rMore))) + $rSettings;
				}
			}
		} catch (\Throwable) {
			// The loaded settings, then.
		}
		return $rSettings;
	}

	/**
	 * Whether a node may use MAIN's URLs: one in mode ≥ 1 that is not
	 * revoked (a node still enrolling dials the URLs of its cluster.json).
	 * When the check fails, one may: a needless announcement costs each node
	 * a hello, a missed one can strand them.
	 */
	private static function nodesListening(): bool {
		try {
			$rDb = self::db();
			if ($rDb->query("SELECT 1 FROM `cluster_nodes` WHERE `mode` >= 1 AND `state` <> 'revoked' LIMIT 1;")) {
				return $rDb->num_rows() > 0;
			}
		} catch (\Throwable) {
			// Unknown, then.
		}
		return true;
	}

	/**
	 * $rPorts with $rFrom kept for GRACE from now, and $rTo (in use again) no
	 * longer kept.
	 *
	 * @param array<int, int> $rPorts
	 * @return array<int, int>
	 */
	private static function keep(array $rPorts, int $rFrom, int $rTo): array {
		unset($rPorts[$rTo]);
		$rPorts[$rFrom] = ClusterClock::now() + self::GRACE;
		ksort($rPorts);
		return $rPorts;
	}

	/**
	 * Store the kept ports, and the kept URLs unless null, and announce: the
	 * policy version goes up in the same UPDATE. With $rWas (both lists as
	 * they were read), only over those: when a change stored another list
	 * meanwhile, nothing is written and this returns false.
	 *
	 * @param array<int, int> $rPorts
	 * @param array<string, int>|null $rUrls
	 * @param array{0: string, 1: string}|null $rWas
	 */
	private static function save(array $rPorts, ?array $rUrls = null, ?array $rWas = null): bool {
		ksort($rPorts);
		$rPortDoc = $rPorts === [] ? '' : (string) json_encode($rPorts);
		$rDb = self::db();
		$rUrlDoc = $rUrls === null || $rUrls === [] ? '' : (string) json_encode($rUrls, JSON_UNESCAPED_SLASHES);
		if ($rWas !== null) {
			$rDb->query("UPDATE `settings` SET `cluster_legacy_ports` = ?, `cluster_legacy_urls` = ?, `cluster_policy_ver` = `cluster_policy_ver` + 1 WHERE COALESCE(`cluster_legacy_ports`, '') = ? AND COALESCE(`cluster_legacy_urls`, '') = ?;", $rPortDoc, $rUrlDoc, $rWas[0], $rWas[1]);
			$rRow = $rDb->query('SELECT `cluster_legacy_ports`, `cluster_legacy_urls` FROM `settings` LIMIT 1;') ? $rDb->get_row() : null;
			if (!is_array($rRow) || (string) ($rRow['cluster_legacy_ports'] ?? '') !== $rPortDoc || (string) ($rRow['cluster_legacy_urls'] ?? '') !== $rUrlDoc) {
				return false;
			}
		} elseif ($rUrls === null || !$rDb->query('UPDATE `settings` SET `cluster_legacy_ports` = ?, `cluster_legacy_urls` = ?, `cluster_policy_ver` = `cluster_policy_ver` + 1;', $rPortDoc, $rUrlDoc)) {
			// The ports alone: no URL to store, or migration 044, which adds
			// their column, has not run yet. The change is announced all the same.
			$rDb->query('UPDATE `settings` SET `cluster_legacy_ports` = ?, `cluster_policy_ver` = `cluster_policy_ver` + 1;', $rPortDoc);
		}
		try {
			SettingsManager::set(SettingsRepository::getAll(true));
		} catch (\Throwable) {
			// The settings cache catches up on its next refresh.
		}
		return true;
	}
}
