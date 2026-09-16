<?php

namespace XcVm\Core\Bootstrap;

/**
 * One step of a boot pipeline.
 *
 * A stage mutates the shared BootState (sets readiness flags, registers services,
 * defines constants) and is otherwise self-contained, so it can be constructed
 * with fakes and run against a hand-built BootState in a unit test.
 *
 * @package XC_VM_Core_Bootstrap
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
interface BootStageInterface {
	public function run(BootState $state): void;
}
