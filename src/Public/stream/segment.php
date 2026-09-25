<?php

use XcVm\Core\Config\ConfigReader;
use XcVm\Core\Util\Encryption;
use XcVm\Streaming\AsyncFileOperations;
use XcVm\Streaming\Codec\FfmpegPaths;
use XcVm\Streaming\Delivery\SignalSender;
use XcVm\Streaming\Fanout\FanoutMode;

/**
 * HLS segment delivery endpoint
 *
 * @package XC_VM_Web_Stream
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

header('Access-Control-Allow-Origin: *');
header('X-Content-Type-Options: nosniff');
set_time_limit(0);

$rSettings = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'settings'));
$rServers = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'servers'));
if (!defined('SERVER_ID')) {
	define('SERVER_ID', intval(ConfigReader::get('server_id')));
}

if (empty($rSettings['live_streaming_pass'])) {
	generate404();
}

if (!empty($rSettings['send_server_header'])) {
	header('Server: ' . $rSettings['send_server_header']);
}

if ($rSettings['send_protection_headers']) {
	header('X-XSS-Protection: 0');
	header('X-Content-Type-Options: nosniff');
}

if ($rSettings['send_altsvc_header']) {
	header('Alt-Svc: h3-29=":' . $rServers[SERVER_ID]['https_broadcast_port'] . '"; ma=2592000,h3-T051=":' . $rServers[SERVER_ID]['https_broadcast_port'] . '"; ma=2592000,h3-Q050=":' . $rServers[SERVER_ID]['https_broadcast_port'] . '"; ma=2592000,h3-Q046=":' . $rServers[SERVER_ID]['https_broadcast_port'] . '"; ma=2592000,h3-Q043=":' . $rServers[SERVER_ID]['https_broadcast_port'] . '"; ma=2592000,quic=":' . $rServers[SERVER_ID]['https_broadcast_port'] . '"; ma=2592000; v="46,43"');
}

if (!empty($rSettings['send_unique_header_domain']) || filter_var(HOST, FILTER_VALIDATE_IP)) {
} else {
	$rSettings['send_unique_header_domain'] = '.' . HOST;
}

$rVideoCodec = 'h264';
$rIsHMAC = null;

if (isset($_GET['token'])) {
	$rOffset = 0;
	$rTokenArray = explode('/', (string) Encryption::readToken($_GET['token'], $rSettings['live_streaming_pass'], OPENSSL_EXTRA, empty($rSettings['secure_stream_tokens'])));

	if (6 > count($rTokenArray)) {
	} else {
		if ($rTokenArray[0] == 'TS') {
			$rServerID = $rTokenArray[8];
		} else {
			$rServerID = $rTokenArray[6];
		}

		if ($rServerID == SERVER_ID) {
			if ($rTokenArray[0] == 'TS') {
				$rType = 'ARCHIVE';
				list(, $rUsername, $rPassword, $rUserIP, $rDuration, $rStartDate, $rSegmentData, $rUUID) = $rTokenArray;
				list($rStreamID, $rSegmentID, $rOffset) = explode('_', $rSegmentData);
				$rStreamID = intval($rStreamID);
				$rSegment = ARCHIVE_PATH . $rStreamID . '/' . $rSegmentID;

				if (!file_exists($rSegment)) {
					generate404();
				}
			} else {
				$rType = 'LIVE';

				if (substr($rTokenArray[0], 0, 5) == 'HMAC#') {
					$rIsHMAC = intval(explode('#', $rTokenArray[0])[1]);
					$rIdentifier = $rTokenArray[1];
				} else {
					list($rUsername, $rPassword) = $rTokenArray;
				}

				$rUserIP = $rTokenArray[2];
				$rStreamID = intval($rTokenArray[3]);
				$rSegmentID = basename($rTokenArray[4]);
				$rUUID = $rTokenArray[5];
				$rVideoCodec = ($rTokenArray[7] ?: 'h264');
				$rOnDemand = ($rTokenArray[8] ?: 0);

				// Phase B (ADR 0003): daemon in-RAM HLS segment — name is
				// "<id>_d<seq>.ts", no on-disk file. Auth lives in the token; run
				// the same uuid + IP checks, then proxy the segment from the
				// daemon's RAM via an internal X-Accel location (2-path-segment
				// target so the server-level rewrites don't hijack it).
				if (preg_match('/^' . intval($rStreamID) . '_d(\d+)\.ts$/', $rSegmentID, $rDSeg)) {
					if (!file_exists(CONS_TMP_PATH . $rUUID)) {
						generate404();
					}
					$rIPMatch = ($rSettings['ip_subnet_match'] ? implode('.', array_slice(explode('.', $rUserIP), 0, -1)) == implode('.', array_slice(explode('.', getuserip()), 0, -1)) : $rUserIP == getuserip());
					if (!($rIPMatch || !$rSettings['restrict_same_ip'])) {
						generate404();
					}
					header('Access-Control-Allow-Origin: *');
					header('Content-Type: video/mp2t');
					// Pass the viewer uuid + video codec so the daemon can apply a
					// pending "send message" overlay to this segment (else no-op).
					header('X-Accel-Redirect: /xc_fanout_hls/' . intval($rStreamID) . '_' . $rDSeg[1] . '?c=' . rawurlencode($rUUID) . '&vc=' . rawurlencode($rVideoCodec));
					exit();
				}

				// With fanout on, client HLS is daemon-only (ADR 0003, Phase E): a LIVE
				// segment that is not a daemon token ("<id>_d<seq>.ts", handled above)
				// is not served from the on-disk HLS, which stays for timeshift,
				// thumbnails, .analyse and MonitorCommand.
				if (!FanoutMode::legacyDelivery($rSettings)) {
					generate404();
				}

				// Fanout switched off (or the licence denies it): the pre-fanout path. The playlist live.php
				// built (HLSGenerator::generateHLS) names the on-disk segments.
				$rSegment = STREAMS_PATH . $rSegmentID;
				$rSegmentData = explode('_', $rSegmentID);
				if (!file_exists($rSegment) || $rSegmentData[0] != $rStreamID) {
					generate404();
				}
			}

			if (!file_exists(CONS_TMP_PATH . $rUUID)) {
				generate404();
			}

			$rFilesize = filesize($rSegment);
			$rIPMatch = ($rSettings['ip_subnet_match'] ? implode('.', array_slice(explode('.', $rUserIP), 0, -1)) == implode('.', array_slice(explode('.', getuserip()), 0, -1)) : $rUserIP == getuserip());

			if (!$rIPMatch && $rSettings['restrict_same_ip']) {
				generate404();
			}

			header('Access-Control-Allow-Origin: *');
			$rExtension = pathinfo($rSegment, PATHINFO_EXTENSION);
			if ($rExtension === 'm4s' || $rExtension === 'mp4') {
				header('Content-Type: video/iso.segment');
			} else {
				header('Content-Type: video/mp2t');
			}

			if ($rType == 'LIVE') {
				// Fanout off only (see above): an on-disk live segment.
				if ($rOnDemand) {
					$rSettings['encrypt_hls'] = false;
				}

				// A pending admin "send message" for this viewer: burn it onto
				// this segment (the daemon does this when fanout is on).
				if (file_exists(SIGNALS_PATH . $rUUID)) {
					$rSignalData = json_decode(file_get_contents(SIGNALS_PATH . $rUUID), true);

					if (is_array($rSignalData) && ($rSignalData['type'] ?? '') == 'signal') {
						FfmpegPaths::resolve((string) ($rSettings['ffmpeg_cpu'] ?? ''), $rSettings['ffmpeg_gpu'] ?? null);
						$rFFMPEG_CPU = FfmpegPaths::cpu();

						if ($rSettings['encrypt_hls']) {
							$rKey = file_get_contents(STREAMS_PATH . $rStreamID . '_.key');
							$rIV = file_get_contents(STREAMS_PATH . $rStreamID . '_.iv');
							$rData = SignalSender::sendSignal($rFFMPEG_CPU, $rSignalData, basename($rSegment), $rVideoCodec, true);
							echo openssl_encrypt($rData, 'aes-128-cbc', $rKey, OPENSSL_RAW_DATA, $rIV);
						} else {
							SignalSender::sendSignal($rFFMPEG_CPU, $rSignalData, basename($rSegment), $rVideoCodec);
						}

						unlink(SIGNALS_PATH . $rUUID);

						exit();
					}
				}

				if ($rSettings['encrypt_hls']) {
					// Encrypt on first read, once per segment: the first request
					// writes <segment>.enc while others wait on the .enc_write marker.
					if (file_exists($rSegment . '.enc_write')) {
						if (file_exists(STREAMS_PATH . $rStreamID . '_.dur')) {
							$rWaitSeconds = intval(file_get_contents(STREAMS_PATH . $rStreamID . '_.dur')) * 2;
						} else {
							$rWaitSeconds = $rSettings['seg_time'] * 2;
						}

						$rStartWait = microtime(true);
						$rTimeout = max(1, $rWaitSeconds);
						while (file_exists($rSegment . '.enc_write') && !file_exists($rSegment . '.enc') && (microtime(true) - $rStartWait) < $rTimeout) {
							AsyncFileOperations::efficientSleep(100000);
						}
					} elseif (!file_exists($rSegment . '.enc')) {
						ignore_user_abort(true);
						touch($rSegment . '.enc_write');
						$rKey = file_get_contents(STREAMS_PATH . $rStreamID . '_.key');
						$rIV = file_get_contents(STREAMS_PATH . $rStreamID . '_.iv');
						$rData = openssl_encrypt(file_get_contents($rSegment), 'aes-128-cbc', $rKey, OPENSSL_RAW_DATA, $rIV);
						file_put_contents($rSegment . '.enc', $rData);
						unset($rData);
						unlink($rSegment . '.enc_write');
						ignore_user_abort(false);
					}

					if (!file_exists($rSegment . '.enc')) {
						generate404();
					}
					header('X-Accel-Redirect: /xc_hls/' . rawurlencode(basename($rSegment) . '.enc'));
				} else {
					header('X-Accel-Redirect: /xc_hls/' . rawurlencode(basename($rSegment)));
				}

				exit();
			}

			// ARCHIVE (timeshift catch-up) segments are on-disk files, served here.
			// Offset-read a partial first segment, else readfile.
			if (0 < $rOffset) {
				header('Content-Length: ' . ($rFilesize - $rOffset));
				$rFP = @fopen($rSegment, 'rb');

				if ($rFP) {
					fseek($rFP, $rOffset);

					while (!feof($rFP)) {
						echo stream_get_line($rFP, $rSettings['read_buffer_size']);
					}
					fclose($rFP);
				}
			} else {
				header('Content-Length: ' . $rFilesize);
				readfile($rSegment);
			}

			exit();
		}

		if ($rServers[$rServerID]['random_ip'] && 0 < count($rServers[$rServerID]['domains']['urls'])) {
			$rURL = $rServers[$rServerID]['domains']['protocol'] . '://' . $rServers[$rServerID]['domains']['urls'][array_rand($rServers[$rServerID]['domains']['urls'])] . ':' . $rServers[$rServerID]['domains']['port'];
		} else {
			$rURL = rtrim($rServers[$rServerID]['site_url'], '/');
		}

		header('Location: ' . $rURL . '/hls/' . $_GET['token']);

		exit();
	}
}

generate404();

function getuserip() {
	return $_SERVER['REMOTE_ADDR'];
}
