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
 * agent logs it like any failed run; 2 when there is no replica.
 *
 * It boots from the replica in every mode (ReplicaStage via
 * ReplicaBoot::forArgv): no connection to MAIN's database at boot, and none
 * at all once CONFIG is on, so a node rebooted while MAIN is unreachable
 * still builds its caches. In shadow the comparison with MAIN's crontab and
 * RTMP publishers still reads MAIN's database, on first use.
 *
 * Without `--from-disk` it applies the `.json` files the agent wrote after
 * verifying the records it just stored. With `--from-disk` (`service` at
 * boot, when the agent may not run yet) it verifies the records on disk
 * itself, with the agent's keys, and applies what they hold (ReplicaRecords).
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
		$rReport = ReplicaApply::run(NodeFlows::on(NodeFlows::CONFIG), null, null, in_array('--from-disk', $rArgs, true));
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
