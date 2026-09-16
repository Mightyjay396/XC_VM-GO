<?php

namespace XcVm\Core\Config;

use RuntimeException;

/**
 * Single source of truth for the runtime constants that used to live in the
 * procedural prelude files (Paths.php, AppConfig.php, Binaries.php) and in
 * XC_Bootstrap::defineStatusConstants().
 *
 * The value maps (paths(), appConfig(), binaries(), statuses()) are pure and
 * unit-testable with different inputs in the same process — unlike the raw
 * define() constants they feed, which are one-shot per process. init() is the
 * ONE place define() runs; the legacy prelude files become thin shims delegating
 * here, so every caller (XC_Bootstrap, WebApiBootstrap, StreamingRequestBootstrap)
 * gets the full, correctly-ordered constant set regardless of include order.
 *
 * @package XC_VM_Core_Config
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class ConstantsInitializer {
	/**
	 * Define the path, app-config and binary constants exactly once.
	 *
	 * Ordering (MAIN_HOME -> paths -> binaries) is enforced here in code rather
	 * than by require order: BIN_PATH is established by the path map before the
	 * binary map derives from it.
	 *
	 * @param string|null $mainHome Deploy root with trailing slash. Falls back to
	 *                              the MAIN_HOME constant when omitted.
	 */
	public static function init(?string $mainHome = null): void {
		$mainHome ??= defined('MAIN_HOME') ? MAIN_HOME : null;

		if ($mainHome === null) {
			throw new RuntimeException(
				'ConstantsInitializer::init() requires MAIN_HOME to be defined or passed explicitly.'
			);
		}

		self::defineAll(self::paths($mainHome));

		$appConfig = self::appConfig();
		// OPENSSL_EXTRA is per-install key material: prefer the value persisted in
		// config.ini (config.enc) so a fresh install carries its own secret, and
		// fall back to the historical literal so existing installs — whose data was
		// derived from it — keep decrypting. Resolve only on the genuine first
		// define, so the config extension is never touched when the constant is
		// already set (tests, re-init).
		if (!defined('OPENSSL_EXTRA')) {
			$appConfig['OPENSSL_EXTRA'] = self::resolveOpensslExtra($appConfig['OPENSSL_EXTRA']);
		}
		self::defineAll($appConfig);

		// Derive binaries from the now-defined BIN_PATH (an explicit test override
		// of BIN_PATH wins over the computed value).
		$binPath = defined('BIN_PATH') ? BIN_PATH : $mainHome . 'bin/';
		self::defineAll(self::binaries($binPath));
	}

	/**
	 * Define the STATUS_* constants exactly once.
	 *
	 * Kept separate from init() to preserve the current lazy semantics: status
	 * codes are only needed by the admin/reseller API handlers.
	 */
	public static function initStatus(): void {
		if (defined('STATUS_FAILURE')) {
			return;
		}

		self::defineAll(self::statuses());
	}

	/**
	 * Filesystem path constants, derived from the deploy root.
	 *
	 * @param string $mainHome Deploy root with trailing slash.
	 * @return array<string,string> Constant name => value.
	 */
	public static function paths(string $mainHome): array {
		$content   = $mainHome . 'content/';
		$tmp       = $mainHome . 'tmp/';
		$bin       = $mainHome . 'bin/';
		$storage   = $mainHome . 'storage/';
		$fanoutRun = $bin . 'xc_fanout/sockets/';
		$images    = $storage . 'images/';

		return [
			'CONTENT_PATH'        => $content,
			'TMP_PATH'            => $tmp,
			'CONFIG_PATH'         => $mainHome . 'config/',
			'BIN_PATH'            => $bin,
			'STORAGE_PATH'        => $storage,
			'SIGNALS_PATH'        => $mainHome . 'signals/',
			'FANOUT_RUN_PATH'     => $fanoutRun,
			'FANOUT_CTL_SOCK'     => $fanoutRun . 'control.sock',
			'FANOUT_HTTP_SOCK'    => $fanoutRun . 'http.sock',
			'STREAMS_PATH'        => $content . 'streams/',
			'EPG_PATH'            => $content . 'epg/',
			'VOD_PATH'            => $content . 'vod/',
			'ARCHIVE_PATH'        => $content . 'archive/',
			'CREATED_PATH'        => $content . 'created/',
			'DELAY_PATH'          => $content . 'delayed/',
			'VIDEO_PATH'          => $content . 'video/',
			'PLAYLIST_PATH'       => $content . 'playlists/',
			'CONS_TMP_PATH'       => $tmp . 'opened_cons/',
			'CRONS_TMP_PATH'      => $tmp . 'crons/',
			'CIDR_TMP_PATH'       => $tmp . 'cidr/',
			'CACHE_TMP_PATH'      => $tmp . 'cache/',
			'STREAMS_TMP_PATH'    => $tmp . 'cache/streams/',
			'SERIES_TMP_PATH'     => $tmp . 'cache/series/',
			'LINES_TMP_PATH'      => $tmp . 'cache/lines/',
			'DIVERGENCE_TMP_PATH' => $tmp . 'divergence/',
			'FLOOD_TMP_PATH'      => $tmp . 'flood/',
			'PLAYER_TMP_PATH'     => $tmp . 'player/',
			'MINISTRA_TMP_PATH'   => $tmp . 'ministra/',
			'SIGNALS_TMP_PATH'    => $tmp . 'signals/',
			'LOGS_TMP_PATH'       => $tmp . 'logs/',
			'WATCH_TMP_PATH'      => $tmp . 'watch/',
			'IMAGES_PATH'         => $images,
			'E2_IMAGES_PATH'      => $images . 'enigma2/',
		];
	}

	/**
	 * Application-level constants: versioning, Git repos and feature flags.
	 *
	 * @return array<string,scalar> Constant name => value.
	 */
	public static function appConfig(): array {
		return [
			'DB_ACCESS_ENABLED' => false,
			'DB_ACCESS_PWD'     => '',
			'DEV_MODE'          => false,
			'XC_VM_VERSION'     => '2.5.1',
			'GIT_OWNER'         => 'Vateron-Media',
			'GIT_REPO_MAIN'     => 'XC_VM',
			'GIT_REPO_UPDATE'   => 'XC_VM_Update',
			'GIT_REPO_BIN'      => 'XC_VM_Binaries',
			'GIT_REPO_FANOUT'   => 'XC_VM_Fanout',
			'GIT_REPO_PROXY'    => 'XC_VM_Proxy',
			'MONITOR_CALLS'     => 3,
			'OPENSSL_EXTRA'     => 'fNiu3XD448xTDa27xoY4',
		];
	}

	/**
	 * Binary-path constants, derived from the bin directory.
	 *
	 * Only the FFmpeg 4.0 anchor is pinned here; other versions are resolved
	 * dynamically by FfmpegPaths/FfmpegBinaries.
	 *
	 * @param string $binPath Bin directory with trailing slash (BIN_PATH).
	 * @return array<string,string> Constant name => value.
	 */
	public static function binaries(string $binPath): array {
		return [
			'PHP_BIN'        => $binPath . 'php/bin/php',
			'YOUTUBE_BIN'    => $binPath . 'yt-dlp',
			'FFMPEG_FONT'    => $binPath . 'free-sans.ttf',
			'GEOLITE2_BIN'   => $binPath . 'maxmind/GeoLite2-Country.mmdb',
			'GEOLITE2C_BIN'  => $binPath . 'maxmind/GeoLite2-City.mmdb',
			'GEOISP_BIN'     => $binPath . 'maxmind/GeoIP2-ISP.mmdb',
			'FFMPEG_BIN_40'  => $binPath . 'ffmpeg_bin/4.0/ffmpeg',
			'FFPROBE_BIN_40' => $binPath . 'ffmpeg_bin/4.0/ffprobe',
		];
	}

	/**
	 * STATUS_* result codes used across admin/reseller API handlers.
	 *
	 * @return array<string,int> Constant name => value.
	 */
	public static function statuses(): array {
		return [
			'STATUS_FAILURE'             => 0,
			'STATUS_SUCCESS'             => 1,
			'STATUS_SUCCESS_MULTI'       => 2,
			'STATUS_CODE_LENGTH'         => 3,
			'STATUS_NO_SOURCES'          => 4,
			'STATUS_DISABLED'            => 5,
			'STATUS_NOT_ADMIN'           => 6,
			'STATUS_INVALID_EMAIL'       => 7,
			'STATUS_INVALID_PASSWORD'    => 8,
			'STATUS_INVALID_IP'          => 9,
			'STATUS_INVALID_PLAYLIST'    => 10,
			'STATUS_INVALID_NAME'        => 11,
			'STATUS_INVALID_CAPTCHA'     => 12,
			'STATUS_INVALID_CODE'        => 13,
			'STATUS_INVALID_DATE'        => 14,
			'STATUS_INVALID_FILE'        => 15,
			'STATUS_INVALID_GROUP'       => 16,
			'STATUS_INVALID_DATA'        => 17,
			'STATUS_INVALID_DIR'         => 18,
			'STATUS_INVALID_MAC'         => 19,
			'STATUS_EXISTS_CODE'         => 20,
			'STATUS_EXISTS_NAME'         => 21,
			'STATUS_EXISTS_USERNAME'     => 22,
			'STATUS_EXISTS_MAC'          => 23,
			'STATUS_EXISTS_SOURCE'       => 24,
			'STATUS_EXISTS_IP'           => 25,
			'STATUS_EXISTS_DIR'          => 26,
			'STATUS_SUCCESS_REPLACE'     => 27,
			'STATUS_FLUSH'               => 28,
			'STATUS_TOO_MANY_RESULTS'    => 29,
			'STATUS_SPACE_ISSUE'         => 30,
			'STATUS_INVALID_USER'        => 31,
			'STATUS_CERTBOT'             => 32,
			'STATUS_CERTBOT_INVALID'     => 33,
			'STATUS_INVALID_INPUT'       => 34,
			'STATUS_NOT_RESELLER'        => 35,
			'STATUS_NO_TRIALS'           => 36,
			'STATUS_INSUFFICIENT_CREDITS' => 37,
			'STATUS_INVALID_PACKAGE'     => 38,
			'STATUS_INVALID_TYPE'        => 39,
			'STATUS_INVALID_USERNAME'    => 40,
			'STATUS_INVALID_SUBRESELLER' => 41,
			'STATUS_NO_DESCRIPTION'      => 42,
			'STATUS_NO_KEY'              => 43,
			'STATUS_EXISTS_HMAC'         => 44,
			'STATUS_CERTBOT_RUNNING'     => 45,
			'STATUS_RESERVED_CODE'       => 46,
			'STATUS_NO_TITLE'            => 47,
			'STATUS_NO_SOURCE'           => 48,
		];
	}

	/**
	 * Resolve the per-install OPENSSL_EXTRA secret from config, falling back to
	 * the historical literal when none is stored.
	 *
	 * The literal fallback is mandatory: OPENSSL_EXTRA feeds key/HMAC derivation
	 * for data persisted before this mechanism existed (hmac_keys rows, cached
	 * image filenames, proxy URL keys, stream tokens), so an install with no
	 * stored secret MUST keep deriving from the old value or that data orphans.
	 * The secret must therefore be generated once at fresh install and never
	 * rotated afterward.
	 *
	 * @param string $fallback Historical literal default.
	 */
	private static function resolveOpensslExtra(string $fallback): string {
		try {
			$value = ConfigReader::get('openssl_extra');
			if (is_string($value) && $value !== '') {
				return $value;
			}
		} catch (\Throwable) {
			// Config extension unavailable (tests, early boot) — use the literal.
		}

		return $fallback;
	}

	/**
	 * define() every entry that is not already defined.
	 *
	 * @param array<string,scalar> $constants Constant name => value.
	 */
	private static function defineAll(array $constants): void {
		foreach ($constants as $name => $value) {
			if (!defined($name)) {
				define($name, $value);
			}
		}
	}
}
