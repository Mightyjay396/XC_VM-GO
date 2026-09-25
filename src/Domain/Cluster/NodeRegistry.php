<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\Crypto\ClusterCrypto;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * Enrolled nodes (`cluster_nodes`): identity, state, mode and flow bits.
 *
 * States: `enrolling` (first token issued at install, waiting for
 * enrol_complete), `active`, `quarantined` (authenticated evidence of a clone;
 * the admin decides), `revoked`. Liveness (suspect/offline) is derived from
 * last_seen_at by NodeHealth, not stored.
 *
 * Revocation raises the extension's floor first (cluster_node_gen), so a node
 * whose row is restored from a backup still cannot open a session.
 */
final class NodeRegistry {
	use DatabaseAware;

	public const STATES = ['enrolling', 'active', 'quarantined', 'revoked'];

	/** Flow bits (plan, section 12). */
	public const FLOW_TELEMETRY = 1;
	public const FLOW_COMMANDS = 2;
	public const FLOW_LOGS = 4;
	public const FLOW_STREAMS = 8;
	public const FLOW_CONTENT = 16;
	public const FLOW_CONFIG = 32;
	public const FLOW_CONNECTIONS = 64;
	public const FLOW_DATAPLANE = 128;

	/** @return array<string, mixed>|null */
	public static function byUuid(string $rUuid): ?array {
		self::db()->query('SELECT * FROM `cluster_nodes` WHERE `node_uuid` = ?;', $rUuid);
		return self::db()->num_rows() > 0 ? self::db()->get_row() : null;
	}

	/** @return array<string, mixed>|null */
	public static function byServer(int $rServerID): ?array {
		self::db()->query('SELECT * FROM `cluster_nodes` WHERE `server_id` = ?;', $rServerID);
		return self::db()->num_rows() > 0 ? self::db()->get_row() : null;
	}

	/**
	 * Create (or re-create, on re-enrolment) the row of a node that is about to
	 * receive its first token. A re-enrolment increments gen, so every token of
	 * the previous generation stops working.
	 *
	 * @return array{gen: int} The generation the first token must carry.
	 */
	public static function startEnrolment(int $rServerID, string $rUuid, string $rSignPub, string $rBoxPub, int $rMode, ?ClusterCrypto $rCrypto = null): array {
		$rNow = ClusterClock::now();
		$rExisting = self::byServer($rServerID);
		$rGen = $rExisting ? (int) $rExisting['gen'] + 1 : 1;
		if ($rExisting && $rCrypto !== null) {
			$rCrypto->nodeGen((string) $rExisting['node_uuid'], $rGen);
		}
		self::db()->query('DELETE FROM `cluster_node_epochs` WHERE `server_id` = ?;', $rServerID);
		self::db()->query('DELETE FROM `cluster_nodes` WHERE `server_id` = ?;', $rServerID);
		self::db()->query(
			'INSERT INTO `cluster_nodes` (`server_id`, `node_uuid`, `state`, `mode`, `flows`, `gen`, `node_sign_pub`, `node_box_pub`, `epoch`, `enrol_deadline`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?);',
			$rServerID,
			$rUuid,
			'enrolling',
			$rMode,
			0,
			$rGen,
			$rSignPub,
			$rBoxPub,
			0,
			$rNow + 1800,
			$rNow,
			$rNow
		);
		return ['gen' => $rGen];
	}

	/** @param array<string, mixed> $rFields */
	public static function update(int $rServerID, array $rFields): void {
		if (empty($rFields)) {
			return;
		}
		$rFields['updated_at'] = ClusterClock::now();
		$rSet = implode(', ', array_map(static fn($rKey) => '`' . $rKey . '` = ?', array_keys($rFields)));
		self::db()->query('UPDATE `cluster_nodes` SET ' . $rSet . ' WHERE `server_id` = ?;', ...array_values($rFields), ...[$rServerID]);
	}

	/**
	 * Revoke a node: raise the extension's floor to the next generation, drop
	 * its epochs, and mark the row. Every later request gets a signed
	 * NODE_REVOKED.
	 */
	public static function revoke(int $rServerID, ClusterCrypto $rCrypto, string $rActor = 'admin'): bool {
		$rNode = self::byServer($rServerID);
		if ($rNode === null) {
			return false;
		}
		$rGen = (int) $rNode['gen'] + 1;
		$rCrypto->nodeGen((string) $rNode['node_uuid'], $rGen);
		self::db()->query('DELETE FROM `cluster_node_epochs` WHERE `server_id` = ?;', $rServerID);
		self::update($rServerID, ['state' => 'revoked', 'gen' => $rGen]);
		ClusterAudit::log('node.revoke', $rServerID, ['node' => $rNode['node_uuid'], 'gen' => $rGen], $rActor);
		return true;
	}

	/** Flows a node may have, given the dependency rules (CONNECTIONS needs COMMANDS+STREAMS; DATAPLANE needs STREAMS+CONTENT). */
	public static function validFlows(int $rFlows): bool {
		if (($rFlows & self::FLOW_CONNECTIONS) && (($rFlows & (self::FLOW_COMMANDS | self::FLOW_STREAMS)) !== (self::FLOW_COMMANDS | self::FLOW_STREAMS))) {
			return false;
		}
		if (($rFlows & self::FLOW_DATAPLANE) && (($rFlows & (self::FLOW_STREAMS | self::FLOW_CONTENT)) !== (self::FLOW_STREAMS | self::FLOW_CONTENT))) {
			return false;
		}
		return $rFlows >= 0 && $rFlows <= 255;
	}
}
