<?php

namespace XcVm\Core\Bootstrap;

/**
 * Runs an ordered list of boot stages against a BootState.
 *
 * A failing stage aborts the pipeline (it throws), mirroring the fail-loud
 * behaviour of the old monolithic boot(). The stage list is introspectable so
 * tests can assert per-context ordering.
 *
 * @package XC_VM_Core_Bootstrap
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class BootPipeline {
	/**
	 * @param list<BootStageInterface> $stages Ordered stages.
	 */
	public function __construct(private array $stages) {
	}

	public function run(BootState $state): void {
		foreach ($this->stages as $stage) {
			$stage->run($state);
		}
	}

	/**
	 * @return list<BootStageInterface>
	 */
	public function stages(): array {
		return $this->stages;
	}
}
