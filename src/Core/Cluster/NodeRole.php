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
 * in mode 1 and 2, refused in mode 2. The node's mode and flow bits are
 * NodeFlows'.
 *
 * An unknown answer to isMain() (servers cache and DB both unavailable) reads
 * as "not MAIN": every caller guards destructive or cluster-wide work, so
 * skipping one run is the safe side. The connect answers need neither.
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

	/** Tests: mainBuild()'s answer. */
	private static ?bool $rMainBuild = null;

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

	/**
	 * Is every connect to MAIN's MySQL/Redis refused (plan, section 10, step
	 * 1: LbDatabaseAccessException)? On a node in mode 2 (api) that MAIN
	 * counts as active or quarantined, as its agent's flows.json says
	 * (ReplicaBoot::apiMode), whatever its flows. Never in mode 1, which
	 * boots from its replica too once CONFIG is on but may still reach
	 * MAIN's database, counted. Read at each connect, without a database.
	 * Never on MAIN, even with a stray flows.json: MAIN's build ships the
	 * cluster API, which the load balancer build never does.
	 */
	public static function refusesConnects(): bool {
		return ReplicaBoot::apiMode() && !self::mainBuild();
	}

	/**
	 * Is this MAIN's build? It ships MAIN's cluster API endpoint
	 * (`Public/cluster/index.php`), which the load balancer build strips
	 * (tools/ci/verify-lb-archive.sh fails the build otherwise). Known from
	 * the files alone, so it holds while the servers and the database are
	 * out of reach.
	 */
	public static function mainBuild(): bool {
		return self::$rMainBuild ?? (defined('MAIN_HOME') && is_file(MAIN_HOME . 'Public/cluster/index.php'));
	}

	/** Tests: force auditConnects()'s answer; null lets the node's mode decide again. */
	public static function resetAudit(?bool $rValue = null): void {
		self::$rAudit = $rValue;
		self::$rTrace = null;
	}

	/** Tests: force mainBuild()'s answer; null reads the files again. */
	public static function useMainBuild(?bool $rMain): void {
		self::$rMainBuild = $rMain;
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
