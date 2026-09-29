<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Domain\Cluster\CredentialRotation;

/**
 * ClusterRotateCredentialsCommand — rotate MAIN's Redis password and push it
 * to the load balancers that still use it (plan, section 10, step 3).
 *
 * - `redis`: a new Redis password beside the old one, MAIN on the new one at
 *   once, and every node below mode 2 told (CredentialRotation::redis()).
 * - `redis --finish [--force]`: drop the old password once every node reached
 *   by command has acked; `--force` drops it regardless (the nodes that have
 *   not moved lose Redis).
 * - `db`: moved to `cluster:rotate-db-password` (DbPassword); it says so.
 * - `status`: the open Redis rotation, node by node.
 *
 * MAIN only (stripped from LB builds). Root, for redis.conf.
 *
 * @package XC_VM_CLI_Commands
 */
class ClusterRotateCredentialsCommand implements CommandInterface {
	public function __construct(private ?CredentialRotation $rRotation = null) {
	}

	public function getName(): string {
		return 'cluster:rotate-credentials';
	}

	public function getDescription(): string {
		return 'Rotate the Redis password for the nodes that still use it: redis [--finish] [--force] | status';
	}

	public function execute(array $rArgs): int {
		$rWhat = (string) ($rArgs[0] ?? 'status');
		$rForce = in_array('--force', $rArgs, true);
		$rRotation = $this->rRotation ?? new CredentialRotation();
		try {
			switch ($rWhat) {
				case 'status':
					return $this->status();
				case 'redis':
					if (in_array('--finish', $rArgs, true)) {
						$rPending = $rRotation->finishRedis($rForce);
						if ($rPending !== []) {
							echo 'Refused: server(s) ' . implode(', ', $rPending) . " have not confirmed the new Redis password (see `status`). --force drops the old one regardless.\n";
							return 1;
						}
						echo "The old Redis password is gone.\n";
						return 0;
					}
					$rJob = $rRotation->redis();
					echo 'Redis rotation ' . $rJob['id'] . " started; MAIN uses the new password, the old one still works.\n";
					foreach ($rJob['nodes'] as $rServerID => $rNode) {
						echo '  server ' . $rServerID . ': ' . $rNode['via'] . (isset($rNode['detail']) ? ' (' . $rNode['detail'] . ')' : '') . "\n";
					}
					echo "When `status` shows every node acked, run `cluster:rotate-credentials redis --finish`.\n";
					return 0;
				case 'db':
					echo "The DB password is rotated by `cluster:rotate-db-password`.\n";
					return 1;
			}
		} catch (\Throwable $rE) {
			echo 'Failed: ' . $rE->getMessage() . "\n";
			return 1;
		}
		echo "Usage: cluster:rotate-credentials [status | redis [--finish] [--force]]\n";
		return 1;
	}

	private function status(): int {
		$rStatus = CredentialRotation::redisStatus();
		if ($rStatus === null) {
			echo "No Redis rotation is open.\n";
			return 0;
		}
		echo 'Redis rotation ' . $rStatus['id'] . ' open since ' . gmdate('Y-m-d H:i:s', $rStatus['started_at']) . " UTC:\n";
		foreach ($rStatus['nodes'] as $rServerID => $rNode) {
			echo '  server ' . $rServerID . ': ' . $rNode['state'] . ' (' . $rNode['via'] . ')' . ($rNode['detail'] !== '' ? ' ' . $rNode['detail'] : '') . "\n";
		}
		return 0;
	}
}
