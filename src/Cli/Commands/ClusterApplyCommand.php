<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\ReplicaApply;

/**
 * ClusterApplyCommand — apply the node replica the agent verified (Phase 7).
 * The agent runs it after the replica changed, and `service` at boot on a
 * CONFIG node before the daemons start; see ReplicaApply. In shadow (CONFIG
 * off) it only reports how the replica differs from what the node reads from
 * MAIN's database today; with CONFIG on it writes the caches. It still boots
 * through the CLI profile, which needs MAIN's database: serving from the
 * replica after a reboot while MAIN is unreachable waits for ReplicaStage.
 *
 * It always reads the replica on disk (`--from-disk`, the plan's boot flag,
 * is accepted and changes nothing): PHP holds no key to fetch or open it.
 *
 * Runs as xc_vm. Usage: `console.php cluster:apply [--from-disk]`
 *
 * @package XC_VM_CLI_Commands
 */
class ClusterApplyCommand implements CommandInterface {
	public function getName(): string {
		return 'cluster:apply';
	}

	public function getDescription(): string {
		return 'Apply the node replica from xc_agent (shadow diff until the CONFIG flow is on)';
	}

	public function execute(array $rArgs): int {
		$rReport = ReplicaApply::run(NodeFlows::on(NodeFlows::CONFIG));
		if ($rReport === null) {
			fwrite(STDERR, "cluster:apply: no replica to apply\n");
			return 2;
		}
		echo json_encode($rReport) . "\n";
		return 0;
	}
}
