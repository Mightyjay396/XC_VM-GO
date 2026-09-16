<?php

namespace XcVm\Core\Bootstrap\Stage;

use XcVm\Core\Bootstrap\BootState;
use XcVm\Core\Bootstrap\BootStageInterface;
use XcVm\Domain\User\ResellerAPI;
use XcVm\Domain\User\UserRepository;

/**
 * Initialize the admin/reseller API surface: resolve the logged-in admin user
 * and boot ResellerAPI.
 *
 * @package XC_VM_Core_Bootstrap_Stage
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class AdminApiStage implements BootStageInterface {
	public function run(BootState $state): void {
		if ($state->adminReady) {
			return;
		}

		if (isset($_SESSION['hash'])) {
			$GLOBALS['rAdminUserInfo'] = UserRepository::getRegisteredUserById($_SESSION['hash']);
		}

		ResellerAPI::init();

		$state->adminReady = true;
	}
}
