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

	/** The flows the page switches, by action name. */
	public const FLOW_BITS = [
		'telemetry' => NodeRegistry::FLOW_TELEMETRY,
		'commands' => NodeRegistry::FLOW_COMMANDS,
		'logs' => NodeRegistry::FLOW_LOGS,
		'streams' => NodeRegistry::FLOW_STREAMS,
		'content' => NodeRegistry::FLOW_CONTENT,
		'config' => NodeRegistry::FLOW_CONFIG,
		'connections' => NodeRegistry::FLOW_CONNECTIONS,
	];

	/** Flows a node must have before it can run without MAIN's database: everything but the data plane (Phase 8). */
	public const MODE2_FLOWS = NodeRegistry::FLOW_TELEMETRY | NodeRegistry::FLOW_COMMANDS | NodeRegistry::FLOW_LOGS
		| NodeRegistry::FLOW_STREAMS | NodeRegistry::FLOW_CONTENT | NodeRegistry::FLOW_CONFIG | NodeRegistry::FLOW_CONNECTIONS;

	/** Days of zero MySQL and Redis connects a node must report before mode 2 (plan, section 11: the cutover gate). */
	public const CUTOVER_CLEAN_DAYS = 7;

	/**
	 * May this node move to $rMode? Pure, so the gate is tested without a request.
	 *
	 * Going down is always allowed: it is the way back when a node misbehaves.
	 * Going up to 1 needs the config replica, because that is what a node boots
	 * from. Going up to 2 stops the node reaching MAIN's database at all, so it
	 * needs every flow but the data plane, and the node's own connect audit must
	 * show it has not opened MySQL or Redis for CUTOVER_CLEAN_DAYS.
	 *
	 * @param array<string, mixed>  $rNode     cluster_nodes row.
	 * @param array<string, mixed>|null $rConnects NodeAudit::connectsOf() of its last report.
	 * @param int $rNow Unix seconds.
	 * @return array{0: bool, 1: string} [allowed, message key]
	 */
	public static function modeGate(array $rNode, ?array $rConnects, int $rMode, int $rNow): array {
		if ($rMode < 0 || $rMode > 2) {
			return [false, 'cluster_mode_unknown'];
		}
		if ($rMode <= (int) $rNode['mode']) {
			return [true, 'cluster_mode_done'];
		}
		if (((int) $rNode['flows'] & NodeRegistry::FLOW_CONFIG) !== NodeRegistry::FLOW_CONFIG) {
			return [false, 'cluster_mode_needs_config'];
		}
		if ($rMode < 2) {
			return [true, 'cluster_mode_done'];
		}
		if (((int) $rNode['flows'] & self::MODE2_FLOWS) !== self::MODE2_FLOWS) {
			return [false, 'cluster_mode_needs_flows'];
		}
		if ($rConnects === null) {
			return [false, 'cluster_mode_no_audit'];
		}
		if ((int) $rConnects['sql_connects'] !== 0 || (int) $rConnects['redis_connects'] !== 0) {
			return [false, 'cluster_mode_still_connects'];
		}
		$rSince = (int) ($rConnects['connects_since'] ?? 0);
		if ($rSince <= 0 || $rNow - $rSince < self::CUTOVER_CLEAN_DAYS * 86400) {
			return [false, 'cluster_mode_too_soon'];
		}
		return [true, 'cluster_mode_done'];
	}

	/**
	 * @param array<int, array<string, mixed>> $rServers ServerRepository::getAll(true)
	 * @return list<array<string, mixed>> One row per enrolled node, with `server_name`, `health`,
	 *                                    `settings_misses` and `connects` (NodeAudit; null when not reported).
	 */
	public static function nodes(array $rServers, int $rOfflineAfterSec): array {
		$rReady = ClusterMeta::readyAtMs(); // its own query: before ours, not between query() and get_rows()
		$rReports = NodeAudit::reports(); // likewise
		$rHeard = HeartbeatService::lastSeen(); // MySQL's copy may be a flush behind
		$rNow = ClusterClock::nowMs();
		self::db()->query('SELECT `server_id`, `node_uuid`, `state`, `mode`, `flows`, `root_ready`, `gen`, `epoch`, `token_exp`, `last_seen_at`, `agent_version`, `arch`, `quarantine_reason` FROM `cluster_nodes` ORDER BY `server_id`;');
		$rOut = [];
		foreach (self::db()->get_rows() as $rRow) {
			$rLastSeen = HeartbeatService::freshest($rRow['last_seen_at'], $rHeard[(int) $rRow['server_id']] ?? null);
			$rRow['last_seen_at'] = $rLastSeen;
			$rRow['server_name'] = (string) ($rServers[(int) $rRow['server_id']]['server_name'] ?? ('#' . $rRow['server_id']));
			$rRow['health'] = $rRow['state'] === 'active' ? NodeHealth::state($rLastSeen, $rReady, $rNow, $rOfflineAfterSec) : (string) $rRow['state'];
			$rRow['settings_misses'] = $rReports[(int) $rRow['server_id']]['settings_misses'] ?? null;
			$rRow['connects'] = NodeAudit::connectsOf($rReports[(int) $rRow['server_id']] ?? null);
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
	 * Is this `servers` row a load balancer (not MAIN, not a proxy): what a
	 * node can be enrolled, re-enrolled or issued a code for, from this page
	 * or the CLI (cluster:enrol-code, cluster:reenrol, server:enrol).
	 *
	 * @param array<string, mixed>|null $rServer
	 */
	public static function isLoadBalancer(?array $rServer): bool {
		return $rServer !== null && empty($rServer['is_main']) && intval($rServer['server_type'] ?? 0) === 0;
	}

	/**
	 * Load balancers a code may be issued for.
	 *
	 * @return array<int, string> server id => name
	 */
	public static function loadBalancers(array $rServers): array {
		$rOut = [];
		foreach ($rServers as $rID => $rServer) {
			if (self::isLoadBalancer($rServer)) {
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

				case 'telemetry_on':
				case 'telemetry_off':
				case 'commands_on':
				case 'commands_off':
				case 'logs_on':
				case 'logs_off':
				case 'streams_on':
				case 'streams_off':
				case 'content_on':
				case 'content_off':
				case 'config_on':
				case 'config_off':
				case 'connections_on':
				case 'connections_off':
					$rNode = NodeRegistry::byServer($rServerID);
					if ($rNode === null || !in_array($rNode['state'], ['active', 'quarantined'], true)) {
						return ['type' => 'info', 'message' => 'cluster_not_enrolled'];
					}
					[$rName, $rSwitch] = explode('_', $rAction);
					$rBit = self::FLOW_BITS[$rName];
					$rFlows = $rSwitch === 'on' ? ((int) $rNode['flows'] | $rBit) : ((int) $rNode['flows'] & ~$rBit);
					if (!NodeRegistry::validFlows($rFlows)) {
						// CONNECTIONS needs COMMANDS and STREAMS; DATAPLANE needs STREAMS and CONTENT.
						return ['type' => 'warning', 'message' => 'cluster_flow_needs'];
					}
					NodeRegistry::update($rServerID, ['flows' => $rFlows]);
					ClusterAudit::log('node.flows', $rServerID, ['flows' => $rFlows, 'was' => (int) $rNode['flows']], $rUserID === null ? 'admin' : 'admin:' . $rUserID);
					return ['type' => 'success', 'message' => 'cluster_' . $rName . '_' . $rSwitch . '_done'];

				case 'mode_up':
				case 'mode_down':
					$rNode = NodeRegistry::byServer($rServerID);
					if ($rNode === null || !in_array($rNode['state'], ['active', 'quarantined'], true)) {
						return ['type' => 'info', 'message' => 'cluster_not_enrolled'];
					}
					$rWanted = (int) $rNode['mode'] + ($rAction === 'mode_up' ? 1 : -1);
					if ($rWanted < 0 || $rWanted > 2) {
						return ['type' => 'info', 'message' => 'cluster_mode_unknown'];
					}
					[$rAllowed, $rWhy] = self::modeGate($rNode, NodeAudit::connectsOf(NodeAudit::reports()[$rServerID] ?? null), $rWanted, time());
					if (!$rAllowed) {
						return ['type' => 'warning', 'message' => $rWhy];
					}
					NodeRegistry::update($rServerID, ['mode' => $rWanted]);
					ClusterAudit::log('node.mode', $rServerID, ['mode' => $rWanted, 'was' => (int) $rNode['mode']], $rUserID === null ? 'admin' : 'admin:' . $rUserID);
					return ['type' => 'success', 'message' => 'cluster_mode_done'];

				case 'rotate_now':
					// The token is the agent's, so this is a command, not a row:
					// the node rotates at its next poll without being stopped.
					[$rRouted, $rQueued] = ClusterRoute::rotateNow($rServerID);
					if (!$rRouted) {
						return ['type' => 'info', 'message' => 'cluster_rotate_no_commands'];
					}
					ClusterAudit::log('node.token_rotate', $rServerID, ['queued' => $rQueued], $rUserID === null ? 'admin' : 'admin:' . $rUserID);
					return $rQueued
						? ['type' => 'success', 'message' => 'cluster_rotate_done']
						: ['type' => 'danger', 'message' => 'cluster_rotate_failed'];

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
