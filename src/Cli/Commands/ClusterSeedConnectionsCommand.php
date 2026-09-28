<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Cluster\AgentConnections;
use XcVm\Core\Cluster\StoredConnections;

/**
 * ClusterSeedConnectionsCommand — load this node's viewers from MAIN's store
 * into its agent's registry (cluster plan, Phase 6, "The CONNECTIONS switch
 * on a live node"). Run on the node while it still reaches MAIN's store,
 * before the CONNECTIONS flow is switched on: the agent then holds the viewers
 * already open, and its first heartbeat digest agrees with MAIN's, so no
 * snapshot is needed. Loading sends no event.
 *
 * Only the registry record's keys are sent (AgentConnections::RECORD_KEYS),
 * never the line's other columns.
 *
 * Usage: `console.php cluster:seed-connections`
 *
 * @package XC_VM_CLI_Commands
 */
class ClusterSeedConnectionsCommand implements CommandInterface {
	public function getName(): string {
		return 'cluster:seed-connections';
	}

	public function getDescription(): string {
		return "Load this node's viewers from MAIN's store into its agent (before the CONNECTIONS switch)";
	}

	public function execute(array $rArgs): int {
		if (!defined('SERVER_ID')) {
			echo "SERVER_ID is not defined. Exiting\n";
			return 1;
		}
		try {
			$rRecords = StoredConnections::ofServer((int) SERVER_ID, false); // ended HLS rows too: the registry holds them
		} catch (\Throwable $rE) {
			echo "Cannot read MAIN's store: " . $rE->getMessage() . "\n";
			return 1;
		}
		$rSeeded = AgentConnections::seed($rRecords);
		if ($rSeeded === null) {
			echo "The agent did not answer (config/cluster/agent.sock). Nothing seeded\n";
			return 1;
		}
		echo 'OK: ' . $rSeeded . ' of ' . count($rRecords) . " connections loaded into the agent's registry\n";
		return 0;
	}
}
