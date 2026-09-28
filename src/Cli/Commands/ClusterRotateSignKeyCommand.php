<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Cluster\NodeActions;
use XcVm\Core\Cluster\RootPin;
use XcVm\Domain\Cluster\ClusterAudit;
use XcVm\Domain\Cluster\ClusterMeta;
use XcVm\Domain\Cluster\CommandBus;
use XcVm\Domain\Cluster\NodeRegistry;

/**
 * ClusterRotateSignKeyCommand — hand the fleet's root trust to another panel
 * signing key without SSH (`node.root rotate_sign_key`, ADR 0004, Phase 4
 * seventh increment and Phase 9).
 *
 * Run on the MAIN the nodes trust now, with the new key read on the MAIN
 * that replaces it (`cluster:init` prints its fingerprint; its
 * `panel_sign_pub` is the key): each node's root re-pins it (RootPin::rotate)
 * under a command signed with the key it pins today. From then on the node's
 * root obeys the new MAIN's commands only, and reports root_ready false
 * until its agent holds the new key as well (an enrolment by code, or
 * cluster:reenrol), so this MAIN sends it no further root command.
 *
 * `console.php cluster:rotate-sign-key --pub=<64 hex> [--server=<id>] --yes`
 *
 * Only nodes that take root commands can be reached; the others are listed,
 * for `cluster:pin-root` on their console or a reinstall. MAIN only.
 *
 * @package XC_VM_CLI_Commands
 */
class ClusterRotateSignKeyCommand implements CommandInterface {
	public function getName(): string {
		return 'cluster:rotate-sign-key';
	}

	public function getDescription(): string {
		return 'Re-pin the nodes\' root trust to another panel signing key: --pub=<hex> [--server=<id>] --yes';
	}

	public function execute(array $rArgs): int {
		$rPub = null;
		$rOnly = null;
		foreach ($rArgs as $rArg) {
			if (str_starts_with($rArg, '--pub=')) {
				$rPub = strtolower(substr($rArg, 6));
			} elseif (str_starts_with($rArg, '--server=')) {
				$rOnly = (int) substr($rArg, 9);
			}
		}
		if ($rPub === null || !preg_match('/^[0-9a-f]{64}$/', $rPub)) {
			echo "Usage: cluster:rotate-sign-key --pub=<the new MAIN's panel_sign_pub, 64 hex> [--server=<id>] --yes\n";
			return 1;
		}
		$rCurrent = base64_decode((string) ClusterMeta::get('panel_sign_pub'), true);
		if ($rCurrent !== false && hash_equals(bin2hex($rCurrent), $rPub)) {
			echo "That is this MAIN's own key: nothing to rotate.\n";
			return 1;
		}
		echo 'New key fingerprint: ' . RootPin::fingerprint((string) hex2bin($rPub)) . "\n";
		if (!in_array('--yes', $rArgs, true)) {
			echo "Compare it with the new MAIN's, then run again with --yes. The nodes' root will obey that MAIN's commands only.\n";
			return 1;
		}
		$rSent = $rSkipped = [];
		foreach (NodeRegistry::enrolled() as $rServerID => $rNode) {
			if ($rOnly !== null && $rServerID !== $rOnly) {
				continue;
			}
			if (!CommandBus::acceptsRoot($rNode)) {
				$rSkipped[] = $rServerID;
				continue;
			}
			try {
				$rOk = NodeActions::send($rServerID, ['action' => 'rotate_sign_key', 'new_pub' => $rPub]);
			} catch (\Throwable) {
				$rOk = false;
			}
			if ($rOk) {
				$rSent[] = $rServerID;
			} else {
				$rSkipped[] = $rServerID;
			}
		}
		ClusterAudit::log('cluster.rotate_sign_key', null, ['new_fp' => RootPin::fingerprint((string) hex2bin($rPub)), 'sent' => $rSent, 'skipped' => $rSkipped], 'cli');
		echo 'Queued for server(s): ' . ($rSent === [] ? 'none' : implode(', ', $rSent)) . "\n";
		if ($rSkipped !== []) {
			echo 'Not reached (no root commands, or not queued): ' . implode(', ', $rSkipped) . " — run cluster:pin-root on their console.\n";
		}
		return $rSent === [] && $rSkipped !== [] ? 1 : 0;
	}
}
