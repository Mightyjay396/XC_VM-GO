<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\Crypto\ClusterCrypto;
use XcVm\Core\Cluster\Crypto\Enc;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * MAIN → node commands (plan, section 7, Phase 4). A command is a typed JSON
 * record, never shell, signed by the panel with tag `cmd`:
 *
 * ```text
 * {"v":1, "type", "exp", "iat", "cmd_id", "seq", "node_uuid", "gen", "dedupe_key", "args"}
 * ```
 *
 * The extension derives its class from `type`: restrictive ones (kills,
 * stops) sign without a licence, granting ones need it. Commands queue in
 * `cluster_commands`, FIFO per node by `seq`, and reach the node's agent
 * through the `commands` long-poll; the agent checks the signature, its own
 * uuid and generation, `seq` above its high-water and `exp`, runs the
 * command and `ack`s it with the result. Delivery is at least once.
 *
 * Only nodes with the COMMANDS flow on get commands; the others keep the
 * legacy transport (NodeRpc's HTTP, the signals table).
 */
final class CommandBus {
	use DatabaseAware;

	/** Lifetime of a command by type prefix (seconds). */
	public const TTL = ['conn.' => 300, 'node.root' => 86400, 'default' => 600];

	/** Types this increment carries. */
	public const TYPES = ['node.rpc', 'node.root', 'conn.kill_worker'];

	/** Types that are restrictive (always signable); the extension decides, this is informational. */
	public const RESTRICTIVE = ['conn.drop', 'conn.drop_line', 'conn.kill_worker', 'stream.stop', 'vod.stop', 'token.rotate_now', 'node.quarantine', 'node.fence', 'resync', 'config.changed'];

	/** Largest result stored from an ack. */
	public const MAX_RESULT = 65536;

	/**
	 * Is this node taking commands over the cluster API?
	 *
	 * @param array<string, mixed>|null $rNode cluster_nodes row
	 */
	public static function accepts(?array $rNode): bool {
		return $rNode !== null && $rNode['state'] === 'active' && (int) $rNode['mode'] >= 1 && ((int) $rNode['flows'] & NodeRegistry::FLOW_COMMANDS) !== 0;
	}

	/**
	 * Does the node take root commands too? Only once its root-owned pin of
	 * the panel key is in place (cluster:root refuses without it).
	 *
	 * @param array<string, mixed>|null $rNode
	 */
	public static function acceptsRoot(?array $rNode): bool {
		return self::accepts($rNode) && !empty($rNode['root_ready']);
	}

	/**
	 * Sign and queue a command. Returns its cmd_id.
	 *
	 * @param array<string, mixed> $rArgs
	 */
	public static function enqueue(ClusterCrypto $rCrypto, int $rServerID, string $rType, array $rArgs, ?string $rDedupeKey = null, ?int $rTtl = null): string {
		if (!in_array($rType, self::TYPES, true)) {
			throw new \InvalidArgumentException('Unknown command type: ' . $rType);
		}
		$rNode = NodeRegistry::byServer($rServerID);
		if ($rNode === null) {
			throw new \InvalidArgumentException('Server ' . $rServerID . ' is not an enrolled node');
		}
		$rNow = ClusterClock::now();
		$rTtl ??= self::ttl($rType);
		$rCmdID = bin2hex(random_bytes(16));
		for ($rAttempt = 0;; $rAttempt++) {
			self::db()->query('SELECT MAX(`seq`) AS `seq` FROM `cluster_commands` WHERE `server_id` = ?;', $rServerID);
			$rSeq = max((int) (self::db()->get_row()['seq'] ?? 0), (int) $rNode['cmd_seq']) + 1;
			$rDoc = (string) json_encode([
				'v' => 1, 'type' => $rType, 'exp' => $rNow + $rTtl, 'iat' => $rNow, 'cmd_id' => $rCmdID, 'seq' => $rSeq,
				'node_uuid' => (string) $rNode['node_uuid'], 'gen' => (int) $rNode['gen'], 'dedupe_key' => $rDedupeKey, 'args' => (object) $rArgs,
			], JSON_UNESCAPED_SLASHES);
			$rSig = $rCrypto->sign('cmd', $rDoc);
			if ($rDedupeKey !== null) {
				// A newer desired state supersedes a command not yet acked.
				self::db()->query("DELETE FROM `cluster_commands` WHERE `server_id` = ? AND `dedupe_key` = ? AND `state` <> 'acked';", $rServerID, $rDedupeKey);
			}
			try {
				self::db()->query(
					'INSERT INTO `cluster_commands` (`server_id`, `seq`, `cmd_id`, `type`, `action`, `class`, `dedupe_key`, `payload`, `sig`, `state`, `created_at`, `exp`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?);',
					$rServerID,
					$rSeq,
					$rCmdID,
					$rType,
					isset($rArgs['action']) ? substr((string) $rArgs['action'], 0, 32) : null,
					in_array($rType, self::RESTRICTIVE, true) ? 'R' : 'G',
					$rDedupeKey,
					$rDoc,
					$rSig,
					'queued',
					$rNow,
					$rNow + $rTtl
				);
				return $rCmdID;
			} catch (\Throwable $rE) {
				if ($rAttempt >= 3) {
					throw $rE; // a concurrent enqueue took this seq three times
				}
			}
		}
	}

	/**
	 * Commands for a node after its high-water, oldest first; marks them delivered.
	 *
	 * @return list<array{doc: string, sig: string, seq: int}>
	 */
	public static function pending(int $rServerID, int $rAfterSeq, int $rLimit = 50): array {
		self::db()->query(
			"SELECT `id`, `seq`, `payload`, `sig` FROM `cluster_commands` WHERE `server_id` = ? AND `seq` > ? AND `state` IN ('queued', 'delivered') AND `exp` > ? ORDER BY `seq` ASC LIMIT " . max(1, min(200, $rLimit)) . ';',
			$rServerID,
			$rAfterSeq,
			ClusterClock::now()
		);
		$rRows = self::db()->get_rows();
		$rOut = [];
		$rIDs = [];
		foreach ($rRows as $rRow) {
			$rOut[] = ['doc' => (string) $rRow['payload'], 'sig' => Enc::b64url((string) $rRow['sig']), 'seq' => (int) $rRow['seq']];
			$rIDs[] = (int) $rRow['id'];
		}
		if ($rIDs !== []) {
			self::db()->query("UPDATE `cluster_commands` SET `state` = 'delivered', `delivered_at` = ? WHERE `state` = 'queued' AND `id` IN (" . implode(',', $rIDs) . ');', ClusterClock::now());
		}
		return $rOut;
	}

	/**
	 * A node's acknowledgement: only for its own commands. Raises its
	 * high-water (cmd_seq) so a restored queue is not replayed.
	 */
	public static function ack(int $rServerID, string $rCmdID, bool $rOk, string $rResult): bool {
		self::db()->query('SELECT `seq`, `state` FROM `cluster_commands` WHERE `server_id` = ? AND `cmd_id` = ?;', $rServerID, $rCmdID);
		$rRow = self::db()->num_rows() > 0 ? self::db()->get_row() : null;
		if ($rRow === null) {
			return false;
		}
		if (in_array($rRow['state'], ['acked', 'failed'], true)) {
			return true; // a repeated ack
		}
		self::db()->query(
			'UPDATE `cluster_commands` SET `state` = ?, `acked_at` = ?, `result` = ? WHERE `server_id` = ? AND `cmd_id` = ?;',
			$rOk ? 'acked' : 'failed',
			ClusterClock::now(),
			substr($rResult, 0, self::MAX_RESULT),
			$rServerID,
			$rCmdID
		);
		self::db()->query('UPDATE `cluster_nodes` SET `cmd_seq` = ? WHERE `server_id` = ? AND `cmd_seq` < ?;', (int) $rRow['seq'], $rServerID, (int) $rRow['seq']);
		return true;
	}

	/**
	 * A command's outcome once acked: [ok, result], or null while pending.
	 *
	 * @return array{0: bool, 1: string}|null
	 */
	public static function result(string $rCmdID): ?array {
		self::db()->query('SELECT `state`, `result` FROM `cluster_commands` WHERE `cmd_id` = ?;', $rCmdID);
		$rRow = self::db()->num_rows() > 0 ? self::db()->get_row() : null;
		if ($rRow === null || !in_array($rRow['state'], ['acked', 'failed'], true)) {
			return null;
		}
		return [$rRow['state'] === 'acked', (string) $rRow['result']];
	}

	/**
	 * Wait for a command's outcome (the admin side of an RPC).
	 *
	 * @return array{0: bool, 1: string}|null null on timeout
	 */
	public static function await(string $rCmdID, float $rTimeout, int $rPollMs = 100): ?array {
		$rDeadline = microtime(true) + $rTimeout;
		do {
			$rResult = self::result($rCmdID);
			if ($rResult !== null) {
				return $rResult;
			}
			usleep($rPollMs * 1000);
		} while (microtime(true) < $rDeadline);
		return self::result($rCmdID);
	}

	/** Drop expired commands and outcomes older than a day. */
	public static function prune(): void {
		$rNow = ClusterClock::now();
		self::db()->query("DELETE FROM `cluster_commands` WHERE (`exp` <= ? AND `state` IN ('queued', 'delivered')) OR (`acked_at` IS NOT NULL AND `acked_at` < ?);", $rNow, $rNow - 86400);
	}

	private static function ttl(string $rType): int {
		foreach (self::TTL as $rPrefix => $rSeconds) {
			if ($rPrefix !== 'default' && str_starts_with($rType, $rPrefix)) {
				return $rSeconds;
			}
		}
		return self::TTL['default'];
	}
}
