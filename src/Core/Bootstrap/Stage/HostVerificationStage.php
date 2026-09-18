<?php

namespace XcVm\Core\Bootstrap\Stage;

use XcVm\Core\Bootstrap\BootState;
use XcVm\Core\Bootstrap\BootStageInterface;

/**
 * Ensure the request arrives on an allowed domain (when verify_host is set in
 * the settings cache). HTTP contexts only — self-skips on CLI.
 *
 * @package XC_VM_Core_Bootstrap_Stage
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class HostVerificationStage implements BootStageInterface {
	public function run(BootState $state): void {
		if ($state->isCli()) {
			return;
		}

		if (!defined('HOST')) {
			$host = trim(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);
			define('HOST', $host);
		}

		// Domain check via settings cache
		if (file_exists(CACHE_TMP_PATH . 'settings')) {
			$rData = @file_get_contents(CACHE_TMP_PATH . 'settings');
			if ($rData !== false) {
				$rSettings = @igbinary_unserialize($rData);
				if (is_array($rSettings) && !empty($rSettings['verify_host'])) {
					if (file_exists(CACHE_TMP_PATH . 'allowed_domains')) {
						$rDomains = @igbinary_unserialize(@file_get_contents(CACHE_TMP_PATH . 'allowed_domains'));
						if (is_array($rDomains) && count($rDomains) > 0
							&& !in_array(HOST, $rDomains) && HOST !== 'xc_vm'
							&& !filter_var(HOST, FILTER_VALIDATE_IP)
						) {
							\generateError('INVALID_HOST');
						}
					}
				}
			}
		}
	}
}
