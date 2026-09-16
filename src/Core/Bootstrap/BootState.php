<?php

namespace XcVm\Core\Bootstrap;

use XcVm\Core\Container\ServiceContainer;
use XcVm\Core\Database\DatabaseHandler;
use XcVm\Core\Enum\BootContext;

/**
 * Mutable state threaded through a boot pipeline.
 *
 * Replaces the per-subsystem static flags of the old XC_Bootstrap god-class.
 * Each stage reads and writes this object instead of static state, which is what
 * makes stages runnable and assertable in isolation. The readiness flags are
 * consumed by ContainerPopulateStage / HealthCheckStage to decide what to
 * register and verify.
 *
 * @package XC_VM_Core_Bootstrap
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class BootState {
	public bool $booted = false;

	public bool $devMode = false;

	public bool $constantsLoaded = false;

	public bool $configLoaded = false;

	public bool $loggerStarted = false;

	public bool $databaseReady = false;

	public bool $coreReady = false;

	public bool $adminReady = false;

	public bool $sessionStarted = false;

	public bool $redisReady = false;

	public ?DatabaseHandler $db = null;

	/**
	 * @param array<string,mixed> $options Resolved boot options (cached/redis/process/shutdown).
	 */
	public function __construct(
		public readonly BootContext $context,
		public readonly array $options,
		public readonly ServiceContainer $container,
	) {
	}

	/**
	 * Whether the process is running under the CLI SAPI.
	 *
	 * Several stages (flood/host/session) are HTTP-only and self-skip on CLI.
	 */
	public function isCli(): bool {
		return php_sapi_name() === 'cli' || defined('STDIN');
	}
}
