<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Domain\Cluster\ClusterPool;

/**
 * ClusterPoolsCommand — bring MAIN's cluster API FPM pools to their current
 * size and say whether both answer (ClusterPool::ensure()).
 *
 * `status` runs it as xc_vm at boot and after an update, and cron:servers
 * does the same each minute. It never runs as root: the pools' files live in
 * directories xc_vm owns, and root would follow a link planted there.
 *
 * Usage: `sudo -u xc_vm console.php cluster:pools [wait seconds, default 10]`
 *
 * Exit code 0 when both pools answer, 1 otherwise.
 *
 * @package XC_VM_CLI_Commands
 */
class ClusterPoolsCommand implements CommandInterface {
	public function getName(): string {
		return 'cluster:pools';
	}

	public function getDescription(): string {
		return "Start or resize MAIN's cluster API FPM pools, and say whether they answer";
	}

	public function execute(array $rArgs): int {
		$rUser = posix_getpwuid(posix_geteuid());
		if (!is_array($rUser) || $rUser['name'] !== 'xc_vm') {
			echo "Please run as XC_VM!\n";
			return 1;
		}
		$rWait = isset($rArgs[0]) && is_numeric($rArgs[0]) ? max(0.0, (float) $rArgs[0]) : 10.0;
		if (ClusterPool::ensure($rWait)) {
			echo "Cluster API pools are ready.\n";
			return 0;
		}
		echo "Cluster API pools are not answering yet.\n";
		return 1;
	}
}
