<?php

namespace XcVm\Core\Cluster;

use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * What a node reports about itself in its own `servers` row (plan, Phase 5,
 * `node.state` and `inventory`). A legacy node writes the row in MAIN's
 * database, as before. A node whose TELEMETRY flow is on sends it through its
 * agent instead ({@see EventSpool}), and MAIN writes only these columns of
 * the node's own row (EventIngest):
 *
 * ```text
 * node.state      P0  certbot_ssl, governor, sysctl    when it changes
 * node.inventory  P1  hardware, devices, interfaces…   once a minute (cron:servers)
 * ```
 *
 * The inventory lane is P1: a newer inventory replaces an older one, so one
 * lost past the cap costs nothing. Columns that grant or route are never the
 * node's to send: `whitelist_ips` (it feeds the allowed IPs), `server_ip`,
 * `status` (the heartbeat's) and the ports stay with MAIN and the admin.
 *
 * In Core: the crons that call it ship to LBs.
 */
final class NodeStateSink {
	/** Columns a node.state event may set. */
	public const STATE = ['certbot_ssl', 'governor', 'sysctl'];

	/** Columns a node.inventory event may set (time_offset comes from MAIN's clock offset). */
	public const INVENTORY = ['remote_status', 'xc_vm_version', 'server_hardware', 'governors', 'sysctl', 'video_devices', 'audio_devices', 'gpu_info', 'interfaces', 'ping'];

	/** Longest value MAIN takes for one column. */
	public const MAX_VALUE = 262144;

	/**
	 * Set state columns of this node's row: an event with TELEMETRY on, else
	 * the row itself.
	 *
	 * @param array<string, scalar|null> $rFields keys from STATE
	 */
	public static function state(array $rFields, ?object $rDb = null): bool {
		$rFields = array_intersect_key($rFields, array_flip(self::STATE));
		if ($rFields === []) {
			return false;
		}
		if (NodeFlows::on(NodeFlows::TELEMETRY) && EventSpool::append('p0', [['type' => 'node.state', 'd' => ['fields' => (object) $rFields]]])) {
			return true;
		}
		$rSet = implode(', ', array_map(static fn(string $rColumn): string => '`' . $rColumn . '` = ?', array_keys($rFields)));
		return (bool) ($rDb ?? DatabaseFactory::get())->query('UPDATE `servers` SET ' . $rSet . ' WHERE `id` = ?;', ...[...array_values($rFields), (int) SERVER_ID]);
	}

	/**
	 * Send this node's inventory as an event when TELEMETRY is on. False when
	 * it was not sent: the caller writes the row the legacy way.
	 *
	 * @param array<string, scalar|null> $rFields keys from INVENTORY
	 */
	public static function inventory(array $rFields): bool {
		$rFields = array_intersect_key($rFields, array_flip(self::INVENTORY));
		return $rFields !== [] && NodeFlows::on(NodeFlows::TELEMETRY)
			&& EventSpool::append('p1', [['type' => 'node.inventory', 'd' => ['fields' => (object) $rFields]]]);
	}
}
