<?php

namespace XcVm\Core\Cluster;

use XcVm\Domain\Server\ServerRepository;

/**
 * Node Role
 *
 * What this node is in the cluster. Today that is only "MAIN or not": the
 * crontab is copied verbatim to every load balancer, so jobs that change
 * cluster-wide state (table rotation, the TMDb crawl, the signals purge, the
 * update check) ask here and do nothing on an LB. The cluster API plan
 * (docs/superpowers/specs/2026-09-21-main-lb-api-communication-design.md)
 * adds the node mode and flow bits to this class later.
 *
 * An unknown answer (servers cache and DB both unavailable) reads as "not
 * MAIN": every caller guards destructive or cluster-wide work, so skipping one
 * run is the safe side.
 *
 * @package XC_VM_Core_Cluster
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

final class NodeRole {
	/** @var (callable(): array<int, array<string, mixed>>)|null */
	private static $rServers = null;

	public static function isMain(): bool {
		$rServers = self::$rServers !== null ? (self::$rServers)() : ServerRepository::getAll();
		return defined('SERVER_ID') && !empty($rServers[SERVER_ID]['is_main']);
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
