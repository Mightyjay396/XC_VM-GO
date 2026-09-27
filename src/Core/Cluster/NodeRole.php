<?php

namespace XcVm\Core\Cluster;

use XcVm\Domain\Server\ServerRepository;

/**
 * Node Role
 *
 * What this node is in the cluster. First "MAIN or not": the crontab is
 * copied verbatim to every load balancer, so jobs that change cluster-wide
 * state (table rotation, the TMDb crawl, the signals purge, the update check)
 * ask here and do nothing on an LB. Then what a connect to MAIN's MySQL or
 * Redis meets on this node (plan, section 10, step 1; ConnectAudit): counted
 * in mode 1 and 2. The node's mode and flow bits are NodeFlows'.
 *
 * An unknown answer to isMain() (servers cache and DB both unavailable) reads
 * as "not MAIN": every caller guards destructive or cluster-wide work, so
 * skipping one run is the safe side. The connect answer needs neither.
 *
 * @package XC_VM_Core_Cluster
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

final class NodeRole {
	/** @var (callable(): array<int, array<string, mixed>>)|null */
	private static $rServers;

	/** Tests: auditConnects()'s answer, whatever the node's mode. */
	private static ?bool $rAudit = null;

	/** A manual trace (XCVM_CONNECT_AUDIT=1 or the `enabled` file), read once per process. */
	private static ?bool $rTrace = null;

	public static function isMain(): bool {
		$rServers = self::$rServers !== null ? (self::$rServers)() : ServerRepository::getAll();
		return defined('SERVER_ID') && !empty($rServers[SERVER_ID]['is_main']);
	}

	/**
	 * Is every connect to MAIN's MySQL/Redis to be counted (ConnectAudit)?
	 * On a node in mode 1 or 2, by its agent's flows.json alone (read at each
	 * connect, so a mode switch counts from the next one; MAIN runs no agent
	 * and has no such file), and for a manual trace: under
	 * XCVM_CONNECT_AUDIT=1 or while `STORAGE_PATH/cluster/sql_audit/enabled`
	 * exists (checked once per process).
	 */
	public static function auditConnects(): bool {
		if (self::$rAudit !== null) {
			return self::$rAudit;
		}
		self::$rTrace ??= getenv('XCVM_CONNECT_AUDIT') === '1'
			|| (defined('STORAGE_PATH') && is_file(STORAGE_PATH . 'cluster/sql_audit/enabled'));
		return self::$rTrace || NodeFlows::declared()['mode'] >= 1;
	}

	/** Tests: force auditConnects()'s answer; null lets the node's mode decide again. */
	public static function resetAudit(?bool $rValue = null): void {
		self::$rAudit = $rValue;
		self::$rTrace = null;
	}

	/**
	 * Replace the servers source (tests only); null restores the repository.
	 *
	 * @param (callable(): array<int, array<string, mixed>>)|null $rServers
	 */
	public static function useServers(?callable $rServers): void {
		self::$rServers = $rServers;
	}
}
