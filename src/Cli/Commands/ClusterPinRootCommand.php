<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Cluster\RootPin;

/**
 * ClusterPinRootCommand — pin the panel key for root commands on a node
 * enrolled by code (the SSH install flow pins it itself). Root compares the
 * key the agent received with the fingerprint the admin reads on MAIN
 * (`panel_fp`, Servers → Cluster Nodes, or `xc_agent health`), so a key
 * planted in the agent's files cannot become root's trust anchor.
 *
 * Usage: `console.php cluster:pin-root <panel_fp>` (root)
 *
 * @package XC_VM_CLI_Commands
 */
class ClusterPinRootCommand implements CommandInterface {
	public function getName(): string {
		return 'cluster:pin-root';
	}

	public function getDescription(): string {
		return "Pin MAIN's panel key for root commands, checked against its fingerprint (root)";
	}

	public function execute(array $rArgs): int {
		if ((posix_getpwuid(posix_geteuid())['name'] ?? null) !== 'root') {
			echo "Please run as root!\n";
			return 1;
		}
		$rFp = strtolower(trim((string) ($rArgs[0] ?? '')));
		$rState = json_decode((string) @file_get_contents(CONFIG_PATH . 'cluster/agent.json'), true);
		$rPub = is_array($rState) ? base64_decode((string) ($rState['panel_sign_pub'] ?? ''), true) : false;
		$rNode = is_array($rState) ? (string) ($rState['node_uuid'] ?? '') : '';
		if (!preg_match('/^[0-9a-f]{64}$/', $rFp)) {
			echo "Usage: cluster:pin-root <panel_fp> (64 hex digits, from MAIN)\n";
			return 1;
		}
		if ($rPub === false || strlen($rPub) !== 32 || $rNode === '') {
			echo "This node is not enrolled (config/cluster/agent.json). Exiting\n";
			return 1;
		}
		if (!hash_equals($rFp, hash('sha256', $rPub))) {
			echo "The agent's panel key does not match that fingerprint: nothing pinned. Exiting\n";
			return 1;
		}
		if (!RootPin::write($rPub, $rNode) || RootPin::read() === null) {
			echo 'Could not write ' . RootPin::dir() . ". Exiting\n";
			return 1;
		}
		@mkdir(RootPin::inbox(), 0700, true);
		@chown(RootPin::inbox(), 'xc_vm');
		echo "OK: root commands for node {$rNode} are checked against panel key {$rFp}\n";
		return 0;
	}
}
