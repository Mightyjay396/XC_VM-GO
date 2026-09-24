<?php

namespace XcVm\Core\Bootstrap\Stage;

use XcVm\Core\Bootstrap\BootState;
use XcVm\Core\Bootstrap\BootStageInterface;
use XcVm\Core\Logging\Logger;

/**
 * WebApi-specific PHP_ERRORS + Logger init.
 *
 * Unlike ConstantsStage (DEV_MODE-only, used by every other context), WebApi
 * endpoints also honour the runtime `debug_show_errors` setting from the file
 * cache, so an admin can toggle API error visibility without a DEV_MODE
 * redeploy. Runs after HostVerificationStage in WebApiBootstrap::init() but
 * does not depend on it — reads the settings file cache independently.
 *
 * @package XC_VM_Core_Bootstrap_Stage
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class WebApiLoggerStage implements BootStageInterface {
	public function run(BootState $state): void {
		$rShowErrors = false;

		if (file_exists(CACHE_TMP_PATH . 'settings')) {
			$rData = file_get_contents(CACHE_TMP_PATH . 'settings');
			$rSettings = igbinary_unserialize($rData);

			if (is_array($rSettings)) {
				$rShowErrors = $rSettings['debug_show_errors'] ?? false;
			}
		}

		if (defined('DEV_MODE') && DEV_MODE) {
			$rShowErrors = true;
		}

		define('PHP_ERRORS', $rShowErrors);

		Logger::init(
			PHP_ERRORS,
			LOGS_TMP_PATH . 'error_log.log'
		);
	}
}
