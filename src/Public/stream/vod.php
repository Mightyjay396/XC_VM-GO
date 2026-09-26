<?php

use XcVm\Core\Cluster\SignalDispatcher;
use XcVm\Core\Logging\DatabaseLogger;
use XcVm\Core\Process\ProcessManager;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Infrastructure\Cache\CacheReader;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Infrastructure\Redis\RedisManager;
use XcVm\Streaming\AsyncFileOperations;
use XcVm\Streaming\Auth\StreamAuth;
use XcVm\Streaming\Auth\StreamAuthMiddleware;
use XcVm\Streaming\Delivery\HttpRange;
use XcVm\Streaming\Lifecycle\ShutdownHandler;

/**
 * VOD stream delivery endpoint
 *
 * @package XC_VM_Web_Stream
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

set_time_limit(0);
register_shutdown_function([ShutdownHandler::class, 'handle'], 'vod');
unset($rSettings['watchdog_data'], $rSettings['server_hardware']);

StreamAuthMiddleware::sendStreamHeaders($rSettings, $rServers);

$rCreateExpiration = 60;
$rProxyID = null;
$rIP = $_SERVER['REMOTE_ADDR'];
$rUserAgent = (empty($_SERVER['HTTP_USER_AGENT']) ? '' : htmlentities(trim($_SERVER['HTTP_USER_AGENT'])));
$rConSpeedFile = null;
$rDivergence = 0;
$rCloseCon = false;
$rPID = getmypid();
$rIsMag = false;

if (isset($rRequest['token'])) {
	$rTokenData = StreamAuthMiddleware::decryptToken($rRequest['token'], $rSettings, $rServers, $rIP);

	if (isset($rTokenData['hmac_id'])) {
		$rIsHMAC = $rTokenData['hmac_id'];
		$rIdentifier = $rTokenData['identifier'];
	} else {
		$rIsHMAC = null;
		$rIdentifier = null;
		$rUsername = $rTokenData['username'];
		$rPassword = $rTokenData['password'];
	}

	$rStreamID = intval($rTokenData['stream_id']);
	$rExtension = $rTokenData['extension'];
	$rType = $rTokenData['type'];
	$rChannelInfo = $rTokenData['channel_info'];
	$rUserInfo = $rTokenData['user_info'];
	$rActivityStart = $rTokenData['activity_start'];
	$rCountryCode = $rTokenData['country_code'];
	$rIsMag = $rTokenData['is_mag'];
	$rDirectProxy = ($rChannelInfo['proxy'] ?: null);

	if (empty($rTokenData['http_range']) || isset($_SERVER['HTTP_RANGE'])) {
	} else {
		$_SERVER['HTTP_RANGE'] = $rTokenData['http_range'];
	}
} else {
	generateError('NO_TOKEN_SPECIFIED');
}

$rRequest = VOD_PATH . $rStreamID . '.' . $rExtension;

if (file_exists($rRequest) || $rDirectProxy) {
} else {
	generateError('VOD_DOESNT_EXIST');
}

if ($rSettings['use_buffer'] != 0) {
} else {
	header('X-Accel-Buffering: no');
}

if ($rChannelInfo) {
	if ($rChannelInfo['originator_id']) {
		$rServerID = $rChannelInfo['originator_id'];
		$rProxyID = $rChannelInfo['redirect_id'];
	} else {
		$rServerID = ($rChannelInfo['redirect_id'] ?: SERVER_ID);
		$rProxyID = null;
	}

	if ($rSettings['redis_handler']) {
		RedisManager::ensureConnected();
	} else {
		DatabaseFactory::connectLazy();
	}

	// A player's HTTP Range request may come without the uuid: match it on the
	// line (or HMAC key), container, agent and stream instead (table path).
	$rRangeMatch = [];
	if (!empty($_SERVER['HTTP_RANGE'])) {
		$rRangeMatch = (!isset($rIsHMAC) && is_null($rIsHMAC)) ? ['user_id' => $rUserInfo['id']] : ['hmac_id' => $rIsHMAC, 'hmac_identifier' => $rIdentifier];
		$rRangeMatch += ['container' => 'VOD', 'user_agent' => $rUserAgent, 'stream_id' => $rStreamID];
	}
	$rConnection = ConnectionTracker::findByUuid($rSettings, $rTokenData['uuid'], '`server_id`, `activity_id`, `pid`, `user_ip`', $rRangeMatch);
	if ($rConnection === null) {
		unset($rConnection);
	}

	if (!isset($rConnection)) {
		if (file_exists(CONS_TMP_PATH . $rTokenData['uuid']) || ($rActivityStart + $rCreateExpiration) - intval($rServers[SERVER_ID]['time_offset']) >= time()) {
		} else {
			generateError('TOKEN_EXPIRED');
		}

		$rLastRead = time() - intval($rServers[SERVER_ID]['time_offset']);
		if (!isset($rIsHMAC) && is_null($rIsHMAC)) {
			$rOwner = ['user_id' => $rUserInfo['id']];
			$rIdentity = $rUserInfo['id'];
		} else {
			$rOwner = ['hmac_id' => $rIsHMAC, 'hmac_identifier' => $rIdentifier];
			$rIdentity = $rIsHMAC . '_' . $rIdentifier;
		}
		$rConnectionData = $rOwner + ['stream_id' => $rStreamID, 'server_id' => $rServerID, 'proxy_id' => $rProxyID, 'user_agent' => $rUserAgent, 'user_ip' => $rIP, 'container' => 'VOD', 'pid' => $rPID, 'date_start' => $rActivityStart, 'geoip_country_code' => $rCountryCode, 'isp' => $rUserInfo['con_isp_name'], 'external_device' => '', 'hls_end' => 0, 'hls_last_read' => $rLastRead, 'on_demand' => 0, 'identity' => $rIdentity, 'uuid' => $rTokenData['uuid']];
		$rResult = ConnectionTracker::openRecord($rSettings, $rConnectionData, $rOwner + ['stream_id' => $rStreamID, 'server_id' => $rServerID, 'proxy_id' => $rProxyID, 'user_agent' => $rUserAgent, 'user_ip' => $rIP, 'container' => 'VOD', 'pid' => $rPID, 'uuid' => $rTokenData['uuid'], 'date_start' => $rActivityStart, 'geoip_country_code' => $rCountryCode, 'isp' => $rUserInfo['con_isp_name'], 'hls_last_read' => $rLastRead], $rTokenData, intval($rServers[SERVER_ID]['time_offset']));
	} else {
		$rIPMatch = ($rSettings['ip_subnet_match'] ? implode('.', array_slice(explode('.', $rConnection['user_ip']), 0, -1)) == implode('.', array_slice(explode('.', $rIP), 0, -1)) : $rConnection['user_ip'] == $rIP);

		if ($rIPMatch || !$rSettings['restrict_same_ip']) {
		} else {
			DatabaseLogger::clientLog($rStreamID, $rUserInfo['id'], 'IP_MISMATCH', $rIP);
			generateError('IP_MISMATCH');
		}

		// Do not kill active workers handling concurrent HTTP Range requests for the same stream.
		// Killing in-flight Range workers immediately breaks video streaming and causes net::ERR_CONTENT_LENGTH_MISMATCH in browsers.
		if (empty($_SERVER['HTTP_RANGE']) && ProcessManager::isRunning($rConnection['pid'], 'php-fpm') && $rPID != $rConnection['pid'] && is_numeric($rConnection['pid']) && 0 < $rConnection['pid']) {
			if ($rConnection['server_id'] == SERVER_ID) {
				posix_kill(intval($rConnection['pid']), 9);
			} else {
				SignalDispatcher::kill(intval($rConnection['server_id']), intval($rConnection['pid']), false, $db);
			}
		}

		$rResult = ConnectionTracker::updateLive($rSettings, $rConnection, ['pid' => $rPID, 'hls_last_read' => time() - intval($rServers[SERVER_ID]['time_offset'])]);
	}

	if (!$rResult) {
		StreamAuth::refuseAdmission($rStreamID, $rUserInfo, $rIP, 'ts', $rCountryCode, $rServerID, $rProxyID);
		DatabaseLogger::clientLog($rStreamID, $rUserInfo['id'], 'LINE_CREATE_FAIL', $rIP, $rSettings['redis_handler'] ? 'redis unavailable: connection tracking write failed' : $db->error());
		generateError('LINE_CREATE_FAIL');
	}

	StreamAuth::validateConnections($rUserInfo, $rIsHMAC, $rIdentifier, $rIP, $rUserAgent, $rTokenData['uuid']);

	if ($rSettings['redis_handler']) {
		RedisManager::closeInstance();
	} else {
		DatabaseFactory::close();
	}

	$rCloseCon = true;

	if ($rSettings['monitor_connection_status']) {
		ob_implicit_flush(true);

		while (ob_get_level()) {
			ob_end_clean();
		}
	}

	touch(CONS_TMP_PATH . $rTokenData['uuid']);

	if (!$rDirectProxy) {
		$rConSpeedFile = DIVERGENCE_TMP_PATH . $rTokenData['uuid'];

		switch ($rChannelInfo['target_container']) {
			case 'mp4':
			case 'm4v':
				header('Content-type: video/mp4');

				break;

			case 'mkv':
				header('Content-type: video/x-matroska');

				break;

			case 'avi':
				header('Content-type: video/x-msvideo');

				break;

			case '3gp':
				header('Content-type: video/3gpp');

				break;

			case 'flv':
				header('Content-type: video/x-flv');

				break;

			case 'wmv':
				header('Content-type: video/x-ms-wmv');

				break;

			case 'mov':
				header('Content-type: video/quicktime');

				break;

			case 'ts':
				header('Content-type: video/mp2t');

				break;

			case 'mpg':
			case 'mpeg':
				header('Content-Type: video/mpeg');

				break;

			default:
				header('Content-Type: application/octet-stream');
		}
		$rRequest = VOD_PATH . $rStreamID . '.' . $rExtension;
		$rDownloadBytes = (!empty($rChannelInfo['bitrate']) ? $rChannelInfo['bitrate'] * 125 : 0);
		$rDownloadBytes += $rDownloadBytes * $rSettings['vod_bitrate_plus'] * 0.01;

		if (!file_exists($rRequest)) {
		} else {
			$rFP = @fopen($rRequest, 'rb');
			$rSize = filesize($rRequest);
			$rServe = HttpRange::sendHeaders(HttpRange::parse($_SERVER['HTTP_RANGE'] ?? null, $rSize), $rSize);
			if ($rServe === null) {
				exit(); // 416 already sent
			}
			[$rStart, $rEnd] = $rServe;
			$rLength = $rEnd - $rStart + 1;
			if (0 < $rStart) {
				fseek($rFP, $rStart);
			}
			$rLastCheck = $rTimeStart = $rTimeChecked = time();
			$rBytesRead = 0;
			$rBuffer = $rSettings['read_buffer_size'];
			$i = 0;
			$o = 0;

			if (0 < $rSettings['vod_limit_perc'] && !$rUserInfo['is_restreamer']) {
				$rLimitAt = intval($rLength * floatval($rSettings['vod_limit_perc'] / 100));
			} else {
				$rLimitAt = $rLength;
			}

			$rApplyLimit = false;

			while (!feof($rFP) && ($p = ftell($rFP)) <= $rEnd) {
				// Never read past the range end: a bounded request (a player probing
				// bytes=0-1, or fetching an index near the end) got a whole buffer.
				$rResponse = stream_get_line($rFP, (int) min($rBuffer, $rEnd - $p + 1));
				$i++;

				if (!$rApplyLimit && $rLimitAt <= $o * $rBuffer) {
					$rApplyLimit = true;
				} else {
					$o++;
				}

				echo $rResponse;
				$rBytesRead += strlen($rResponse);

				if (30 > time() - $rTimeStart) {
				} else {
					file_put_contents($rConSpeedFile, intval($rBytesRead / 1024 / 30));
					$rTimeStart = time();
					$rBytesRead = 0;
				}

				if (0 < $rDownloadBytes && $rApplyLimit && ceil($rDownloadBytes / $rBuffer) <= $i) {
					// Use efficient sleep instead of blocking sleep
					AsyncFileOperations::efficientSleep(1000000); // 1 second with better CPU usage
					$i = 0;
				}

				if (!($rSettings['monitor_connection_status'] && 5 <= time() - $rTimeChecked)) {
				} else {
					if (connection_status() == CONNECTION_NORMAL) {
						$rTimeChecked = time();
					} else {
						exit();
					}
				}

				if (300 > time() - $rLastCheck) {
				} else {
					$rLastCheck = time();
					$rConnection = null;
					$rSettings = CacheReader::get('settings');

					$rConnection = ConnectionTracker::heartbeat($rSettings, $rTokenData['uuid'], time() - intval($rServers[SERVER_ID]['time_offset']));

					if (!(!is_array($rConnection) || $rConnection['hls_end'] != 0 || $rConnection['pid'] != $rPID)) {
					} else {
						exit();
					}
				}
			}
			fclose($rFP);

			exit();
		}
	} else {
		// Direct-proxy VOD: relay the source. Its size and type are read with cURL
		// — get_headers() goes through the https stream wrapper, which does not
		// work under PHP-FPM here (every https source failed), and returned
		// Content-Length as an array after a redirect. The final response's
		// headers are kept; the body is not downloaded.
		$rSourceUA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.16; rv:101.0) Gecko/20100101 Firefox/101.0';
		$rHeaders = [];
		$ch = curl_init($rDirectProxy);
		curl_setopt_array($ch, [
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS => 20,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT => 122,
			CURLOPT_SSL_VERIFYPEER => 0,
			CURLOPT_USERAGENT => $rSourceUA,
			CURLOPT_HEADERFUNCTION => static function ($rHandle, $rLine) use (&$rHeaders) {
				if (preg_match('#^HTTP/\S+\s+\d+#i', $rLine)) {
					$rHeaders = []; // a redirect hop: only the final response counts
				} elseif (strpos($rLine, ':') !== false) {
					[$rName, $rValue] = explode(':', $rLine, 2);
					$rHeaders[strtolower(trim($rName))] = trim($rValue);
				}
				return strlen($rLine);
			},
			CURLOPT_WRITEFUNCTION => static function () {
				return 0; // headers only: stop at the first body byte
			},
		]);
		curl_exec($ch);
		$rDirectProxy = (string) (curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $rDirectProxy);
		curl_close($ch);

		$rSize = intval($rHeaders['content-length'] ?? 0);
		$rContentType = strtolower(trim(explode(';', (string) ($rHeaders['content-type'] ?? ''))[0]));

		if (0 < $rSize && in_array($rContentType, ['video/mp4', 'video/x-matroska', 'video/x-msvideo', 'video/3gpp', 'video/x-flv', 'video/x-ms-wmv', 'video/quicktime', 'video/mp2t', 'video/mpeg', 'application/octet-stream'], true)) {
			header('Content-Type: ' . $rContentType);
			$rServe = HttpRange::sendHeaders(HttpRange::parse($_SERVER['HTTP_RANGE'] ?? null, $rSize), $rSize);
			if ($rServe === null) {
				exit(); // 416 already sent
			}
			[$rStart, $rEnd] = $rServe;

			$ch = curl_init();
			if (0 < $rStart || $rEnd < $rSize - 1) {
				// Ask the source for exactly the range this response promises.
				curl_setopt($ch, CURLOPT_HTTPHEADER, ['Range: bytes=' . $rStart . '-' . $rEnd]);
			}

			if (512 * 1024 * 1024 >= $rSize) {
			} else {
				$rMaxRate = (!empty($rChannelInfo['bitrate']) ? ($rSize * 0.008) / $rChannelInfo['bitrate'] * 125 * 3 : 20 * 1024 * 1024);

				if ($rMaxRate >= 1 * 1024 * 1024) {
				} else {
					$rMaxRate = 1 * 1024 * 1024;
				}

				curl_setopt($ch, CURLOPT_MAX_RECV_SPEED_LARGE, intval($rMaxRate));
			}

			curl_setopt($ch, CURLOPT_BUFFERSIZE, 10 * 1024 * 1024);
			curl_setopt($ch, CURLOPT_TIMEOUT, 0);
			curl_setopt($ch, CURLOPT_URL, $rDirectProxy);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($ch, CURLOPT_USERAGENT, $rSourceUA);
			curl_setopt($ch, CURLOPT_HEADER, false);
			curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
			curl_setopt($ch, CURLOPT_NOBODY, false);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
			curl_exec($ch);

			exit();
		}

		generateError('VOD_DOESNT_EXIST');
	}
} else {
	generateError('TOKEN_ERROR');
}
