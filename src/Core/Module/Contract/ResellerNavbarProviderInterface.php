<?php

namespace XcVm\Core\Module\Contract;

use XcVm\Core\Module\ResellerNavbarRegistry;

/**
 * @package XC_VM_Core_Module
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
interface ResellerNavbarProviderInterface {
	/**
	 * Register reseller navbar items via the provided ResellerNavbarRegistry.
	 *
	 * Called in bootAll() after all modules have been booted.
	 * Use $registry->add() instead of ResellerNavbarRegistry::add() (static).
	 *
	 * Named distinctly from NavbarProviderInterface::registerNavbar() so a
	 * module can implement both interfaces (PHP methods aren't overloaded by
	 * parameter type).
	 */
	public function registerResellerNavbar(ResellerNavbarRegistry $registry): void;
}
