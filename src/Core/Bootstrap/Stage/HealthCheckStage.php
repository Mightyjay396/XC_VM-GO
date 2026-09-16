<?php

namespace XcVm\Core\Bootstrap\Stage;

use RuntimeException;
use XcVm\Core\Bootstrap\BootState;
use XcVm\Core\Bootstrap\BootStageInterface;

/**
 * Verify the services that should have been registered actually are, failing
 * loudly instead of degrading silently. Not run for the Minimal context.
 *
 * @package XC_VM_Core_Bootstrap_Stage
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class HealthCheckStage implements BootStageInterface {
	public function run(BootState $state): void {
		$container = $state->container;

		$required = ['events'];

		if ($state->databaseReady) {
			$required[] = 'db';
		}

		if ($state->redisReady) {
			$required[] = 'redis';
		}

		$missing = [];
		foreach ($required as $service) {
			if (!$container->has($service)) {
				$missing[] = $service;
			}
		}

		if ($missing !== []) {
			throw new RuntimeException(
				'ServiceContainer health check failed — missing required services: '
					. implode(', ', $missing)
			);
		}
	}
}
