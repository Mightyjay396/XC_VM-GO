<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Domain\Cluster\CredentialRotation;

/**
 * ClusterRotateCredentialsCommand — rotate MAIN's Redis or DB password and
 * push it to the load balancers that still use it (plan, section 10, step 3).
 *
 * - `redis`: a new Redis password beside the old one, MAIN on the new one at
 *   once, and every node below mode 2 told (CredentialRotation::redis()).
 * - `redis --finish [--force]`: drop the old password once every node reached
 *   by command has acked; `--force` drops it regardless (the nodes that have
 *   not moved lose Redis).
 * - `db [--force]`: the DB password, through xcvm_core (rotateDb()); refused while a node
 *   below mode 2 takes no root command, unless `--force`.
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
		return 'Rotate the Redis or DB password for the nodes that still use them: redis [--finish] [--force] | db [--force] | status';
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
					if (!$rRotation->dbSupported()) {
						echo 'Refused: this xcvm_core cannot change the DB password (it needs XC_VM::' . CredentialRotation::DB_ROTATOR . "()). Nothing was changed.\n";
						return 1;
					}
					$rOut = $rRotation->rotateDb($rForce);
					if ($rOut['nodes'] === [] && $rOut['blockers'] !== []) {
						echo 'Refused: server(s) ' . implode(', ', $rOut['blockers']) . " take no root command, so they could not be given the new password and would lose MAIN's database. --force rotates regardless.\n";
						return 1;
					}
					echo "The DB password changed on MAIN.\n";
					foreach ($rOut['nodes'] as $rServerID => $rState) {
						echo '  server ' . $rServerID . ': ' . $rState . "\n";
					}
					return 0;
			}
		} catch (\Throwable $rE) {
			echo 'Failed: ' . $rE->getMessage() . "\n";
			return 1;
		}
		echo "Usage: cluster:rotate-credentials [status | redis [--finish] [--force] | db [--force]]\n";
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
