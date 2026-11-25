<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Domain\Stream\ProxyService;

/** SourceProxiesController — liste de proxys réutilisables (Serveurs > Proxys). @renders Views/admin/source_proxies.php */
class SourceProxiesController extends BaseAdminController {
	public function index() {
		$this->requirePermission();
		$this->setTitle("Proxys sources");
		$this->render("source_proxies", ['rProxies' => ProxyService::all(), 'rUsage' => ProxyService::usage()]);
	}
}
