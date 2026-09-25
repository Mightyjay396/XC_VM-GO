<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Core\Cluster\Crypto\ClusterCryptoFactory;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ClusterAdmin;
use XcVm\Domain\Server\ServerRepository;

/**
 * ClusterNodesController — the cluster API's nodes (admin/cluster_nodes.php):
 * enrolled nodes with liveness, pending code enrolments approved by SAS,
 * enrolment codes, and revocation. Actions POST back to the page (session
 * cookies are SameSite=Strict); the page renders their result.
 *
 * GET|POST /cluster_nodes · permission: servers
 *
 * @renders Views/admin/cluster_nodes.php
 *
 * @package XC_VM_Public_Controllers_Admin
 */
class ClusterNodesController extends BaseAdminController {
	public function index(): void {
		$this->requirePermission();
		$this->setTitle('Cluster Nodes');

		$rSettings = SettingsManager::getAll();
		$rServers = ServerRepository::getAll(true);
		$rEnabled = !empty($rSettings['cluster_api_enabled']);
		$rAvailable = ClusterCryptoFactory::available();
		$rFlash = null;

		if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
			if (!$rEnabled || !$rAvailable) {
				$rFlash = ['type' => 'danger', 'message' => 'cluster_api_off'];
			} else {
				$rFlash = ClusterAdmin::act(ClusterCryptoFactory::create(), [
					'cluster_action' => $this->input('cluster_action', ''),
					'server_id' => $this->input('server_id', 0),
					'sas' => $this->input('sas', ''),
					'url' => $this->input('url', ''),
				], $rServers, (int) SERVER_ID, $rSettings, isset($GLOBALS['rUserInfo']['id']) ? (int) $GLOBALS['rUserInfo']['id'] : null);
			}
		}

		$rOffline = max(10, min(300, intval($rSettings['cluster_offline_after_sec'] ?? 30)));
		$this->render('cluster_nodes', [
			'clusterEnabled' => $rEnabled && $rAvailable,
			'clusterNodes' => ClusterAdmin::nodes($rServers, $rOffline),
			'clusterPending' => ClusterAdmin::pending($rServers),
			'clusterLbs' => ClusterAdmin::loadBalancers($rServers),
			'clusterFlash' => $rFlash,
		]);
	}
}
