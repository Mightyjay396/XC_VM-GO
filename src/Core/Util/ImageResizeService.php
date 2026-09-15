<?php

namespace XcVm\Core\Util;

/**
 * ImageResizeService — HTTP image resize handler
 *
 * Resizes a remote/local image on demand, caches the result as PNG,
 * and outputs the file directly to the HTTP response.
 *
 * Caller sets up options and delegates:
 *
 *   ImageResizeService::serve([
 *     'cacheDir'     => IMAGES_PATH . 'admin/',   // required
 *     'placeholder'  => null,                     // optional path, null → 1×1 transparent
 *     'extraParams'  => false,                    // true → support ?w, ?h, ?icon (player)
 *   ]);
 *
 * @package XC_VM_Core_Util
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ImageResizeService {
	/**
	 * Process resize request and send image response.
	 *
	 * @param array $rOptions {
	 *   string      cacheDir    Cache directory (trailing slash). Default: IMAGES_PATH . 'admin/'
	 *   string|null placeholder Path to placeholder file. Default: null (1×1 transparent)
	 *   bool        extraParams Support ?w, ?h, ?icon params (player panel). Default: false
	 * }
	 */
	public static function serve(array $rOptions = []): void {
		set_time_limit(15);
		ini_set('default_socket_timeout', 10);
		ini_set('user_agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

		$rCacheDir     = $rOptions['cacheDir'] ?? (defined('IMAGES_PATH') ? IMAGES_PATH . 'admin/' : '');
		$rPlaceholder  = $rOptions['placeholder'] ?? null;
		$rExtraParams  = $rOptions['extraParams'] ?? false;

		if ($rCacheDir && !is_dir($rCacheDir)) {
			@mkdir($rCacheDir, 0755, true);
		}

		if (empty($_GET['url']) && !empty($_SERVER['REQUEST_URI']) && str_contains($_SERVER['REQUEST_URI'], '?')) {
			$qStr = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
			if ($qStr !== '') {
				parse_str($qStr, $parsedParams);
				$_GET = array_merge($parsedParams, $_GET);
			}
		}

		$rURL  = $_GET['url'] ?? '';
		$rMaxW = 0;
		$rMaxH = 0;

		if (isset($_GET['maxw'])) {
			$rMaxW = intval($_GET['maxw']);
		}
		if (isset($_GET['maxh'])) {
			$rMaxH = intval($_GET['maxh']);
		}
		if (isset($_GET['max'])) {
			$rMaxW = intval($_GET['max']);
			$rMaxH = intval($_GET['max']);
		}

		$rImageSize = null;

		if ($rExtraParams) {
			$reqW = isset($_GET['w']) ? intval($_GET['w']) : (isset($_GET['width']) ? intval($_GET['width']) : 0);
			$reqH = isset($_GET['h']) ? intval($_GET['h']) : (isset($_GET['height']) ? intval($_GET['height']) : 0);

			if ($reqW > 0 && $reqH > 0) {
				$rImageSize = ['width' => $reqW, 'height' => $reqH];
			} elseif ($reqW > 0) {
				$rMaxW = $reqW;
				$rMaxH = $reqW * 2;
			} elseif ($reqH > 0) {
				$rMaxH = $reqH;
				$rMaxW = $reqH * 2;
			}

			if (isset($_GET['icon'])) {
				$rMaxH = $rMaxW = 48;
			}
		}

		if ($rMaxW > 0 && $rMaxH === 0) {
			$rMaxH = $rMaxW * 2;
		} elseif ($rMaxH > 0 && $rMaxW === 0) {
			$rMaxW = $rMaxH * 2;
		} elseif ($rMaxW === 0 && $rMaxH === 0 && $rImageSize === null) {
			$rMaxW = 600;
			$rMaxH = 900;
		}

		// Resolve server-prefixed URL (s:<id>:<path>) only when needed. A URL
		// resolved from the admin-configured server list is trusted (it may point
		// at a private LB address); a raw user-supplied URL is not (SSRF risk).
		$rTrustedSource = false;
		if (substr($rURL, 0, 2) === 's:') {
			$rServers = $GLOBALS['rServers'] ?? null;
			if ($rServers === null && class_exists(\XcVm\Domain\Server\ServerRepository::class)) {
				try {
					$rServers = \XcVm\Domain\Server\ServerRepository::getAll();
				} catch (\Throwable $e) {
					$rServers = [];
				}
			}
			$rSplit    = explode(':', $rURL, 3);
			$rServerID = intval($rSplit[1] ?? 0);
			if (isset($rServers[$rServerID])) {
				$rSrv      = $rServers[$rServerID];
				$rDomain   = empty($rSrv['domain_name'])
					? $rSrv['server_ip']
					: explode(',', $rSrv['domain_name'])[0];
				$rProtocol = (!empty($rSrv['server_protocol']) ? $rSrv['server_protocol'] : 'http');
				$rPort = intval($rSrv['request_port'] ?? 0);
				$rServerURL = $rProtocol . '://' . $rDomain;
				if (0 < $rPort) {
					$rServerURL .= ':' . $rPort;
				}
				$rServerURL .= '/';
				$rURL = $rServerURL . 'images/' . basename($rURL);
				$rTrustedSource = true;
			}
		}

		header('Content-Type: image/png');
		header('X-Content-Type-Options: nosniff');

		if ($rURL && ($rMaxW > 0 && $rMaxH > 0 || $rImageSize !== null)) {
			$wKey = $rImageSize ? $rImageSize['width'] : $rMaxW;
			$hKey = $rImageSize ? $rImageSize['height'] : $rMaxH;
			$rImagePath = $rCacheDir . md5($rURL) . '_' . $wKey . '_' . $hKey . '.png';

			if (!file_exists($rImagePath) || filesize($rImagePath) === 0) {
				$rActURL = ImageUtils::isAbsoluteUrl($rURL)
					? $rURL
					: (defined('IMAGES_PATH') ? IMAGES_PATH . basename($rURL) : $rURL);

				$rImage = null;
				$rawImageData = null;

				if (ImageUtils::isAbsoluteUrl($rActURL)) {
					// SSRF guard: a raw user-supplied URL must resolve to a public
					// host; trusted server-list URLs (resolved above) are exempt.
					if (!$rTrustedSource && !self::hostIsPublic($rActURL)) {
						goto fallback;
					}
					$ctx = stream_context_create([
						'http' => [
							'method' => 'GET',
							'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36\r\nAccept: image/jpeg,image/png,image/*;q=0.5\r\n",
							'timeout' => 10,
							// Do not follow redirects: a 30x could point at an internal
							// address and slip past the pre-flight host check.
							'follow_location' => 0,
							'max_redirects' => 0,
						],
						'ssl' => [
							'verify_peer' => true,
							'verify_peer_name' => true,
						]
					]);
					$rawImageData = @file_get_contents($rActURL, false, $ctx);
					if ($rawImageData !== false && $rawImageData !== '') {
						$rImage = @imagecreatefromstring($rawImageData);
					}
				} else {
					if (file_exists($rActURL)) {
						$rawImageData = @file_get_contents($rActURL);
						if ($rawImageData !== false && $rawImageData !== '') {
							$rImage = @imagecreatefromstring($rawImageData);
						}
					}
				}

				if (!$rImage) {
					// Fallback for WebP images when bundled PHP GD has no WebP support:
					// Cache the raw webp and pass directly to browser (which natively renders WebP).
					if (is_string($rawImageData) && strlen($rawImageData) > 12 && substr($rawImageData, 0, 4) === 'RIFF' && substr($rawImageData, 8, 4) === 'WEBP') {
						$webpCache = $rCacheDir . md5($rURL) . '.webp';
						@file_put_contents($webpCache, $rawImageData);
						header('Content-Type: image/webp');
						header('Content-Length: ' . strlen($rawImageData));
						header('Cache-Control: public, max-age=604800');
						echo $rawImageData;
						exit();
					}

					goto fallback;
				}

				$origW = imagesx($rImage);
				$origH = imagesy($rImage);

				if ($rImageSize === null) {
					$rImageSize = ImageUtils::getImageSizeKeepAspectRatio(
						$origW,
						$origH,
						$rMaxW,
						$rMaxH
					);
				}

				if (!empty($rImageSize['width']) && !empty($rImageSize['height'])) {
					$rImageP = imagecreatetruecolor($rImageSize['width'], $rImageSize['height']);
					imagealphablending($rImageP, false);
					imagesavealpha($rImageP, true);
					imagecopyresampled(
						$rImageP,
						$rImage,
						0,
						0,
						0,
						0,
						$rImageSize['width'],
						$rImageSize['height'],
						$origW,
						$origH
					);
					@imagepng($rImageP, $rImagePath);
					imagedestroy($rImageP);
				}
				imagedestroy($rImage);
			}

			if (file_exists($rImagePath) && filesize($rImagePath) > 0) {
				header('Content-Length: ' . filesize($rImagePath));
				header('Cache-Control: public, max-age=604800');
				readfile($rImagePath);
				exit();
			}
		}

		fallback:
		if ($rPlaceholder && file_exists($rPlaceholder) && !isset($_GET['icon'])) {
			header('Content-Length: ' . filesize($rPlaceholder));
			header('Cache-Control: public, max-age=86400');
			readfile($rPlaceholder);
			exit();
		}

		$rImg = imagecreatetruecolor(1, 1);
		imagesavealpha($rImg, true);
		imagefill($rImg, 0, 0, imagecolorallocatealpha($rImg, 0, 0, 0, 127));
		imagepng($rImg);
		imagedestroy($rImg);
		exit();
	}

	/**
	 * SSRF guard for outbound image fetches — true only if the URL is http(s)
	 * and its host resolves entirely to public IPs. Rejects loopback, RFC1918,
	 * link-local 169.254/16 (cloud metadata), CGNAT and other reserved ranges,
	 * and an unresolvable host. Applied only to raw user-supplied URLs.
	 */
	private static function hostIsPublic(string $url): bool {
		$scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
		if ($scheme !== 'http' && $scheme !== 'https') {
			return false;
		}
		$host = parse_url($url, PHP_URL_HOST);
		if (!is_string($host) || $host === '') {
			return false;
		}
		$host = trim($host, '[]');

		$ips = [];
		if (filter_var($host, FILTER_VALIDATE_IP)) {
			$ips[] = $host;
		} else {
			foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
				if (!empty($record['ip'])) {
					$ips[] = $record['ip'];
				}
				if (!empty($record['ipv6'])) {
					$ips[] = $record['ipv6'];
				}
			}
			if ($ips === []) {
				$resolved = gethostbyname($host);
				if ($resolved !== '' && $resolved !== $host) {
					$ips[] = $resolved;
				}
			}
		}

		if ($ips === []) {
			return false;
		}

		foreach ($ips as $ip) {
			if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
				return false;
			}
			// filter_var misses CGNAT shared space (RFC 6598, 100.64.0.0/10).
			if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
				&& (ip2long($ip) & 0xffc00000) === (ip2long('100.64.0.0') & 0xffc00000)) {
				return false;
			}
		}

		return true;
	}
}
