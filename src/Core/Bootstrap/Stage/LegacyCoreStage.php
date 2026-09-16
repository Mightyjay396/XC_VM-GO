<?php

namespace XcVm\Core\Bootstrap\Stage;

use XcVm\Core\Bootstrap\BootState;
use XcVm\Core\Bootstrap\BootStageInterface;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Database\DatabaseHandler;
use XcVm\Core\Init\LegacyInitializer;
use XcVm\Infrastructure\Bootstrap\DomainDatabaseWiring;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * Initialize the legacy core: sanitize superglobals, parse config, define
 * SERVER_ID, load settings (DB or cache) and wire $db into the domain services.
 *
 * @package XC_VM_Core_Bootstrap_Stage
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class LegacyCoreStage implements BootStageInterface {
	/**
	 * @param bool $cached Load settings from the file cache instead of SQL
	 *                     (for high-load paths).
	 */
	public function __construct(private bool $cached = false) {
	}

	public function run(BootState $state): void {
		if ($state->coreReady) {
			return;
		}

		global $db;

		DatabaseFactory::set($db);

		// Wire $db into the domain service classes before initCore() runs,
		// since initCore() calls ServerRepository::getAll() which requires it.
		DomainDatabaseWiring::wire($db);

		LegacyInitializer::initCore($this->cached);

		// If cache was used and is incomplete — reconnect to DB
		if ($this->cached && !SettingsManager::getBool('enable_cache')) {
			$db = new DatabaseHandler();
			DatabaseFactory::set($db);
			DomainDatabaseWiring::wire($db);
		}

		$state->db       = $db;
		$state->coreReady = true;
	}
}
