<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Cluster\Crypto\ClusterCryptoFactory;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ClusterPolicy;
use XcVm\Domain\Cluster\EnrolCodeService;
use XcVm\Domain\Server\InstallCredentials;
use XcVm\Domain\Server\ServerRepository;

/**
 * ClusterEnrolCodeCommand — issue a break-glass enrolment code for a load
 * balancer that MAIN cannot reach over SSH (NAT, lost keys). The code carries
 * MAIN's URL, the panel key's hash and a one-time secret; it lives 30 minutes.
 * The node's request then waits for `cluster:enrol-approve` with its SAS.
 *
 * Usage: `console.php cluster:enrol-code <serverID> [--url=http://host:port]`
 * (default URL: the first of the node policy's MAIN URLs). MAIN only.
 *
 * @package XC_VM_CLI_Commands
 */
class ClusterEnrolCodeCommand implements CommandInterface {
	public function getName(): string {
		return 'cluster:enrol-code';
	}

	public function getDescription(): string {
		return 'Issue a one-time enrolment code for a load balancer (no SSH needed)';
	}

	public function execute(array $rArgs): int {
		[$rArgs, $rOptions] = InstallCredentials::splitOptions($rArgs);
		$rServerID = intval($rArgs[0] ?? 0);
		if ($rServerID <= 0) {
			echo "Usage: cluster:enrol-code <serverID> [--url=http://host:port]\n";
			return 1;
		}
		$rSettings = SettingsManager::getAll();
		if (empty($rSettings['cluster_api_enabled'])) {
			echo "The cluster API is disabled (Settings → Cluster). Exiting\n";
			return 1;
		}
		try {
			$rCrypto = ClusterCryptoFactory::create();
		} catch (\Throwable $rE) {
			echo 'Cluster API unavailable: ' . $rE->getMessage() . ". Exiting\n";
			return 1;
		}
		$rServers = ServerRepository::getAll(true);
		$rServer = $rServers[$rServerID] ?? null;
		if ($rServer === null || !empty($rServer['is_main']) || intval($rServer['server_type'] ?? 0) !== 0) {
			echo "Server {$rServerID} is not a load balancer. Exiting\n";
			return 1;
		}
		$rUrl = (string) ($rOptions['url'] ?? '');
		if ($rUrl === '') {
			$rFirst = ClusterPolicy::current($rSettings, $rServers[SERVER_ID] ?? [])['main_urls'][0] ?? '';
			$rUrl = (string) preg_replace('#/cluster/v1/$#', '', $rFirst);
		}
		try {
			$rOut = EnrolCodeService::generate($rCrypto, $rServerID, rtrim($rUrl, '/'));
		} catch (\InvalidArgumentException) {
			echo "Not a usable MAIN URL: '{$rUrl}' (scheme, host and port, e.g. http://10.0.0.1:25461). Exiting\n";
			return 1;
		}
		echo "Enrolment code for server {$rServerID} (MAIN {$rOut['main_url']}), valid until " . gmdate('Y-m-d H:i:s', $rOut['exp']) . " UTC:\n\n";
		echo '  ' . $rOut['code'] . "\n\n";
		echo "On the node, as root:\n";
		echo "  sudo -u xc_vm " . LbInstallFlow::AGENT_BIN . ' enrol -state ' . LbInstallFlow::AGENT_STATE . " <code>\n";
		echo "It prints a SAS; approve it here with: console.php cluster:enrol-approve {$rServerID} <SAS>\n";
		return 0;
	}
}
