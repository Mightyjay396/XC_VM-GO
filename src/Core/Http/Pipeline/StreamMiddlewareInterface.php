<?php

namespace XcVm\Core\Http\Pipeline;

/**
 * Former contract for stream pipeline middleware.
 *
 * @deprecated Core never ran a stream pipeline; nothing calls handle(). Kept
 *             only so a module class that still implements it keeps loading.
 *
 * @package XC_VM_Core_Http_Pipeline
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
interface StreamMiddlewareInterface {
	/**
	 * Handle the stream context and pass control to the next middleware.
	 *
	 * @param StreamContext       $ctx  Mutable context object
	 * @param callable(StreamContext): StreamContext $next Next middleware in chain
	 */
	public function handle(StreamContext $ctx, callable $next): StreamContext;

	/**
	 * Execution priority — higher value runs first.
	 *
	 * Core reserved ranges:
	 *   100 = AuthStreamMiddleware
	 *   90  = PermissionMiddleware
	 *   80  = ConnectionLimitMiddleware
	 *   0–79 = module middleware
	 *   -1  = ExecuteMiddleware (terminal)
	 */
	public function getPriority(): int;
}
