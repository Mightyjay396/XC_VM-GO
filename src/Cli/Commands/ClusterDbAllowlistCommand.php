<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\DbAllowlist;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * ClusterDbAllowlistCommand — the opt-in 3306/6379 firewall allowlist on MAIN.
 *
 * - `status`: the setting, who would be allowed, whether the live chain matches,
 *   and the established MariaDB/Redis connections the allowlist would cut. Run
 *   it before turning the allowlist on.
 * - `apply`: turn the setting on and apply now (the root cron keeps it applied).
 * - `undo`: turn the setting off and remove the chain now.
 *
 * Root only (iptables). MAIN only (stripped from LB builds).
 *
 * @package XC_VM_CLI_Commands
 */
class ClusterDbAllowlistCommand implements CommandInterface {
	use DatabaseAware;

	public function __construct(private ?DbAllowlist $rAllowlist = null, private bool $rCheckRoot = true) {
	}

	public function getName(): string {
		return 'cluster:db-allowlist';
	}

	public function getDescription(): string {
		return 'Firewall MariaDB/Redis on MAIN to cluster nodes: status | apply | undo';
	}

	public function execute(array $rArgs): int {
		$rAction = (string) ($rArgs[0] ?? 'status');
		if (!in_array($rAction, ['status', 'apply', 'undo'], true)) {
			echo "Usage: cluster:db-allowlist [status|apply|undo]\n";
			return 1;
		}
		if ($this->rCheckRoot && (posix_getpwuid(posix_geteuid())['name'] ?? null) !== 'root') {
			echo "Run as root (iptables). Exiting\n";
			return 1;
		}
		$rAllowlist = $this->rAllowlist ?? new DbAllowlist();
		$db = self::db();
		if (!$db->query('SELECT `cluster_db_allowlist` FROM `settings` LIMIT 1;')) {
			echo "The settings table has no cluster_db_allowlist column yet: run `console.php status` to migrate. Exiting\n";
			return 1;
		}
		$rOn = !empty($db->get_row()['cluster_db_allowlist']);

		if ($rAction === 'status') {
			return $this->status($rAllowlist, $rOn);
		}

		$db->query('UPDATE `settings` SET `cluster_db_allowlist` = ?;', $rAction === 'apply' ? 1 : 0);
		if (defined('CACHE_TMP_PATH')) {
			SettingsManager::clearCache();
		}
		$rResult = $rAllowlist->sync('cli');
		if ($rResult === null) {
			echo "Could not read the servers table; the firewall was left as it is.\n";
			return 1;
		}
		echo ($rAction === 'apply' ? 'Allowlist on' : 'Allowlist off') . ': IPv4 ' . $rResult[4] . ', IPv6 ' . $rResult[6] . "\n";
		return str_starts_with($rResult[4], 'error') || str_starts_with($rResult[6], 'error') ? 1 : 0;
	}

	private function status(DbAllowlist $rAllowlist, bool $rOn): int {
		$rWanted = $rAllowlist->wanted();
		if ($rWanted === null) {
			echo "Could not read the servers table.\n";
			return 1;
		}
		echo 'Setting: ' . ($rOn ? 'on' : 'off') . "\n";
		echo 'Allowed besides loopback (' . (count($rWanted[4]) + count($rWanted[6])) . "):\n";
		foreach (array_merge($rWanted[4], $rWanted[6]) as $rCidr) {
			echo '  ' . $rCidr . "\n";
		}
		foreach ($rAllowlist->live() as $rFamily => $rLive) {
			$rLabel = $rFamily === 4 ? 'IPv4' : 'IPv6';
			if ($rLive === null) {
				echo $rLabel . ' chain: ' . ($rOn ? 'MISSING (the root cron applies it within a minute)' : 'absent') . "\n";
			} else {
				echo $rLabel . ' chain: ' . ($rLive === DbAllowlist::chainLines($rWanted[$rFamily]) ? 'in sync' : ($rOn ? 'OUT OF DATE' : 'present (removed within a minute)')) . "\n";
			}
		}
		$rRefused = $rAllowlist->refusedPeers($rWanted);
		if ($rRefused === []) {
			echo "No established connection on 3306/6379 comes from outside the allowlist.\n";
		} else {
			echo 'Established connections the allowlist ' . ($rOn ? 'refuses' : 'would cut') . ' (add them to the extra list if they are yours):' . "\n";
			foreach ($rRefused as $rPeer) {
				echo '  ' . $rPeer . "\n";
			}
		}
		return 0;
	}
}
