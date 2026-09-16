<?php

namespace XcVm\Core\Bootstrap\Stage;

use XcVm\Core\Bootstrap\BootState;
use XcVm\Core\Bootstrap\BootStageInterface;
use XcVm\Infrastructure\Redis\RedisManager;

/**
 * Connect to Redis. redisReady means "connected", not "attempted": a failed
 * connection leaves it false so HealthCheckStage does not require the service
 * and the panel degrades instead of 500ing.
 *
 * @package XC_VM_Core_Bootstrap_Stage
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class RedisStage implements BootStageInterface {
	public function run(BootState $state): void {
		if ($state->redisReady) {
			return;
		}

		$state->redisReady = RedisManager::ensureConnected();
	}
}
