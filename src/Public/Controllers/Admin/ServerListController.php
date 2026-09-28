<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Core\Cluster\ClusterSettings;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ClusterAdmin;
use XcVm\Domain\Server\ServerRepository;

/**
 * ServerListController — список серверов (admin/servers.php).
 *
 * GET /servers
 * Client-side DataTable — PHP рендерит <tbody>.
 * Данные: ServerRepository::getAll(true).
 *
 * @renders Views/admin/servers.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ServerListController extends BaseAdminController {
	public function index(): void {
		$this->requirePermission();
		$this->setTitle('Servers');

		$rServers = ServerRepository::getAll(true);
		// Which of these servers is a cluster node, and how far it has moved: the
		// Cluster Nodes page has it all, and an operator reading this list had to
		// correlate by server id to know whether a node still holds MAIN's
		// credentials. One badge per node, from the same rows that page shows.
		$this->render('servers', ['rServers' => $rServers, 'rClusterNodes' => self::clusterNodes($rServers)]);
	}

	/**
	 * `server id => {state, health, mode}` for the enrolled nodes, or an empty
	 * list when the API is off (or its tables are not there yet: the list is a
	 * page an operator opens before `cluster:init`).
	 *
	 * @param array<int, array<string, mixed>> $rServers
	 * @return array<int, array<string, mixed>>
	 */
	private static function clusterNodes(array $rServers): array {
		if (empty(SettingsManager::get('cluster_api_enabled'))) {
			return [];
		}
		try {
			$rOfflineAfter = ClusterSettings::int('cluster_offline_after_sec', SettingsManager::get('cluster_offline_after_sec'));
			$rOut = [];
			foreach (ClusterAdmin::nodes($rServers, $rOfflineAfter) as $rNode) {
				$rOut[(int) $rNode['server_id']] = $rNode;
			}
			return $rOut;
		} catch (\Throwable) {
			return [];
		}
	}
}
