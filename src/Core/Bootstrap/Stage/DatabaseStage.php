<?php

namespace XcVm\Core\Bootstrap\Stage;

use XcVm\Core\Bootstrap\BootState;
use XcVm\Core\Bootstrap\BootStageInterface;
use XcVm\Core\Database\DatabaseHandler;

/**
 * Open the MySQL/MariaDB connection and publish it as the global $db (backward
 * compatibility) and on the BootState.
 *
 * @package XC_VM_Core_Bootstrap_Stage
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class DatabaseStage implements BootStageInterface {
	public function run(BootState $state): void {
		if ($state->databaseReady) {
			return;
		}

		global $db;

		$db = new DatabaseHandler();

		$state->db            = $db;
		$state->databaseReady = true;
	}
}
