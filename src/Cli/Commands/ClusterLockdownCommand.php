<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Domain\Cluster\ClusterLockdown;
use XcVm\Domain\Cluster\LockdownRefused;

/**
 * ClusterLockdownCommand — close MAIN's MariaDB and Redis to the network, the
 * last step of the cutover (plan, section 10, step 5), or `--undo` it.
 *
 * - `console.php cluster:lockdown [--force] [--restart]`
 * - `console.php cluster:lockdown --undo [--restart]`
 * - `console.php cluster:lockdown --status`
 *
 * Manual only: nothing in the panel runs it. It refuses while a load balancer
 * is below mode 2 or a proxy may still hold a DB grant, unless `--force`
 * (ClusterLockdown). The firewall acts at once; the loopback binds when
 * MariaDB and Redis restart (`--restart` restarts MariaDB). Undo it before any
 * rollback that needs a node back on MAIN's database (plan, section 12).
 *
 * Root only. MAIN only (stripped from LB builds).
 *
 * @package XC_VM_CLI_Commands
 */
class ClusterLockdownCommand implements CommandInterface {
	public function __construct(private ?ClusterLockdown $rLockdown = null, private bool $rCheckRoot = true) {
	}

	public function getName(): string {
		return 'cluster:lockdown';
	}

	public function getDescription(): string {
		return 'Close MAIN\'s MariaDB/Redis to the network (manual, last cutover step): [--force] [--restart] | --undo | --status';
	}

	public function execute(array $rArgs): int {
		if (in_array('--status', $rArgs, true)) {
			$rState = ClusterLockdown::state();
			echo $rState === null ? "Not locked down.\n" : 'Locked down since ' . gmdate('Y-m-d H:i:s', (int) ($rState['at'] ?? 0)) . ' UTC' . (empty($rState['loopback']) ? ' (firewall only: the extra allowlist is set)' : '') . ".\n";
			$rBlockers = ClusterLockdown::blockers();
			echo 'Below mode 2: ' . ($rBlockers['nodes'] === [] ? 'none' : implode(', ', $rBlockers['nodes'])) . '; proxies: ' . ($rBlockers['proxies'] === [] ? 'none' : implode(', ', $rBlockers['proxies'])) . "\n";
			return 0;
		}
		if ($this->rCheckRoot && (posix_getpwuid(posix_geteuid())['name'] ?? null) !== 'root') {
			echo "Run as root (iptables, the MariaDB configuration). Exiting\n";
			return 1;
		}
		$rLockdown = $this->rLockdown ?? new ClusterLockdown();
		$rRestart = in_array('--restart', $rArgs, true);
		try {
			$rLines = in_array('--undo', $rArgs, true) ? $rLockdown->undo($rRestart) : $rLockdown->apply(in_array('--force', $rArgs, true), $rRestart);
		} catch (LockdownRefused $rE) {
			if ($rE->rBlockers['nodes'] !== []) {
				echo 'Refused: load balancer(s) ' . implode(', ', $rE->rBlockers['nodes']) . " are below mode 2 and still use MAIN's database or Redis.\n";
			}
			if ($rE->rBlockers['proxies'] !== []) {
				echo 'Refused: proxy server(s) ' . implode(', ', $rE->rBlockers['proxies']) . " may still hold a database grant (which ones do is not recorded).\n";
			}
			echo "--force locks down regardless and cuts them off.\n";
			return 1;
		} catch (\Throwable $rE) {
			echo 'Failed: ' . $rE->getMessage() . "\n";
			return 1;
		}
		foreach ($rLines as $rLine) {
			echo $rLine . "\n";
		}
		if (!in_array('--undo', $rArgs, true)) {
			// The plan's lockdown ends with a last rotation of each; they stay
			// separate commands, each run by the operator.
			echo "Next, rotate once more: cluster:rotate-credentials redis (then --finish), cluster:rotate-credentials db, cluster:rotate-stream-secret.\n";
		}
		return 0;
	}
}
