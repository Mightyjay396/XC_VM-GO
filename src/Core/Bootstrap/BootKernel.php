<?php

namespace XcVm\Core\Bootstrap;

use XcVm\Core\Cluster\ReplicaBoot;
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
		$resolved = self::resolve($context, $options);

		$container = ServiceContainer::getInstance();
		$container->set('context', $context->value);
		$container->set('options', $resolved);

		$state = new BootState($context, $resolved, $container);

		(new BootPipeline(StageProfiles::for($context, $resolved)))->run($state);

		$state->booted = true;

		return $state;
	}

	/**
	 * The caller's options over the context's defaults. For the CLI, an
	 * unset `replica` is this node's: ReplicaBoot::WHEN_READY in mode 2
	 * (ReplicaStage then replaces DatabaseStage and LegacyCoreStage), false
	 * otherwise, so mode 0 and 1 nodes and MAIN boot as before. A caller's
	 * ReplicaBoot::ALWAYS (cluster:apply) is kept in every mode.
	 *
	 * @param array<string,mixed> $options
	 * @return array<string,mixed>
	 */
	public static function resolve(BootContext $context, array $options = []): array {
		$resolved = array_merge(self::defaults($context), $options);
		if ($context === BootContext::Cli && $resolved['replica'] === null) {
			$resolved['replica'] = ReplicaBoot::wanted() ? ReplicaBoot::WHEN_READY : false;
		}
		return $resolved;
	}

	/**
	 * Default boot options for a context. `replica` is resolved per node
	 * (resolve()); only the CLI profile reads it.
	 *
	 * @return array{cached: bool, redis: bool, process: string, shutdown: ?callable, replica: string|false|null}
	 */
	public static function defaults(BootContext $context): array {
		return match ($context) {
			BootContext::Admin   => ['cached' => false, 'redis' => true,  'process' => '', 'shutdown' => null, 'replica' => null],
			BootContext::Stream  => ['cached' => true,  'redis' => false, 'process' => '', 'shutdown' => null, 'replica' => null],
			BootContext::Cli     => ['cached' => false, 'redis' => false, 'process' => '', 'shutdown' => null, 'replica' => null],
			BootContext::Minimal => ['cached' => false, 'redis' => false, 'process' => '', 'shutdown' => null, 'replica' => null],
			// WebApi boots via WebApiBootstrap (its own pipeline), not this kernel;
			// defined for exhaustiveness and per-endpoint 'cached' is passed directly.
			BootContext::WebApi  => ['cached' => false, 'redis' => false, 'process' => '', 'shutdown' => null, 'replica' => null],
		};
	}
}
