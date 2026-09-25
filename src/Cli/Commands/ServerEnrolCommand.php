<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Cluster\Crypto\ClusterCryptoFactory;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Domain\Server\InstallCredentials;
use XcVm\Domain\Server\ServerRepository;

/**
 * ServerEnrolCommand — enrol an existing (legacy) LB in the cluster API over
 * SSH (MAIN ↔ LB plan, section 6, path (a)). It reruns the install flow's
 * enrolment (LbInstallFlow::provisionCluster) without reinstalling.
 *
 * Trust on first use is refused: the node's SSH host key must match the
 * `--expect-hostkey` given (the SHA-1 of `ssh-keygen -l -E sha1 -f
 * /etc/ssh/ssh_host_ed25519_key.pub`, as the admin reads it on the node's
 * console) or the one stored at its install. The LB must already run this
 * panel release (it ships bin/xc_agent/run.sh); update it with the legacy
 * `update` signal first. A failure never marks the live node as failed: it
 * keeps serving the legacy way.
 *
 * Usage: `console.php server:enrol <serverID> <sshPort> --cred-file=<path> [--expect-hostkey=<sha1>]`
 * (the credentials as for server:install: a 0600 file, read and deleted).
 *
 * @package XC_VM_CLI_Commands
 */
class ServerEnrolCommand implements CommandInterface {
	public function getName(): string {
		return 'server:enrol';
	}

	public function getDescription(): string {
		return 'Enrol an existing LB in the cluster API over SSH';
	}

	public function execute(array $rArgs): int {
		if (posix_getpwuid(posix_geteuid())['name'] != 'xc_vm') {
			echo "Please run as XC_VM!\n";
			return 1;
		}
		[$rArgs, $rOptions] = InstallCredentials::splitOptions($rArgs);
		$rServerID = intval($rArgs[0] ?? 0);
		$rPort = intval($rArgs[1] ?? 22);
		$rCred = isset($rOptions['cred-file']) ? InstallCredentials::consume($rOptions['cred-file']) : null;
		if ($rServerID <= 0 || $rCred === null) {
			echo "Usage: server:enrol <serverID> <sshPort> --cred-file=<path> [--expect-hostkey=<sha1>]\n";
			return 1;
		}
		if (empty(SettingsManager::get('cluster_api_enabled'))) {
			echo "The cluster API is disabled (Settings → Cluster). Exiting\n";
			return 1;
		}
		try {
			$rCrypto = ClusterCryptoFactory::create();
		} catch (\Throwable $rE) {
			echo 'Cluster API unavailable: ' . $rE->getMessage() . ". Exiting\n";
			return 1;
		}

		global $db;
		$rServers = ServerRepository::getAll(true);
		$rServer = $rServers[$rServerID] ?? null;
		if ($rServer === null || !empty($rServer['is_main']) || intval($rServer['server_type'] ?? 0) !== 0) {
			echo "Server {$rServerID} is not a load balancer. Exiting\n";
			return 1;
		}

		$rExpected = InstallCredentials::normalizeHostKey($rOptions['expect-hostkey'] ?? '');
		$rStored = $rServer['ssh_hostkey_sha1'] ?? null;
		if (($rExpected === null || $rExpected === '') && ($rStored === null || $rStored === '')) {
			echo "No SSH host key to check against: pass --expect-hostkey with the node's SHA-1 fingerprint\n";
			echo "(on the node: ssh-keygen -l -E sha1 -f /etc/ssh/ssh_host_ed25519_key.pub). Trust on first use is refused for enrolment. Exiting\n";
			return 1;
		}

		set_time_limit(0);
		$rHost = (string) $rServer['server_ip'];
		echo 'Connecting to ' . $rHost . ':' . $rPort . "\n";
		if (!($rConn = @ssh2_connect($rHost, $rPort))) {
			echo "Failed to connect to server. Exiting\n";
			return 1;
		}
		$rPresented = (string) @ssh2_fingerprint($rConn, SSH2_FINGERPRINT_SHA1 | SSH2_FINGERPRINT_HEX);
		$rHostKeyError = InstallCredentials::checkHostKey($rPresented, $rExpected, $rStored);
		if ($rHostKeyError !== null) {
			echo $rHostKeyError . ". Exiting\n";
			return 1;
		}
		if (!@ssh2_auth_password($rConn, $rCred['username'], $rCred['password'])) {
			echo "Failed to authenticate over SSH. Exiting\n";
			return 1;
		}
		$rHostKey = InstallCredentials::normalizeHostKey($rPresented);
		if ($rHostKey !== $rStored) {
			$db->query('UPDATE `servers` SET `ssh_hostkey_sha1` = ? WHERE `id` = ?;', $rHostKey, $rServerID);
		}

		$rRunSSH = static fn($rC, string $rCmd): array => SshChannel::run($rC, $rCmd);
		$rSendFileSSH = static fn($rC, string $rFrom, string $rTo, bool $rWarn = false): bool => SshChannel::send($rC, $rFrom, $rTo, $rWarn);
		$rReady = SshChannel::run($rConn, 'test -f /home/xc_vm/bin/xc_agent/run.sh && test -f /home/xc_vm/config/config.enc && echo READY');
		if (trim($rReady['output']) !== 'READY') {
			echo "The node does not run this panel release yet (no bin/xc_agent/run.sh). Update it first, then retry. Exiting\n";
			return 1;
		}

		$rStart = time();
		if (!LbInstallFlow::provisionCluster($rConn, $rRunSSH, $rSendFileSSH, $rServers, $rServerID, $db, $rCrypto, null, false)) {
			return 1;
		}
		$rNode = NodeRegistry::byServer($rServerID);
		if ($rNode === null || intval($rNode['created_at']) < $rStart) {
			echo "The node was not enrolled (see above); it stays legacy. Exiting\n";
			return 1;
		}
		return 0;
	}
}
