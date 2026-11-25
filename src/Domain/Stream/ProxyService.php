<?php

namespace XcVm\Domain\Stream;

/**
 * ProxyService — liste de proxys réutilisables (Serveurs > Proxys sources), sélectionnables par chaîne.
 * La chaîne garde l'id du proxy (argument `proxy_id`) ET l'URL résolue dans l'argument `proxy` (id 2) lu par ffmpeg ;
 * modifier un proxy réécrit l'URL de toutes ses chaînes et notifie les nœuds (prise en compte au prochain démarrage).
 */
class ProxyService {
	public static function all(bool $rEnabledOnly = false): array {
		global $db;
		$db->query('SELECT * FROM `source_proxies`' . ($rEnabledOnly ? ' WHERE `enabled` = 1' : '') . ' ORDER BY `name`');
		return $db->get_rows() ?: [];
	}

	public static function get(int $rID): ?array {
		global $db;
		$db->query('SELECT * FROM `source_proxies` WHERE `id` = ?', $rID);
		return $db->get_row() ?: null;
	}

	public static function url(array $rProxy): string {
		$rAuth = ($rProxy['username'] ?? '') !== '' ? rawurlencode($rProxy['username']) . ':' . rawurlencode((string) $rProxy['password']) . '@' : '';
		return ($rProxy['type'] ?: 'http') . '://' . $rAuth . $rProxy['host'] . ':' . (int) $rProxy['port'];
	}

	public static function argumentID(): int {
		global $db;
		$db->query("SELECT `id` FROM `streams_arguments` WHERE `argument_key` = 'proxy_id'");
		return (int) ($db->get_row()['id'] ?? 0);
	}

	/** Enregistre (id 0 = création) ; propage l'URL aux chaînes liées. Retourne [id, nb de chaînes mises à jour]. */
	public static function save(array $rData): array {
		global $db;
		$rID = (int) ($rData['id'] ?? 0);
		$rFields = [trim($rData['name']), $rData['type'] === 'socks5' ? 'socks5' : 'http', trim($rData['host']), (int) $rData['port'], trim((string) ($rData['username'] ?? '')) ?: null, (string) ($rData['password'] ?? '') !== '' ? $rData['password'] : null, (int) !empty($rData['enabled']), trim((string) ($rData['notes'] ?? '')) ?: null];
		if ($rID > 0) {
			if ($rData['password'] === '') {
				$rFields[5] = self::get($rID)['password'] ?? null;
			}
			$db->query('UPDATE `source_proxies` SET `name` = ?, `type` = ?, `host` = ?, `port` = ?, `username` = ?, `password` = ?, `enabled` = ?, `notes` = ? WHERE `id` = ?', ...[...$rFields, $rID]);
		} else {
			$db->query('INSERT INTO `source_proxies` (`name`, `type`, `host`, `port`, `username`, `password`, `enabled`, `notes`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', ...$rFields);
			$rID = (int) $db->last_insert_id();
		}
		return [$rID, self::propagate($rID)];
	}

	public static function delete(int $rID): int {
		global $db;
		$rArg = self::argumentID();
		$db->query('SELECT `stream_id` FROM `streams_options` WHERE `argument_id` = ? AND `value` = ?', $rArg, (string) $rID);
		$rStreams = array_map('intval', array_column($db->get_rows() ?: [], 'stream_id'));
		if ($rStreams) {
			$db->query('DELETE FROM `streams_options` WHERE `argument_id` IN (?, 2) AND `stream_id` IN (' . implode(',', $rStreams) . ')', $rArg);
			\XcVm\Core\Events\EventDispatcher::dispatch(new \XcVm\Core\Events\Stream\StreamsChangedEvent($rStreams));
		}
		$db->query('DELETE FROM `source_proxies` WHERE `id` = ?', $rID);
		return count($rStreams);
	}

	/** Réécrit l'argument `proxy` (id 2) de toutes les chaînes qui référencent ce proxy. */
	public static function propagate(int $rID): int {
		global $db;
		$rProxy = self::get($rID);
		if (!$rProxy) {
			return 0;
		}
		$db->query('SELECT `stream_id` FROM `streams_options` WHERE `argument_id` = ? AND `value` = ?', self::argumentID(), (string) $rID);
		$rStreams = array_map('intval', array_column($db->get_rows() ?: [], 'stream_id'));
		foreach ($rStreams as $rStreamID) {
			self::applyToStream($rStreamID, $rID);
		}
		if ($rStreams) {
			\XcVm\Core\Events\EventDispatcher::dispatch(new \XcVm\Core\Events\Stream\StreamsChangedEvent($rStreams));
		}
		return count($rStreams);
	}

	/** Appelé à l'enregistrement d'une chaîne : stocke proxy_id et remplace l'URL proxy (id 2) par celle du proxy choisi. */
	public static function applyToStream(int $rStreamID, int $rProxyID): void {
		global $db;
		$rArg = self::argumentID();
		$db->query('DELETE FROM `streams_options` WHERE `stream_id` = ? AND `argument_id` = ?', $rStreamID, $rArg);
		$rProxy = $rProxyID > 0 ? self::get($rProxyID) : null;
		if (!$rProxy) {
			return;
		}
		$db->query('INSERT INTO `streams_options` (`stream_id`, `argument_id`, `value`) VALUES (?, ?, ?)', $rStreamID, $rArg, (string) $rProxyID);
		$db->query('DELETE FROM `streams_options` WHERE `stream_id` = ? AND `argument_id` = 2', $rStreamID);
		if ((int) $rProxy['enabled'] === 1) {
			$db->query('INSERT INTO `streams_options` (`stream_id`, `argument_id`, `value`) VALUES (?, 2, ?)', $rStreamID, self::url($rProxy));
		}
	}

	/** Test réel : requête HTTP via le proxy vers un service d'IP publique ; mémorise ip / pays / latence. */
	public static function test(int $rID): array {
		global $db;
		$rProxy = self::get($rID);
		if (!$rProxy) {
			return ['ok' => false, 'error' => 'introuvable'];
		}
		$rT0 = microtime(true);
		$ch = curl_init('http://ip-api.com/json/?fields=status,country,countryCode,city,query');
		curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_PROXY => $rProxy['host'] . ':' . (int) $rProxy['port'],
			CURLOPT_PROXYTYPE => $rProxy['type'] === 'socks5' ? CURLPROXY_SOCKS5_HOSTNAME : CURLPROXY_HTTP, CURLOPT_USERAGENT => 'Mozilla/5.0']);
		if (($rProxy['username'] ?? '') !== '') {
			curl_setopt($ch, CURLOPT_PROXYUSERPWD, $rProxy['username'] . ':' . $rProxy['password']);
		}
		$rBody = curl_exec($ch);
		$rErr = curl_error($ch);
		$rCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		curl_close($ch);
		$rMs = (int) round((microtime(true) - $rT0) * 1000);
		$rJSON = json_decode((string) $rBody, true) ?: [];
		$rOK = $rCode === 200 && ($rJSON['status'] ?? '') === 'success';
		$rRes = ['ok' => $rOK, 'ms' => $rMs, 'ip' => $rJSON['query'] ?? null, 'country' => $rOK ? trim(($rJSON['country'] ?? '') . ' ' . ($rJSON['city'] ? '(' . $rJSON['city'] . ')' : '')) : null, 'error' => $rOK ? null : ($rErr ?: 'HTTP ' . $rCode)];
		$db->query('UPDATE `source_proxies` SET `last_check` = ?, `last_ok` = ?, `last_ip` = ?, `last_country` = ?, `last_ms` = ? WHERE `id` = ?', time(), (int) $rOK, $rRes['ip'], $rRes['country'], $rMs, $rID);
		return $rRes;
	}

	/** Nombre de chaînes par proxy (pour la liste). */
	public static function usage(): array {
		global $db;
		$db->query('SELECT `value`, COUNT(*) `n` FROM `streams_options` WHERE `argument_id` = ? GROUP BY `value`', self::argumentID());
		return array_column($db->get_rows() ?: [], 'n', 'value');
	}
}
