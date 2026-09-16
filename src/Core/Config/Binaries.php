<?php

/**
 * Binary-path constants — backward-compatibility shim.
 *
 * FFmpeg/FFprobe/GeoIP/PHP paths now live in ConstantsInitializer (the single
 * define() site), derived from BIN_PATH. Kept because boot paths require_once it
 * by name; it delegates to the same idempotent initializer as Paths.php.
 *
 * @package XC_VM_Core_Config
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

use XcVm\Core\Config\ConstantsInitializer;

ConstantsInitializer::init();
