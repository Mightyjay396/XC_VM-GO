<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Domain\Cluster\ClusterNginxConfig;

/**
 * ClusterNginxCommand — render MAIN's nginx config for the cluster API
 * (ClusterNginxConfig::apply()): the /cluster/v1/ location, the
 * `cluster_api_port` server and the old ports kept after a port change.
 * What changed is kept only once `nginx -t` passes, then nginx reloads.
 *
 * `status` runs it as xc_vm at boot and after an update; the root set_port
 * handler runs it with --no-reload before its own reload. It never runs as
 * root (ClusterNginxConfig::apply() refuses any user but xc_vm): the files
 * live in a directory xc_vm owns, and root would follow a link planted there.
 *
 * Usage: `sudo -u xc_vm console.php cluster:nginx [--no-reload]`
 *
 * Exit code 0 when the config is current, 1 when it was refused (not run as
 * xc_vm, nginx refused it or did not serve a new port, or it could not be
 * written); the files are then as they were.
 *
 * @package XC_VM_CLI_Commands
 */
class ClusterNginxCommand implements CommandInterface {
	public function getName(): string {
		return 'cluster:nginx';
	}

	public function getDescription(): string {
		return "Render MAIN's cluster API nginx config, keep it once nginx -t passes, reload";
	}

	public function execute(array $rArgs): int {
		$rResult = ClusterNginxConfig::apply(null, !in_array('--no-reload', $rArgs, true));
		if (!$rResult['ok']) {
			echo "Cluster API nginx config refused; the files are as they were:\n" . $rResult['error'] . "\n";
			return 1;
		}
		if (!$rResult['changed']) {
			echo "Cluster API nginx config is current.\n";
		} elseif ($rResult['reloaded']) {
			echo "Cluster API nginx config updated; nginx reloaded.\n";
		} else {
			echo "Cluster API nginx config updated; nginx not reloaded.\n";
		}
		return 0;
	}
}
