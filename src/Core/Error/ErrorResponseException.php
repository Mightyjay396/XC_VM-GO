<?php

namespace XcVm\Core\Error;

use RuntimeException;

/**
 * Value carrier for a resolved error response.
 *
 * ErrorResponder::respondError()/respond404() build one of these describing what
 * the request should emit (body, HTTP code, whether to terminate). In production
 * the global generateError()/generate404() shims read its fields and exit(); in
 * test mode (ErrorResponder::$throwInsteadOfExit) the terminal exit() becomes a
 * throw of this exception, so a caller that would otherwise kill the process can
 * be asserted against instead.
 *
 * @package XC_VM_Core_Error
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class ErrorResponseException extends RuntimeException {
	public function __construct(
		public readonly string $errorCode,
		public readonly ?int $httpCode,
		public readonly string $body,
		public readonly bool $is404,
		public readonly bool $shouldExit,
	) {
		parent::__construct(sprintf(
			'XC_VM error response: %s (http %s)',
			$errorCode !== '' ? $errorCode : '404',
			$httpCode ?? 404
		));
	}
}
