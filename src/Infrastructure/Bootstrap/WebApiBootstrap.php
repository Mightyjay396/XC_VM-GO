<?php

namespace XcVm\Infrastructure\Bootstrap;

use XcVm\Core\Bootstrap\BootPipeline;
use XcVm\Core\Bootstrap\BootState;
use XcVm\Core\Bootstrap\Stage\DatabaseStage;
use XcVm\Core\Bootstrap\Stage\FloodProtectionStage;
use XcVm\Core\Bootstrap\Stage\HostVerificationStage;
use XcVm\Core\Bootstrap\Stage\LegacyCoreStage;
use XcVm\Core\Bootstrap\Stage\WebApiLoggerStage;
use XcVm\Core\Config\ConstantsInitializer;
use XcVm\Core\Container\ServiceContainer;
use XcVm\Core\Enum\BootContext;
use XcVm\Core\Updates\UpdateChannels;

/**
 * WebApiBootstrap — bootstrap для web API endpoint'ов
 *
 * The shared DB + LegacyInitializer step now runs through the same DatabaseStage
 * and LegacyCoreStage the main BootKernel uses, so there is one source of truth
 * for "connect, wire the domain services, run initCore, reconnect if the settings
 * cache is incomplete". Flood/host verification reuse the same
 * FloodProtectionStage/HostVerificationStage every other BootContext uses
 * (formerly duplicated inline in the now-removed Core/Http/RequestGuard.php);
 * WebApiLoggerStage covers the one WebApi-specific difference — PHP_ERRORS also
 * honours the runtime `debug_show_errors` setting, not just DEV_MODE. The
 * web-API-specific prelude (constants split around the ini_set defaults) and
 * the $gitRelease global stay inline — they are order-sensitive and not part
 * of the container-based boot.
 *
 * @package XC_VM_Infrastructure_Bootstrap
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class WebApiBootstrap {
	/** Endpoints that read settings from the file cache instead of SQL. */
	private const CACHED_ENDPOINTS = ['enigma2', 'epg', 'playlist', 'api', 'xplugin', 'live', 'proxy_api', 'thumb', 'timeshift', 'vod'];

	/**
	 * Инициализирует web API контекст.
	 *
	 * @param string $rFilename  Имя endpoint'а (enigma2, epg, playlist, xplugin, api, …)
	 */
	public static function init(string $rFilename): void {
		// ── 1. PHP defaults + constants ──────────────────────────
		// generateError()/generate404() are provided globally via autoload.files.
		@ini_set('user_agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/83.0.4103.61 Safari/537.36');
		@ini_set('default_socket_timeout', 5);
		ConstantsInitializer::init();

		// ── 2. Flood / host / Logger / DB + LegacyInitializer (shared stages) ──
		$rUseCache = in_array($rFilename, self::CACHED_ENDPOINTS, true);

		$state = new BootState(BootContext::WebApi, ['cached' => $rUseCache], ServiceContainer::getInstance());
		(new BootPipeline([
			new FloodProtectionStage(),
			new HostVerificationStage(),
			new WebApiLoggerStage(),
			new DatabaseStage(),
			new LegacyCoreStage($rUseCache),
		]))->run($state);

		// ── 6. GithubReleases ────────────────────────────────────
		global $gitRelease;
		// phpcs:ignore SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- declared global $gitRelease; consumed by legacy update code
		$gitRelease = UpdateChannels::mainReleases();
	}
}
