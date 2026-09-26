<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\ReplicaApply;

/**
 * ClusterApplyCommand — apply the node replica the agent verified (Phase 7).
 * The agent runs it after the replica changed, and `service` at boot on a
 * CONFIG node before the daemons start; see ReplicaApply. In shadow (CONFIG
 * off) it only reports how the replica differs from what the node reads from
 * MAIN's database today; with CONFIG on it writes the caches, the settings
 * cache among them, and config/openssl_extra. Its output (the report) never
 * holds a secret: the agent may log it. It exits 3, after printing the
 * report, when a part of it failed (a write of config/openssl_extra), so the
 * agent logs it like any failed run; 2 when there is no replica. It still boots
 * through the CLI profile, which needs MAIN's database: serving from the
 * replica after a reboot while MAIN is unreachable waits for ReplicaStage.
 *
 * It always reads the replica on disk (`--from-disk`, the plan's boot flag,
 * is accepted and changes nothing): PHP holds no key to fetch or open it.
 *
 * Runs as xc_vm. Usage: `console.php cluster:apply [--from-disk]`
 *
 * @package XC_VM_CLI_Commands
 */
class ClusterApplyCommand implements CommandInterface {
	/** The exit code when a part of the report says `failed`. */
	public const EXIT_FAILED = 3;

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
		return self::failed($rReport) ? self::EXIT_FAILED : 0;
	}

	/** @param array<string, mixed> $rReport */
	public static function failed(array $rReport): bool {
		foreach ($rReport as $rPart) {
			if (is_array($rPart) && ($rPart['mode'] ?? null) === 'failed') {
				return true;
			}
		}
		return false;
	}
}
