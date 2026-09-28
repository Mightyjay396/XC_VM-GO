<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\NodeActions;
use XcVm\Core\Cluster\NodeCredentials;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * MAIN's half of taking its database credentials off a load balancer (plan,
 * section 10, step 3; Phase 9): the node drops them (`node.root
 * strip_db_credentials`, or a credential-free `node.root install_config`),
 * then MAIN revokes the node's grant (`\XC_VM::db_revoke`) and records when
 * (`cluster_nodes.db_revoked_at`, migration 052).
 *
 * Stripping and revoking is the point of no return for a node (plan, section
 * 12), so strip() sends the command only to a node already in mode 2 — one that
 * reaches MAIN through the cluster API alone and has passed the connect audit
 * to get there. The revoke waits for the node's own word: the command's ack,
 * whose result carries the extension's report of the config afterwards
 * (NodeCredentials::outcome()). A config that still holds credentials, an ack
 * that only says root queued it, or a failed command revokes nothing.
 *
 * `api_mode_allowed` is not touched here: whether new nodes install in API
 * mode is the operator's cutover decision.
 *
 * @package XC_VM_Domain_Cluster
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class DbCredentials {
	use DatabaseAware;

	/** @var \Closure(string): bool|null Tests: `db_revoke`. */
	private static ?\Closure $rRevoke = null;

	/** Tests: revoke through $rRevoke; null restores `\XC_VM::db_revoke`. */
	public static function useRevoke(?\Closure $rRevoke): void {
		self::$rRevoke = $rRevoke;
	}

	/**
	 * Ask a node in mode 2 to drop MAIN's credentials. Null when the signed
	 * command was queued, else why nothing was sent.
	 */
	public static function strip(int $rServerID): ?string {
		self::db()->query('SELECT `mode`, `state` FROM `cluster_nodes` WHERE `server_id` = ?;', $rServerID);
		$rNode = self::db()->num_rows() > 0 ? self::db()->get_row() : null;
		if ($rNode === null) {
			return 'not a cluster node';
		}
		if ((int) $rNode['mode'] !== 2) {
			return 'the node is in mode ' . (int) $rNode['mode'] . '; only a node in mode 2 gives up its credentials';
		}
		if ($rNode['state'] !== 'active') {
			return 'the node is ' . $rNode['state'];
		}
		return NodeActions::stripDbCredentials($rServerID) ? null : 'the command was not queued (see the cluster log)';
	}

	/**
	 * A `node.root` command's first ack (ClusterApi). For a credential action
	 * that succeeded and left the node's config without credentials, revoke
	 * the node's grant. True when it revoked.
	 */
	public static function acked(int $rServerID, string $rCmdID, bool $rOk, string $rResult): bool {
		if (!$rOk) {
			return false;
		}
		self::db()->query('SELECT `type`, `payload` FROM `cluster_commands` WHERE `server_id` = ? AND `cmd_id` = ?;', $rServerID, $rCmdID);
		$rRow = self::db()->num_rows() > 0 ? self::db()->get_row() : null;
		$rDoc = $rRow !== null && $rRow['type'] === 'node.root' ? json_decode((string) $rRow['payload'], true) : null;
		if (!is_array($rDoc) || !in_array($rDoc['action'] ?? null, [NodeCredentials::STRIP, NodeCredentials::INSTALL], true)) {
			return false;
		}
		$rConfig = NodeCredentials::outcome($rResult);
		if ($rConfig === null || ($rConfig['db_credentials'] ?? true) !== false) {
			return false;
		}
		return self::revoke($rServerID);
	}

	/**
	 * Revoke the node's grant and record it. The extension refuses a host MAIN's
	 * own connection may use (loopback, its DB host), so a misconfigured row
	 * cannot take MAIN off its database.
	 */
	public static function revoke(int $rServerID): bool {
		self::db()->query('SELECT `server_ip` FROM `servers` WHERE `id` = ?;', $rServerID);
		$rIP = self::db()->num_rows() > 0 ? (string) self::db()->get_row()['server_ip'] : '';
		if ($rIP === '') {
			return false;
		}
		$rRevoke = self::$rRevoke ?? (class_exists('XC_VM') && method_exists('XC_VM', 'db_revoke') ? static fn(string $rHost): bool => (bool) \XC_VM::db_revoke($rHost) : null);
		$rDone = $rRevoke !== null && $rRevoke($rIP);
		if (!$rDone) {
			ClusterAudit::log('node.db_revoke_failed', $rServerID, ['ip' => $rIP], 'system');
			return false;
		}
		NodeRegistry::update($rServerID, ['db_revoked_at' => ClusterClock::now()]);
		ClusterAudit::log('node.db_revoked', $rServerID, ['ip' => $rIP], 'system');
		return true;
	}
}
