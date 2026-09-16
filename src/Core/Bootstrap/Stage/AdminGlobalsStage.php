<?php

namespace XcVm\Core\Bootstrap\Stage;

use XcVm\Core\Bootstrap\BootState;
use XcVm\Core\Bootstrap\BootStageInterface;
use XcVm\Core\Config\ConfigReader;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Localization\Translator;
use XcVm\Domain\Server\ServerRepository;

/**
 * Initialize the legacy admin view globals: MobileDetect, timeouts, server
 * lists, protocol, allowed languages, and the reseller assets symlink.
 *
 * @package XC_VM_Core_Bootstrap_Stage
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class AdminGlobalsStage implements BootStageInterface {
	public function run(BootState $state): void {
		global $rDetect, $rMobile, $rTimeout, $rSQLTimeout, $rProtocol,
			$allServers, $rServers, $rSettings, $rProxyServers,
			$rPermissions, $allowedLangs;

		if (!defined('SERVER_ID')) {
			define('SERVER_ID', intval(ConfigReader::get('server_id')));
		}

		$rDetect = new \Detection\MobileDetect();
		$rMobile = $rDetect->isMobile();

		$rTimeout    = 15;
		$rSQLTimeout = 10;
		set_time_limit($rTimeout);
		ini_set('mysql.connect_timeout', (string) $rSQLTimeout);
		ini_set('max_execution_time', (string) $rTimeout);
		ini_set('default_socket_timeout', (string) $rTimeout);

		$rProtocol    = $this->detectProtocol();
		$allServers   = ServerRepository::getAllSimple();
		$rServers     = ServerRepository::getStreamingSimple($rPermissions);
		$rSettings    = SettingsManager::getAll();
		if ($state->devMode) {
			$rSettings['debug_show_errors'] = true;
		}
		$rProxyServers = ServerRepository::getProxySimple($rPermissions);

		$allowedLangs = Translator::available();

		// Sort servers by order
		if (is_array($rServers)) {
			uasort(
				$rServers,
				function ($a, $b) {
					return $a['order'] - $b['order'];
				}
			);
		}

		// Ensure the legacy 'reseller' assets alias (Public/assets/reseller → admin).
		$this->ensureResellerAssetsSymlink();
	}

	/**
	 * Ensure the legacy 'reseller' assets alias exists (Public/assets/reseller → admin).
	 *
	 * Reseller pages reuse the admin asset bundle, and some nginx configs / legacy
	 * routes expect Public/assets/reseller to resolve to Public/assets/admin. The
	 * link is not stored in git (an absolute-path symlink broke the build), so the
	 * panel recreates it here. Idempotent, and repairs a stale/broken link.
	 */
	private function ensureResellerAssetsSymlink(): void {
		$assetsBase   = MAIN_HOME . 'Public/assets/';
		$resellerLink = $assetsBase . 'reseller';

		// Nothing to point at yet — skip.
		if (!is_dir($assetsBase . 'admin')) {
			return;
		}

		if (is_link($resellerLink)) {
			// Correct, resolvable link → nothing to do.
			if (readlink($resellerLink) === 'admin' && is_dir($resellerLink)) {
				return;
			}
			@unlink($resellerLink); // stale/broken link — recreate below
		} elseif (file_exists($resellerLink)) {
			return; // a real directory/file lives here — leave it alone
		}

		// Relative link so it is independent of MAIN_HOME and path case.
		if (!@symlink('admin', $resellerLink)) {
			// Without the link every reseller access code serves pages with no
			// CSS/JS (nginx aliases /CODE/assets/ to Public/assets/reseller/).
			error_log('AdminGlobalsStage: failed to create Public/assets/reseller -> admin symlink — check ownership of Public/assets/ (expected xc_vm).');
		}
	}

	/**
	 * Detect HTTP protocol (http/https).
	 */
	private function detectProtocol(): string {
		$https   = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
		$port443 = isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443;
		return ($https || $port443) ? 'https' : 'http';
	}
}
