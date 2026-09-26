<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Cluster\Crypto\ClusterCrypto;
use XcVm\Core\Cluster\Crypto\ClusterCryptoFactory;
use XcVm\Core\Cluster\Crypto\ClusterRefusedException;
use XcVm\Domain\Cluster\ClusterAudit;
use XcVm\Domain\Cluster\ClusterMeta;
use XcVm\Domain\Server\InstallCredentials;

/**
 * ClusterImportKeysCommand — a replacement MAIN takes over the cluster root
 * from a `cluster:export-keys` bundle, so enrolled nodes keep trusting it
 * (MAIN ↔ LB plan, "Recovery and MAIN replacement").
 *
 * The extension seals the root to this machine and merges the revocation
 * floors and clock high-water (never lowered). It refuses when this machine
 * already has a different root (`ROOT_EXISTS`); the same root again is a
 * no-op. Then the panel keys are recorded as by `cluster:init`.
 *
 * Retire the old MAIN first: two MAINs with one root mint tokens under
 * diverging revocation floors. Tokens issued by the old MAIN do not open
 * here (their epoch records were sealed to its machine); agents recover by
 * themselves with `token_rekey`.
 *
 * Usage: `console.php cluster:import-keys <file> [--passphrase-file=<path>]`.
 * MAIN only (stripped from LB builds).
 *
 * @package XC_VM_CLI_Commands
 */
class ClusterImportKeysCommand implements CommandInterface {
	public function __construct(private ?ClusterCrypto $rCrypto = null, private $rIn = null) {
	}

	public function getName(): string {
		return 'cluster:import-keys';
	}

	public function getDescription(): string {
		return 'Take over the cluster root from a disaster-recovery bundle';
	}

	public function execute(array $rArgs): int {
		[$rArgs, $rOptions] = InstallCredentials::splitOptions($rArgs);
		$rFile = $rArgs[0] ?? '';
		$rBundle = $rFile === '' ? false : @file_get_contents($rFile);
		if ($rBundle === false || $rBundle === '') {
			echo "Usage: cluster:import-keys <file> [--passphrase-file=<path>]\n";
			return 1;
		}
		try {
			$rCrypto = $this->rCrypto ?? ClusterCryptoFactory::create();
		} catch (\Throwable $rE) {
			echo 'Cluster API unavailable: ' . $rE->getMessage() . "\n";
			return 1;
		}
		$rPass = ClusterPassphrase::read($rOptions, false, $this->rIn);
		if ($rPass === null) {
			echo "No passphrase. Exiting\n";
			return 1;
		}
		try {
			$rOut = $rCrypto->importKeys($rBundle, $rPass);
		} catch (ClusterRefusedException $rE) {
			echo match (true) {
				$rE->reason() === 'ROOT_EXISTS' => 'This machine already has a different cluster root. Importing would split the cluster; nothing was changed.',
				$rE->reason() === 'CRYPTO' => 'The bundle does not open with this passphrase (or it was changed).',
				str_starts_with($rE->reason(), 'RECORD:clock') => "The bundle's clock is ahead of this machine's: fix the time here, then retry.",
				$rE->reason() === 'BUSY' => 'Another export or import is running on this machine.',
				default => 'Import refused: ' . $rE->reason(),
			} . "\n";
			return 1;
		}
		$rFp = bin2hex((string) $rOut['panel_fp']);
		ClusterMeta::init($rCrypto);
		ClusterAudit::log('cluster.import_keys', null, ['panel_fp' => $rFp, 'created' => (bool) $rOut['created'], 'nodes' => (int) $rOut['nodes'], 'exported_at' => (int) $rOut['exported_at']], 'cli');
		echo ($rOut['created'] ? 'Imported' : 'Already imported') . " the cluster root (panel fingerprint {$rFp}; " . (int) $rOut['nodes'] . ' revocation floors; exported ' . gmdate('Y-m-d H:i', (int) $rOut['exported_at']) . " UTC).\n";
		echo "Make sure the old MAIN is retired. Nodes re-key by themselves (token_rekey) once they reach this MAIN.\n";
		return 0;
	}
}
