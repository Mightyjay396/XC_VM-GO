<?php

namespace XcVm\Core\Bootstrap\Stage;

use XcVm\Core\Bootstrap\BootState;
use XcVm\Core\Bootstrap\BootStageInterface;
use XcVm\Core\Logging\Logger;

/**
 * Load constants (paths, app-config, binaries, error functions) and start the
 * Logger. The prelude shims delegate to ConstantsInitializer; requiring them by
 * name keeps the legacy include points working.
 *
 * @package XC_VM_Core_Bootstrap_Stage
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class ConstantsStage implements BootStageInterface {
	public function run(BootState $state): void {
		if ($state->constantsLoaded) {
			return;
		}

		require_once MAIN_HOME . 'Core/Error/ErrorCodes.php';
		require_once MAIN_HOME . 'Core/Error/ErrorHandler.php';
		require_once MAIN_HOME . 'Core/Config/Paths.php';
		require_once MAIN_HOME . 'Core/Config/AppConfig.php';
		require_once MAIN_HOME . 'Core/Config/Binaries.php';

		$state->devMode = DEV_MODE;

		if (!defined('PHP_ERRORS')) {
			define('PHP_ERRORS', $state->devMode);
		}

		Logger::init(
			$state->devMode || PHP_ERRORS,
			LOGS_TMP_PATH . 'error_log.log'
		);

		$state->constantsLoaded = true;
		$state->configLoaded    = true;
		$state->loggerStarted   = true;
	}
}
