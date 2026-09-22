<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Core\Util\AdminHelpers;
use XcVm\Domain\Line\PackageService;

/**
 * PackageEditController — add/edit package.
 *
 * Route: GET /admin/package → index()
 *
 * @renders Views/admin/package.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class PackageEditController extends BaseAdminController {
	public function index() {
		$this->requirePermission();

		$rPackage = null;
		$id = $this->input('id');
		if ($id !== null) {
			$rPackage = PackageService::getById($id);
			if (!$rPackage) {
				AdminHelpers::goHome();
				return;
			}
		}

		$this->setTitle('Package');
		$this->render('package', ['rPackage' => $rPackage]);
	}
}
