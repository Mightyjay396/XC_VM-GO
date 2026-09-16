<?php

namespace XcVm\Core\Bootstrap\Stage;

use XcVm\Core\Bootstrap\BootState;
use XcVm\Core\Bootstrap\BootStageInterface;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Events\EventDispatcher;
use XcVm\Core\Localization\Translator;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Domain\Stream\CategoryService;
use XcVm\Infrastructure\Bootstrap\DomainDatabaseWiring;
use XcVm\Infrastructure\Redis\RedisManager;

/**
 * Register the initialized services in the container. Runs last (after all
 * subsystems), reading BootState readiness flags to decide what to publish:
 *   db, settings, servers, bouquets, categories, redis, translator, events.
 *
 * @package XC_VM_Core_Bootstrap_Stage
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class ContainerPopulateStage implements BootStageInterface {
	public function run(BootState $state): void {
		$container = $state->container;

		// Database
		if ($state->databaseReady) {
			global $db;
			$container->set('db', $db);
			DomainDatabaseWiring::wire($db);
		}

		// Settings and core data
		if ($state->coreReady) {
			$container->set('settings', SettingsManager::getAll());
			$container->set('servers', ServerRepository::getAll());
			$container->set('bouquets', BouquetService::getAll());
			$container->set('categories', CategoryService::getFromDatabase());

			if ($state->redisReady && RedisManager::isConnected()) {
				$container->set('redis', RedisManager::instance());
			}
		}

		// Translator
		if (class_exists(Translator::class, false) && Translator::available()) {
			$container->set('translator', Translator::class);
		}

		// Events — create an instance, wire it as the static singleton bridge,
		// and register it in the container so it can be injected via DI.
		$dispatcher = new EventDispatcher();
		EventDispatcher::setInstance($dispatcher);
		$container->set('events', $dispatcher);
	}
}
