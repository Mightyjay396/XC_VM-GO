<?php

namespace XcVm\Core\Bootstrap\Stage;

use XcVm\Core\Bootstrap\BootState;
use XcVm\Core\Bootstrap\BootStageInterface;

/**
 * Set the CLI process title (for cron/worker identification).
 *
 * @package XC_VM_Core_Bootstrap_Stage
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class ProcessTitleStage implements BootStageInterface {
	public function __construct(private string $process) {
	}

	public function run(BootState $state): void {
		if ($this->process !== '') {
			cli_set_process_title($this->process);
		}
	}
}
