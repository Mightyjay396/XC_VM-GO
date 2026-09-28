<?php

namespace XcVm\Public\Controllers\Api;

use XcVm\Core\Cluster\FileTicketServer;
use XcVm\Core\Config\SettingsManager;

/**
 * `GET /xfile` (ADR 0004, Phase 8): a file this server owns, read by another
 * server's agent with a panel-signed file ticket instead of the stream
 * secret in a `getFile` URL. FileTicketServer decides; this only speaks
 * HTTP. On MAIN and on a load balancer alike: both own files other servers
 * read.
 */
final class FileTicketController {
	public function handle(): void {
		$rOut = FileTicketServer::serve($_SERVER, $_GET, ['lb_scan_roots' => SettingsManager::get('lb_scan_roots')]);
		http_response_code($rOut['status']);
		foreach ($rOut['headers'] as $rName => $rValue) {
			header($rName . ': ' . $rValue);
		}
		echo $rOut['body'];
	}
}
