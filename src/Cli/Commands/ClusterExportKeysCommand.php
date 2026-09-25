<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Cluster\Crypto\ClusterCrypto;
use XcVm\Core\Cluster\Crypto\ClusterCryptoFactory;
use XcVm\Core\Cluster\Crypto\ClusterRefusedException;
use XcVm\Domain\Cluster\ClusterAudit;
use XcVm\Domain\Server\InstallCredentials;

/**
 * ClusterExportKeysCommand — write a disaster-recovery bundle of MAIN's
 * cluster root (MAIN ↔ LB plan, "Recovery and MAIN replacement").
 *
 * The bundle carries the root, the revocation floors and the clock
 * high-water, under Argon2id (1 GiB, 4 passes) of a passphrase plus a pepper
 * only an extension holds (`xcvm_core`, `cluster_export_keys`): the file alone
 * or the file with the passphrase outside an extension opens nothing. Keep it
 * off this machine. `cluster:import-keys` on a replacement MAIN takes it
 * back; without it, a lost MAIN means a new root and re-enrolling every node.
 *
 * Usage: `console.php cluster:export-keys <file> [--passphrase-file=<path>]`
 * (else the passphrase is asked twice at the terminal). The file is written
 * 0600 and never overwritten. MAIN only (stripped from LB builds).
 *
 * @package XC_VM_CLI_Commands
 */
class ClusterExportKeysCommand implements CommandInterface {
	public function __construct(private ?ClusterCrypto $rCrypto = null, private $rIn = null) {
	}

	public function getName(): string {
		return 'cluster:export-keys';
	}

	public function getDescription(): string {
		return 'Write a disaster-recovery bundle of the cluster root (passphrase-protected)';
	}

	public function execute(array $rArgs): int {
		[$rArgs, $rOptions] = InstallCredentials::splitOptions($rArgs);
		$rFile = (string) ($rArgs[0] ?? '');
		if ($rFile === '') {
			echo "Usage: cluster:export-keys <file> [--passphrase-file=<path>]\n";
			return 1;
		}
		if (file_exists($rFile)) {
			echo "{$rFile} exists; choose a new file. Exiting\n";
			return 1;
		}
		try {
			$rCrypto = $this->rCrypto ?? ClusterCryptoFactory::create();
		} catch (\Throwable $rE) {
			echo 'Cluster API unavailable: ' . $rE->getMessage() . "\n";
			return 1;
		}
		$rPass = ClusterPassphrase::read($rOptions, true, $this->rIn);
		if ($rPass === null) {
			echo "No passphrase, or the two entries differ. Exiting\n";
			return 1;
		}
		echo "Deriving the key (about a gigabyte of memory, a few seconds)...\n";
		try {
			$rBundle = $rCrypto->exportKeys($rPass);
		} catch (ClusterRefusedException $rE) {
			echo match (true) {
				str_starts_with($rE->reason(), 'ARG:passphrase') => 'Passphrase too weak: use 20+ characters, or 12+ with three character classes (and 8+ distinct characters).',
				$rE->reason() === 'BUSY' => 'Another export or import is running on this machine.',
				default => 'Export refused: ' . $rE->reason(),
			} . "\n";
			return 1;
		}
		$rFh = @fopen($rFile, 'x');
		if ($rFh === false) {
			echo "Cannot create {$rFile}. Exiting\n";
			return 1;
		}
		@chmod($rFile, 0600);
		$rOk = fwrite($rFh, $rBundle) === strlen($rBundle);
		fclose($rFh);
		if (!$rOk) {
			@unlink($rFile);
			echo "Writing {$rFile} failed. Exiting\n";
			return 1;
		}
		$rFp = bin2hex((string) ($rCrypto->info()['panel_fp'] ?? ''));
		ClusterAudit::log('cluster.export_keys', null, ['panel_fp' => $rFp, 'bytes' => strlen($rBundle)], 'cli');
		echo "Wrote {$rFile} (" . strlen($rBundle) . " bytes, panel fingerprint {$rFp}).\n";
		echo "Keep it and the passphrase apart, and both off this machine.\n";
		return 0;
	}
}
