<?php

namespace XcVm\Public\Controllers\Admin\Ajax;

use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Stream\ProxyService;

/** SourceProxyAjaxController — action=source_proxy_save | source_proxy_delete | source_proxy_test (Serveurs > Proxys). */
class SourceProxyAjaxController extends BaseAjaxController {
	public function save(): never {
		$this->requireXhr();
		$this->gate('adv', 'servers');
		$rName = trim((string) RequestManager::get('name'));
		$rHost = trim((string) RequestManager::get('host'));
		$rPort = (int) RequestManager::get('port');
		if ($rName === '' || $rHost === '' || $rPort < 1 || $rPort > 65535) {
			$this->fail(['error' => 'Nom, hôte et port sont obligatoires']);
		}
		[$rID, $rStreams] = ProxyService::save(['id' => (int) RequestManager::get('id'), 'name' => $rName, 'type' => (string) RequestManager::get('type'), 'host' => $rHost, 'port' => $rPort,
			'username' => (string) RequestManager::get('username'), 'password' => (string) RequestManager::get('password'), 'enabled' => (int) RequestManager::get('enabled', 1), 'notes' => (string) RequestManager::get('notes')]);
		$this->ok(['id' => $rID, 'streams' => $rStreams]);
	}

	public function delete(): never {
		$this->requireXhr();
		$this->gate('adv', 'servers');
		$this->ok(['streams' => ProxyService::delete((int) RequestManager::get('id'))]);
	}

	public function test(): never {
		$this->requireXhr();
		$this->gate('adv', 'servers');
		$this->json(ProxyService::test((int) RequestManager::get('id')));
	}
}
