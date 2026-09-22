<?php

namespace XcVm\Public\Controllers\Admin\Ajax;

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Container\ServiceContainer;
use XcVm\Core\Enum\ModuleState;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Module\ModuleManager;

/**
 * Admin-ajax controller for the modules table's row actions.
 *
 * `action=module&sub=...` replaces the page POSTing to itself: the table is a
 * DataTable fed by `./table?id=modules`, so an action only has to report what
 * happened and let the table reload — no HTML round-trip.
 *
 * Every response carries the module's state AFTER the action, read back from
 * ModuleManager, so the caller never has to infer it from the request it sent.
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class ModuleAjaxController extends BaseAjaxController {
	/** action=module — per-row module lifecycle operations. */
	public function module(): never {
		$this->requireXhr();
		// The modules page itself falls through PageAuthorization's default-allow,
		// so gate the operations explicitly — installing or removing a module is
		// system-level, same as the settings page.
		$this->gate('adv', 'settings');

		$rManager = new ModuleManager(container: ServiceContainer::getInstance());
		$rName    = (string) RequestManager::get('name');
		$rSub     = (string) RequestManager::get('sub');

		if ($rName === '') {
			$this->fail(['message' => 'Module name is required.']);
		}

		try {
			$rMessage = $this->apply($rManager, $rSub, $rName);
		} catch (\Throwable $e) {
			// The manager refuses with a message worth showing verbatim (e.g.
			// "still required by plex"), so pass it through rather than flatten it.
			$this->fail(['message' => $e->getMessage(), 'module' => $this->snapshot($rManager, $rName)]);
		}

		$this->ok(['message' => $rMessage, 'module' => $this->snapshot($rManager, $rName)]);
	}

	/**
	 * Run one sub-action and return the message to show.
	 *
	 * @throws \RuntimeException on an unknown sub-action or a refused operation.
	 */
	private function apply(ModuleManager $rManager, string $rSub, string $rName): string {
		switch ($rSub) {
			case 'enable':
				$rManager->setState($rName, ModuleState::Enabled);
				return 'Module enabled: ' . $rName;

			case 'disable':
				$rManager->setState($rName, ModuleState::Disabled);
				return 'Module disabled: ' . $rName;

			case 'install':
				$rManager->installModule($rName);
				return 'Module installed: ' . $rName;

			case 'update':
				$rNew = $rManager->updateModuleFromSource($rName);
				return $rNew !== null
					? 'Module updated: ' . $rName . ' -> ' . $rNew
					: 'Nothing newer at the source for ' . $rName . ' — already up to date.';

			case 'uninstall':
				$rManager->uninstallModule($rName);
				return 'Module uninstalled: ' . $rName;

			case 'delete':
				$rManager->deleteModule($rName);
				return 'Module deleted: ' . $rName;

			case 'rollback':
				$rManager->rollbackFromPlatform($rName, $this->platformKey());
				return 'Module rolled back to previous version: ' . $rName;

			case 'renew_license':
				return $rManager->renewModuleLicense($rName, $this->platformKey())
					? 'License renewed for: ' . $rName
					: 'License not renewed for ' . $rName . ' (platform licensing off, not entitled, or no server data).';
		}

		throw new \RuntimeException('Unknown module action: ' . $rSub);
	}

	/** The platform API key, or a refusal when the operator has not set one. */
	private function platformKey(): string {
		$rKey = (string) (SettingsManager::get('platform_api_key') ?? '');
		if ($rKey === '') {
			throw new \RuntimeException('Set the platform API key first.');
		}

		return $rKey;
	}

	/**
	 * The module's state as it stands now — null when it is no longer listed
	 * (a delete), which the caller reads as "gone" rather than "unchanged".
	 *
	 * @return array{name: string, enabled: bool, state: string, installed: bool}|null
	 */
	private function snapshot(ModuleManager $rManager, string $rName): ?array {
		foreach ($rManager->listModules() as $rModule) {
			if ($rModule['name'] !== $rName) {
				continue;
			}

			return [
				'name'      => $rModule['name'],
				'enabled'   => (bool) $rModule['enabled'],
				'state'     => $rModule['state']->value,
				'installed' => $rModule['installed_version'] !== '',
			];
		}

		return null;
	}
}
