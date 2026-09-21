<?php

namespace XcVm\Core\GeoIP;

use XcVm\Core\Util\GeoIP;

/**
 * GeoIPService — GeoIP/ISP lookup и CIDR matching.
 *
 * Использует MaxMind GeoLite2 и GeoISP базы с файловым кэшированием.
 *
 * @package XC_VM_Core_GeoIP
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class GeoIPService {
	/**
	 * Получить GeoIP-информацию по IP-адресу (GeoLite2).
	 *
	 * Результат кэшируется в файл CONS_TMP_PATH/md5(ip)_geo2.
	 *
	 * @return array|false
	 */
	public static function getIPInfo(string $rIP) {
		if (!empty($rIP)) {
			if (!file_exists(CONS_TMP_PATH . md5($rIP) . '_geo2')) {
				$rResponse = self::readMmdb('GEOLITE2_BIN', $rIP);
				if ($rResponse) {
					file_put_contents(CONS_TMP_PATH . md5($rIP) . '_geo2', json_encode($rResponse));
				}
				return $rResponse;
			}
			return json_decode(file_get_contents(CONS_TMP_PATH . md5($rIP) . '_geo2'), true);
		}
		return false;
	}

	/**
	 * Read one record from a MaxMind database, guarding a missing constant or
	 * binary and any reader error so a corrupt/absent database can never fatal
	 * the streaming request (returns false instead).
	 *
	 * @param string $rBinConst Name of the DB-path constant (GEOLITE2_BIN/GEOISP_BIN).
	 * @return array|mixed|false The record, or false when the database is unusable.
	 */
	private static function readMmdb(string $rBinConst, string $rIP) {
		if (!defined($rBinConst) || !file_exists(constant($rBinConst))) {
			return false;
		}
		try {
			$rGeoIP = new \MaxMind\Db\Reader(constant($rBinConst));
			$rResponse = $rGeoIP->get($rIP);
			$rGeoIP->close();
			return $rResponse;
		} catch (\Throwable $e) {
			return false;
		}
	}

	/**
	 * Получить ISP-информацию по IP-адресу (GeoISP).
	 *
	 * Результат кэшируется в файл CONS_TMP_PATH/md5(ip)_isp.
	 *
	 * @return array|false
	 */
	public static function getISP(string $rIP) {
		if (!empty($rIP)) {
			$rResponse = (file_exists(CONS_TMP_PATH . md5($rIP) . '_isp') ? json_decode(file_get_contents(CONS_TMP_PATH . md5($rIP) . '_isp'), true) : null);
			if (!is_array($rResponse)) {
				$rResponse = self::readMmdb('GEOISP_BIN', $rIP);
				if (is_array($rResponse)) {
					file_put_contents(CONS_TMP_PATH . md5($rIP) . '_isp', json_encode($rResponse));
				}
			}
			return $rResponse;
		}
		return false;
	}

	/**
	 * Проверить IP на соответствие CIDR-блокам для ASN.
	 *
	 * @param string $rASN ASN identifier
	 * @param string $rIP IP address
	 * @return array|null Matching CIDR data or null
	 */
	public static function matchCIDR(string $rASN, string $rIP) {
		if (file_exists(CIDR_TMP_PATH . $rASN)) {
			$rCIDRs = json_decode(file_get_contents(CIDR_TMP_PATH . $rASN), true);
			foreach ($rCIDRs as $rData) {
				if (ip2long($rData[1]) <= ip2long($rIP) && ip2long($rIP) <= ip2long($rData[2])) {
					return $rData;
				}
			}
		}
		return null;
	}
}
