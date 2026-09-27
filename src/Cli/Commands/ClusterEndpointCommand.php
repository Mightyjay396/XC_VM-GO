<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ClusterEndpoint;

/**
 * ClusterEndpointCommand — MAIN's old endpoints the nodes' policy still
 * lists after an endpoint change (ClusterEndpoint): old ports and URLs,
 * last in `policy.main_urls`, for up to 7 days.
 *
 * - `list` (the default): the kept ports and URLs, with their expiry.
 * - `drop <url|host>`: drop a kept URL now, or every kept URL of a host (an
 *   old DNS name or address). Run it when MAIN gives up the old name or
 *   address: whoever holds it next answers the nodes that dial it, which can
 *   keep today's agent from MAIN for up to 10 minutes after each outage. The
 *   policy version goes up, so every node refetches the policy within a
 *   heartbeat; nginx closes an old HTTPS port it served only for a dropped
 *   URL at the next cron:cluster pass, within a minute.
 *
 * Usage: `console.php cluster:endpoint [list | drop <url|host>]`. Exit code 0
 * when listed or dropped, 1 when nothing matched or the drop could not be
 * written. MAIN only (stripped from LB builds).
 *
 * @package XC_VM_CLI_Commands
 */
class ClusterEndpointCommand implements CommandInterface {
	public function getName(): string {
		return 'cluster:endpoint';
	}

	public function getDescription(): string {
		return "List MAIN's old ports and URLs kept in the nodes' policy, or drop a kept URL: list | drop <url|host>";
	}

	public function execute(array $rArgs): int {
		$rAction = (string) ($rArgs[0] ?? 'list');
		$rWhat = trim((string) ($rArgs[1] ?? ''));
		if (!($rAction === 'list' && count($rArgs) <= 1) && !($rAction === 'drop' && $rWhat !== '' && count($rArgs) === 2)) {
			echo "Usage: cluster:endpoint [list | drop <url|host>]\n";
			return 1;
		}
		$rSettings = ClusterEndpoint::stored(SettingsManager::getAll());
		if ($rAction === 'list') {
			return self::show($rSettings);
		}
		$rGone = ClusterEndpoint::drop($rWhat, $rSettings);
		if ($rGone === null) {
			echo "The kept URLs could not be written; nothing was dropped. Try again.\n";
			return 1;
		}
		if ($rGone === []) {
			echo 'No kept URL is ' . $rWhat . " or on that host.\n";
			return 1;
		}
		echo "Dropped; the nodes refetch the policy at their next heartbeat:\n";
		foreach ($rGone as $rUrl) {
			echo '  ' . $rUrl . "\n";
		}
		return 0;
	}

	/** @param array<string, mixed> $rSettings */
	private static function show(array $rSettings): int {
		$rPorts = ClusterEndpoint::legacyPorts($rSettings);
		$rUrls = ClusterEndpoint::legacyUrls($rSettings);
		echo "Old plain-HTTP ports, served for the cluster API on MAIN's current addresses:\n";
		foreach ($rPorts as $rPort => $rUntil) {
			echo '  ' . $rPort . ' until ' . gmdate('Y-m-d H:i:s', $rUntil) . " UTC\n";
		}
		echo $rPorts === [] ? "  none\n" : '';
		echo "Old URLs, listed last in the policy (latest change first):\n";
		foreach ($rUrls as $rUrl => $rUntil) {
			echo '  ' . $rUrl . ' until ' . gmdate('Y-m-d H:i:s', $rUntil) . " UTC\n";
		}
		echo $rUrls === [] ? "  none\n" : '';
		return 0;
	}
}
