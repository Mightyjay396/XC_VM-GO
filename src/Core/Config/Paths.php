<?php

/**
 * Path constants — backward-compatibility shim.
 *
 * The path, app-config and binary constants now live in ConstantsInitializer
 * (the single define() site). This file is kept because several boot paths
 * require_once it by name; it simply delegates. init() is idempotent and
 * defines the full, correctly-ordered set regardless of which prelude file is
 * required first.
 *
 * @package XC_VM_Core_Config
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

use XcVm\Core\Config\ConstantsInitializer;

ConstantsInitializer::init();
