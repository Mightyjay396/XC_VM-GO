<?php

namespace XcVm\Core\Module\Contract;

use XcVm\Core\Http\Pipeline\StreamMiddlewareInterface;

/**
 * Former opt-in contract for modules to add stream middleware.
 *
 * @deprecated Core never ran a stream pipeline, so getStreamMiddleware() was
 *             never called; the pipeline is gone. Kept only so a module that
 *             still implements it keeps loading. Do not implement it.
 *
 * @package XC_VM_Core_Module
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
interface StreamMiddlewareProviderInterface {
	/**
	 * Stream middleware instances; never called (see the interface note).
	 *
	 * Modules return one or more middleware objects. Each must implement
	 * StreamMiddlewareInterface and declare its own priority via getPriority().
	 *
	 * @return StreamMiddlewareInterface[]
	 */
	public function getStreamMiddleware(): array;
}
