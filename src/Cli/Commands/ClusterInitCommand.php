<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Cluster\Crypto\ClusterCryptoFactory;
use XcVm\Domain\Cluster\ClusterMeta;

/**
 * ClusterInitCommand — create MAIN's cluster root in `xcvm_core` (idempotent)
 * and record the panel keys in `cluster_meta`.
 *
 * Enabling the cluster API in Settings does the same from php-fpm; this is the
 * CLI path (install, recovery). The extension hands files root writes to the
 * owner of its config directory. MAIN only: the LB build strips it.
 *
 * Usage: `console.php cluster:init`
 *
 * @package XC_VM_CLI_Commands
 */
class ClusterInitCommand implements CommandInterface {
	public function getName(): string {
		return 'cluster:init';
	}

	public function getDescription(): string {
		return 'Create the cluster API root (xcvm_core) and record the panel keys';
	}

	public function execute(array $rArgs): int {
		try {
			$rCrypto = ClusterCryptoFactory::create();
		} catch (\Throwable $rE) {
			echo 'Cluster API unavailable: ' . $rE->getMessage() . "\n";
			return 1;
		}
		try {
			$rOut = ClusterMeta::init($rCrypto);
		} catch (\Throwable $rE) {
			echo 'cluster:init failed: ' . $rE->getMessage() . "\n";
			return 1;
		}
		echo ($rOut['created'] ? 'Created' : 'Already initialised') . '; panel fingerprint ' . $rOut['panel_fp'] . "\n";
		return 0;
	}
}
