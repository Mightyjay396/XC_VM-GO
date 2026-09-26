<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\ReplicaApply;

/**
 * ClusterApplyCommand — apply the node replica the agent verified (Phase 7).
 * The agent runs it after the replica changed; see ReplicaApply. In shadow
 * (CONFIG off) it only reports how the replica differs from the caches
 * cron:cache builds from MAIN's database; with CONFIG on it writes them.
 *
 * Runs as xc_vm. Usage: `console.php cluster:apply`
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
