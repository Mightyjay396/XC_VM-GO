<?php

/**
 * Error-code catalogue — backward-compatibility shim.
 *
 * The catalogue now lives in ErrorResponder::codes(). This file is kept because
 * several boot paths require_once it by name and a handful of legacy consumers
 * read the global $rErrorCodes directly; it bridges the array into that global.
 *
 * @package XC_VM_Core_Error
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

use XcVm\Core\Error\ErrorResponder;

$GLOBALS['rErrorCodes'] = ErrorResponder::codes();
