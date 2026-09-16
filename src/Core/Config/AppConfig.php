<?php

/**
 * Application constants — backward-compatibility shim.
 *
 * Versioning, Git repos and feature flags now live in ConstantsInitializer (the
 * single define() site). Kept because boot paths require_once it by name; it
 * delegates to the same idempotent initializer as Paths.php / Binaries.php.
 *
 * @package XC_VM_Core_Config
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

use XcVm\Core\Config\ConstantsInitializer;

ConstantsInitializer::init();
