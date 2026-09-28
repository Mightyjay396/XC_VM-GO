<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Cluster\Crypto\ClusterCrypto;
use XcVm\Core\Cluster\Crypto\ClusterCryptoFactory;
use XcVm\Domain\Cluster\ClusterMeta;
use XcVm\Domain\Cluster\StreamSecretRotation;

/**
 * ClusterRotateStreamSecretCommand — replace the viewer-token secret and
 * re-encrypt what is stored under it (plan, section 10, step 4).
 *
 * - `console.php cluster:rotate-stream-secret` starts a rotation, or finishes
 *   the one a previous run left (StreamSecretRotation keeps its progress in
 *   `cluster_meta`, so running it again is how a cut run is resumed).
 * - `--status` prints the rotation in progress and the last one's time.
 * - `--force` rotates although enrolled nodes still have their DATAPLANE flow
 *   off. The rotation exists to take a leaked secret off the wire, and those
 *   nodes' relays and file pulls still carry it in their URLs, so the new
 *   value would leak the same way: the command refuses without it.
 *
 * MAIN only (stripped from LB builds), as xc_vm.
 *
 * @package XC_VM_CLI_Commands
 */
class ClusterRotateStreamSecretCommand implements CommandInterface {
	/**
	 * @param (callable(): ?ClusterCrypto)|null $rCrypto the extension handle for config.changed; null asks the factory
	 */
	public function __construct(private ?StreamSecretRotation $rRotation = null, private $rCrypto = null) {
	}

	public function getName(): string {
		return 'cluster:rotate-stream-secret';
	}

	public function getDescription(): string {
		return 'Rotate live_streaming_pass and re-encrypt hmac_keys and the image cache (resumable): [--status] [--force]';
	}

	public function execute(array $rArgs): int {
		if (in_array('--status', $rArgs, true)) {
			$rJob = StreamSecretRotation::inProgress();
			echo $rJob === null ? "No rotation in progress.\n" : 'Rotation ' . $rJob['id'] . ' in progress since ' . gmdate('Y-m-d H:i:s', $rJob['started_at']) . ' UTC, at its ' . $rJob['phase'] . ' step (' . $rJob['hmac'] . ' HMAC key(s), ' . $rJob['images'] . " image(s) so far).\n";
			$rLast = ClusterMeta::get(StreamSecretRotation::DONE_META);
			echo $rLast === null ? "Never rotated by this command.\n" : 'Last rotation ended ' . gmdate('Y-m-d H:i:s', (int) $rLast) . " UTC.\n";
			return 0;
		}
		$rRotation = $this->rRotation ?? new StreamSecretRotation(static function (string $rLine): void {
			echo $rLine . "\n";
		});
		if (StreamSecretRotation::inProgress() === null) {
			$rBlockers = StreamSecretRotation::blockers();
			if ($rBlockers !== []) {
				$rList = implode(', ', $rBlockers);
				if (!in_array('--force', $rArgs, true)) {
					echo 'Refused: the DATAPLANE flow is off on server(s) ' . $rList . ". Their relays and file pulls carry the secret in URLs, so a new value would be exposed the same way.\n";
					echo "Switch DATAPLANE on across the fleet first, or pass --force to rotate anyway.\n";
					return 1;
				}
				echo 'WARNING: rotating with the DATAPLANE flow off on server(s) ' . $rList . ": the new secret travels in their URLs.\n";
			}
		}
		try {
			$rOut = $rRotation->run(null, $this->crypto());
		} catch (\Throwable $rE) {
			echo 'The rotation stopped: ' . $rE->getMessage() . "\nRun the command again to resume it.\n";
			return 1;
		}
		echo ($rOut['resumed'] ? 'Rotation resumed and finished' : 'Rotation finished') . ': ' . $rOut['hmac'] . ' HMAC key(s) and ' . $rOut['images'] . ' image(s) re-encrypted, ' . $rOut['notified'] . " node(s) told at once.\n";
		return 0;
	}

	private function crypto(): ?ClusterCrypto {
		if ($this->rCrypto !== null) {
			return ($this->rCrypto)();
		}
		try {
			return ClusterCryptoFactory::create();
		} catch (\Throwable) {
			return null; // nodes read the secrets section within a minute regardless
		}
	}
}
