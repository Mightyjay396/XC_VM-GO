<?php

namespace XcVm\Core\Cluster;

use XcVm\Core\Util\AtomicFile;
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
 * A node in mode 2 keeps its own copy of the KEPT fields it reported
 * (`config/cluster/node_state.json`), since MAIN's row is out of its reach:
 * reported() is what it last sent MAIN, which MAIN may have cleared since
 * (cron:certbot reports its certificate again each day).
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
	 * State fields a node in mode 2 reads back (cron:certbot, the certbot
	 * command): it keeps its own copy of what it reported.
	 */
	public const KEPT = ['certbot_ssl'];

	/**
	 * Set state columns of this node's row: an event with TELEMETRY on, else
	 * the row itself. A node in mode 2 never writes MAIN's database: false
	 * when the spool did not take the event (the next change sends it).
	 *
	 * @param array<string, scalar|null> $rFields keys from STATE
	 */
	public static function state(array $rFields, ?object $rDb = null): bool {
		$rFields = array_intersect_key($rFields, array_flip(self::STATE));
		if ($rFields === []) {
			return false;
		}
		if (NodeFlows::on(NodeFlows::TELEMETRY) && EventSpool::append('p0', [['type' => 'node.state', 'd' => ['fields' => (object) $rFields]]])) {
			if (NodeRole::refusesConnects()) {
				self::keep($rFields);
			}
			return true;
		}
		if (NodeRole::refusesConnects()) {
			return false;
		}
		$rSet = implode(', ', array_map(static fn(string $rColumn): string => '`' . $rColumn . '` = ?', array_keys($rFields)));
		return (bool) ($rDb ?? DatabaseFactory::get())->query('UPDATE `servers` SET ' . $rSet . ' WHERE `id` = ?;', ...[...array_values($rFields), (int) SERVER_ID]);
	}

	/**
	 * A KEPT field of this node's row as a node in mode 2 last reported it:
	 * the value the spool took (P0 is never dropped), unless forgotten
	 * since. MAIN may have cleared its record meanwhile (the admin's
	 * regenerate). Null when there is none. Read with the agent's user's
	 * rights (root's processes too), as written.
	 */
	public static function reported(string $rField): ?string {
		$rValue = null;
		SettingsAudit::asAgentUser(static function () use ($rField, &$rValue): bool {
			$rKept = json_decode((string) @file_get_contents(self::keptFile()), true);
			$rValue = is_array($rKept) && is_string($rKept[$rField] ?? null) ? $rKept[$rField] : null;
			return true;
		}, dirname(self::keptFile()));
		return $rValue;
	}

	/**
	 * Forget the node's copy of KEPT fields, when MAIN's record may have
	 * been cleared: the certbot command, which the admin's regenerate
	 * starts once it cleared `certbot_ssl` (and MAIN's renewal starts
	 * without clearing it). Mode 2 only.
	 */
	public static function forget(string ...$rFields): void {
		if (NodeRole::refusesConnects()) {
			self::rewrite(array_fill_keys(array_intersect($rFields, self::KEPT), null));
		}
	}

	/**
	 * Keep the KEPT fields a node in mode 2 just reported.
	 *
	 * @param array<string, scalar|null> $rFields
	 */
	private static function keep(array $rFields): void {
		$rFields = array_intersect_key($rFields, array_flip(self::KEPT));
		if ($rFields !== []) {
			self::rewrite($rFields);
		}
	}

	/**
	 * Set fields of the node's copy (null removes one). Written aside and
	 * renamed in, as the agent's user (SettingsAudit::asAgentUser):
	 * config/cluster/ is the agent's, where root neither makes a file of its
	 * own nor follows a link. A copy that is not written costs one report
	 * (and a reload) more at the next run.
	 *
	 * @param array<string, scalar|null> $rFields
	 */
	private static function rewrite(array $rFields): void {
		if ($rFields === []) {
			return;
		}
		SettingsAudit::asAgentUser(static function () use ($rFields): bool {
			$rFile = self::keptFile();
			$rKept = json_decode((string) @file_get_contents($rFile), true);
			$rKept = array_filter(array_merge(is_array($rKept) ? $rKept : [], $rFields), static fn($rValue): bool => $rValue !== null);
			$rBody = json_encode((object) $rKept, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
			return $rBody !== false && AtomicFile::write($rFile, $rBody);
		}, dirname(self::keptFile()));
	}

	/** The node's copy: beside the spool, in the agent's directory. */
	private static function keptFile(): string {
		return dirname(rtrim(EventSpool::dir(), '/')) . '/node_state.json';
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
