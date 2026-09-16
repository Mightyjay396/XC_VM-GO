<?php

namespace XcVm\Core\Bootstrap;

use XcVm\Core\Container\ServiceContainer;
use XcVm\Core\Enum\BootContext;

/**
 * Orchestrates a context-scoped boot: resolves options, sets up the container,
 * builds the stage list for the context and runs it against a fresh BootState.
 *
 * The XC_Bootstrap global class is now a thin static facade over this kernel;
 * WebApiBootstrap / StreamingRequestBootstrap converge here in later steps.
 *
 * @package XC_VM_Core_Bootstrap
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class BootKernel {
	/**
	 * @param array<string,mixed> $options Caller options, merged over the context defaults.
	 */
	public function boot(BootContext $context, array $options = []): BootState {
		$resolved = array_merge(self::defaults($context), $options);

		$container = ServiceContainer::getInstance();
		$container->set('context', $context->value);
		$container->set('options', $resolved);

		$state = new BootState($context, $resolved, $container);

		(new BootPipeline(StageProfiles::for($context, $resolved)))->run($state);

		$state->booted = true;

		return $state;
	}

	/**
	 * Default boot options for a context.
	 *
	 * @return array{cached: bool, redis: bool, process: string, shutdown: ?callable}
	 */
	public static function defaults(BootContext $context): array {
		return match ($context) {
			BootContext::Admin   => ['cached' => false, 'redis' => true,  'process' => '', 'shutdown' => null],
			BootContext::Stream  => ['cached' => true,  'redis' => false, 'process' => '', 'shutdown' => null],
			BootContext::Cli     => ['cached' => false, 'redis' => false, 'process' => '', 'shutdown' => null],
			BootContext::Minimal => ['cached' => false, 'redis' => false, 'process' => '', 'shutdown' => null],
			// WebApi boots via WebApiBootstrap (its own pipeline), not this kernel;
			// defined for exhaustiveness and per-endpoint 'cached' is passed directly.
			BootContext::WebApi  => ['cached' => false, 'redis' => false, 'process' => '', 'shutdown' => null],
		};
	}
}
