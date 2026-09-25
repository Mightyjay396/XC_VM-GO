<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\Crypto\ClusterCrypto;
use XcVm\Core\Cluster\Crypto\ClusterRefusedException;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * What the admin's Cluster Nodes page shows and does: the node list with
 * liveness, pending code enrolments, and the actions (issue a code, approve
 * by SAS, reject, revoke). Kept out of the controller so it is tested without
 * a web request; the CLI commands are the same operations.
 */
final class ClusterAdmin {
	use DatabaseAware;

	/**
	 * @param array<int, array<string, mixed>> $rServers ServerRepository::getAll(true)
	 * @return list<array<string, mixed>> One row per enrolled node, with `server_name` and `health`.
	 */
	public static function nodes(array $rServers, int $rOfflineAfterSec): array {
		$rReady = ClusterMeta::readyAtMs(); // its own query: before ours, not between query() and get_rows()
		$rNow = ClusterClock::nowMs();
		self::db()->query('SELECT `server_id`, `node_uuid`, `state`, `mode`, `gen`, `epoch`, `token_exp`, `last_seen_at`, `agent_version`, `quarantine_reason` FROM `cluster_nodes` ORDER BY `server_id`;');
		$rOut = [];
		foreach (self::db()->get_rows() as $rRow) {
			$rLastSeen = $rRow['last_seen_at'] === null ? null : (int) $rRow['last_seen_at'];
			$rRow['server_name'] = (string) ($rServers[(int) $rRow['server_id']]['server_name'] ?? ('#' . $rRow['server_id']));
			$rRow['health'] = $rRow['state'] === 'active' ? NodeHealth::state($rLastSeen, $rReady, $rNow, $rOfflineAfterSec) : (string) $rRow['state'];
			$rOut[] = $rRow;
		}
		return $rOut;
	}

	/** @return list<array<string, mixed>> Code enrolments waiting for the admin. */
	public static function pending(array $rServers): array {
		self::db()->query("SELECT `server_id`, `node_uuid`, `attest`, `created_at` FROM `cluster_enrol_requests` WHERE `state` = 'pending_approval' ORDER BY `created_at`;");
		$rOut = [];
		foreach (self::db()->get_rows() as $rRow) {
			$rRow['server_name'] = (string) ($rServers[(int) $rRow['server_id']]['server_name'] ?? ('#' . $rRow['server_id']));
			$rOut[] = $rRow;
		}
		return $rOut;
	}

	/**
	 * Load balancers a code may be issued for.
	 *
	 * @return array<int, string> server id => name
	 */
	public static function loadBalancers(array $rServers): array {
		$rOut = [];
		foreach ($rServers as $rID => $rServer) {
			if (empty($rServer['is_main']) && (int) ($rServer['server_type'] ?? 0) === 0) {
				$rOut[(int) $rID] = (string) ($rServer['server_name'] ?? ('#' . $rID));
			}
		}
		return $rOut;
	}

	/**
	 * Perform one action from the page.
	 *
	 * @param array<string, mixed> $rInput cluster_action, server_id, sas, url
	 * @param array<int, array<string, mixed>> $rServers
	 * @param array<string, mixed> $rSettings
	 * @return array{type: string, message: string, code?: string, server_id?: int}
	 */
	public static function act(ClusterCrypto $rCrypto, array $rInput, array $rServers, int $rMainID, array $rSettings, ?int $rUserID): array {
		$rAction = (string) ($rInput['cluster_action'] ?? '');
		$rServerID = (int) ($rInput['server_id'] ?? 0);
		$rMain = $rServers[$rMainID] ?? [];
		$rLbs = self::loadBalancers($rServers);
		if (!isset($rLbs[$rServerID])) {
			return ['type' => 'danger', 'message' => 'cluster_not_a_load_balancer'];
		}
		try {
			switch ($rAction) {
				case 'code':
					$rUrl = trim((string) ($rInput['url'] ?? ''));
					if ($rUrl === '') {
						$rUrl = (string) preg_replace('#/cluster/v1/$#', '', ClusterPolicy::current($rSettings, $rMain)['main_urls'][0] ?? '');
					}
					try {
						$rOut = EnrolCodeService::generate($rCrypto, $rServerID, rtrim($rUrl, '/'), $rUserID);
					} catch (\InvalidArgumentException) {
						return ['type' => 'danger', 'message' => 'cluster_bad_main_url'];
					}
					return ['type' => 'success', 'message' => 'cluster_code_issued', 'code' => $rOut['code'], 'server_id' => $rServerID];

				case 'approve':
					return match (EnrolCodeService::approve($rCrypto, $rServerID, (string) ($rInput['sas'] ?? ''), $rSettings, $rMain, $rUserID)) {
						'approved' => ['type' => 'success', 'message' => 'cluster_enrol_approved'],
						'wrong_sas' => ['type' => 'warning', 'message' => 'cluster_wrong_sas'],
						'rejected' => ['type' => 'danger', 'message' => 'cluster_enrol_rejected_attempts'],
						default => ['type' => 'info', 'message' => 'cluster_nothing_pending'],
					};

				case 'reject':
					return EnrolCodeService::reject($rServerID, $rUserID)
						? ['type' => 'warning', 'message' => 'cluster_enrol_rejected']
						: ['type' => 'info', 'message' => 'cluster_nothing_pending'];

				case 'revoke':
					return NodeRegistry::revoke($rServerID, $rCrypto, $rUserID === null ? 'admin' : 'admin:' . $rUserID)
						? ['type' => 'warning', 'message' => 'cluster_node_revoked']
						: ['type' => 'info', 'message' => 'cluster_not_enrolled'];
			}
		} catch (ClusterRefusedException $rE) {
			return ['type' => 'danger', 'message' => $rE->reason() === 'LICENCE' ? 'cluster_licence_required' : 'cluster_refused'];
		}
		return ['type' => 'danger', 'message' => 'cluster_unknown_action'];
	}
}
