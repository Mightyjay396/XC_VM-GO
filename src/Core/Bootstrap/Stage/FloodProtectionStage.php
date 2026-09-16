<?php

namespace XcVm\Core\Bootstrap\Stage;

use XcVm\Core\Bootstrap\BootState;
use XcVm\Core\Bootstrap\BootStageInterface;

/**
 * Block banned IPs early. HTTP contexts only — self-skips on CLI.
 *
 * @package XC_VM_Core_Bootstrap_Stage
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class FloodProtectionStage implements BootStageInterface {
	public function run(BootState $state): void {
		if ($state->isCli()) {
			return;
		}

		$rIP = $_SERVER['REMOTE_ADDR'] ?? '';
		if (!empty($rIP) && file_exists(FLOOD_TMP_PATH . 'block_' . $rIP)) {
			http_response_code(403);
			exit();
		}
	}
}
