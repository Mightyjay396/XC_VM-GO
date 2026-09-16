<?php

/**
 * Unified initialization entry point (bootstrap)
 *
 * Provides context-dependent initialization for the entire application.
 * Handles: constant loading, DB connection, flood-protection, Logger,
 * error functions, session, Redis, Translator, and admin globals.
 *
 * ──────────────────────────────────────────────────────────────────
 * Initialization contexts:
 * ──────────────────────────────────────────────────────────────────
 *
 *   CONTEXT_MINIMAL  — autoload + constants + config + Logger only.
 *                      No DB connection. For scripts that only need
 *                      paths and configuration.
 *
 *   CONTEXT_CLI      — + Database + LegacyInitializer.
 *                      For cron jobs and CLI scripts.
 *
 *   CONTEXT_STREAM   — + Database + LegacyInitializer (lightweight path).
 *                      For streaming endpoints (live, vod, timeshift).
 *                      Does not load admin_api, Translator, etc.
 *
 *   CONTEXT_ADMIN    — + Database + LegacyInitializer + API + ResellerAPI
 *                      + Translator + MobileDetect + session.
 *                      Full initialization for admin/reseller panel.
 *
 * ──────────────────────────────────────────────────────────────────
 * Usage:
 * ──────────────────────────────────────────────────────────────────
 *
 *   // In an admin controller:
 *   require_once '/home/xc_vm/bootstrap.php';
 *   XC_Bootstrap::boot(XC_Bootstrap::CONTEXT_ADMIN);
 *
 *   // In a cron job:
 *   require_once '/home/xc_vm/bootstrap.php';
 *   XC_Bootstrap::boot(XC_Bootstrap::CONTEXT_CLI);
 *
 *   // In a streaming endpoint:
 *   require_once '/home/xc_vm/bootstrap.php';
 *   XC_Bootstrap::boot(XC_Bootstrap::CONTEXT_STREAM, ['cached' => true]);
 *
 *   // Constants only (no DB):
 *   require_once '/home/xc_vm/bootstrap.php';
 *   XC_Bootstrap::boot(XC_Bootstrap::CONTEXT_MINIMAL);
 *
 * The heavy lifting now lives in XcVm\Core\Bootstrap\BootKernel and its stages;
 * XC_Bootstrap is a thin, backward-compatible static facade over that pipeline.
 *
 * @package XC_VM
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

use XcVm\Core\Bootstrap\BootKernel;
use XcVm\Core\Bootstrap\BootState;
use XcVm\Core\Config\ConstantsInitializer;
use XcVm\Core\Container\ServiceContainer;
use XcVm\Core\Database\Database;
use XcVm\Core\Enum\BootContext;
use XcVm\Core\Events\EventDispatcher;
use XcVm\Infrastructure\Database\DatabaseFactory;

// ─────────────────────────────────────────────────────────────────
//  1. Class autoloader
// ─────────────────────────────────────────────────────────────────

// MAIN_HOME — the deploy root (src/ maps to /home/xc_vm/). Used by the runtime
// require_once paths below. Defined here now that the legacy autoload.php (which
// used to define it) has been removed.
if (!defined('MAIN_HOME')) {
	define('MAIN_HOME', __DIR__ . '/');
}

// Composer PSR-4 autoloader — resolves every XcVm\* class; modules load via
// ModuleLoader's PSR-4 resolver.
require_once __DIR__ . '/vendor/autoload.php';
// After this: MAIN_HOME is defined and the Composer autoloader is registered.


// ─────────────────────────────────────────────────────────────────
//  2. Polyfills (required before any HTTP processing)
// ─────────────────────────────────────────────────────────────────

if (!function_exists('getallheaders')) {
	/**
	 * Polyfill for getallheaders(): reconstruct request headers from $_SERVER.
	 *
	 * @return array<string,string> Header name => value.
	 */
	function getallheaders() {
		$headers = [];
		foreach ($_SERVER as $name => $value) {
			if (substr($name, 0, 5) === 'HTTP_') {
				$key = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
				$headers[$key] = $value;
			}
		}
		return $headers;
	}
}


// ─────────────────────────────────────────────────────────────────
//  3. XC_Bootstrap facade
// ─────────────────────────────────────────────────────────────────

class XC_Bootstrap {
	// ── Contexts (kept for backward compatibility — prefer BootContext enum) ──
	/** @deprecated Use BootContext::Minimal */
	const CONTEXT_MINIMAL  = 'minimal';
	/** @deprecated Use BootContext::Cli */
	const CONTEXT_CLI      = 'cli';
	/** @deprecated Use BootContext::Stream */
	const CONTEXT_STREAM   = 'stream';
	/** @deprecated Use BootContext::Admin */
	const CONTEXT_ADMIN    = 'admin';

	/** Result of the last boot(); null until the first boot. */
	private static ?BootState $state = null;

	/**
	 * Main entry point — delegates to the BootKernel pipeline.
	 *
	 * @param string|BootContext $context  Boot context. Accepts BootContext enum or legacy string constant.
	 * @param array              $options  Additional options:
	 *   'cached'      => bool   Use settings cache (for stream/cli, default: false)
	 *   'redis'       => bool   Connect Redis (default: true for admin, false for others)
	 *   'process'     => string Process name for cli_set_process_title()
	 *   'shutdown'    => callable Shutdown callback (replaces register_shutdown_function)
	 */
	public static function boot(string|BootContext $context = BootContext::Cli, array $options = []): void {
		if (self::$state?->booted) {
			return;
		}

		$ctx = $context instanceof BootContext ? $context : BootContext::from($context);

		self::$state = (new BootKernel())->boot($ctx, $options);
	}

	/**
	 * Current boot context.
	 */
	public static function getContext(): ?string {
		return self::$state?->context->value;
	}

	/**
	 * Whether bootstrap has been executed.
	 */
	public static function isBooted(): bool {
		return (bool) self::$state?->booted;
	}

	/**
	 * Whether dev mode is active (DEV_MODE constant in AppConfig.php is true).
	 * When true, PHP errors are displayed on-screen regardless of DB settings.
	 */
	public static function isDevMode(): bool {
		return (bool) self::$state?->devMode;
	}

	/**
	 * Database reference (backward compatibility).
	 */
	public static function getDatabase(): ?Database {
		global $db;
		return $db;
	}

	/**
	 * Get the ServiceContainer.
	 */
	public static function getContainer(): ServiceContainer {
		return ServiceContainer::getInstance();
	}

	/**
	 * Check whether running in CLI mode.
	 */
	public static function isCli(): bool {
		return php_sapi_name() === 'cli' || defined('STDIN');
	}

	/**
	 * Force reset (for testing): drops the boot state and the process-wide
	 * singletons the pipeline populates.
	 */
	public static function reset(): void {
		self::$state = null;

		ServiceContainer::resetInstance();
		EventDispatcher::resetInstance();
		DatabaseFactory::reset();
	}

	/**
	 * Define status constants (STATUS_FAILURE, STATUS_SUCCESS, ...).
	 *
	 * Used throughout admin and reseller API handlers. Called automatically in
	 * the Admin context; can be called manually when needed.
	 */
	public static function defineStatusConstants(): void {
		ConstantsInitializer::initStatus();
	}
}
