<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\ClusterSettings;
use XcVm\Core\Cluster\Crypto\ClusterCrypto;
use XcVm\Core\Cluster\Crypto\SessionKeys;
use XcVm\Core\Config\SettingsManager;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * Node token epochs on MAIN.
 *
 * The extension mints a token and returns an opaque epoch record (z and the
 * MAC inputs, sealed to this machine with the node uuid as context) plus the
 * token sealed to the agent's per-epoch key. MAIN keeps the record in
 * `cluster_node_epochs` and hands it back to the extension per request to get
 * that epoch's session keys; deleting the row at expiry erases z.
 *
 * A node holds at most two valid epochs: the one it uses and, after a
 * refresh, the next. A refresh retried with the same ephemeral key gets the
 * same unused next epoch back, so a lost reply never strands a node or piles
 * up epochs.
 */
final class TokenService {
	use DatabaseAware;

	public static function rotationMin(): int {
		return max(5, min(1440, (int) (SettingsManager::get('lb_token_rotation_min') ?? 60)));
	}

	/**
	 * Mint an epoch for a node and store it.
	 *
	 * @param array<string, mixed> $rNode A cluster_nodes row.
	 * @return array<string, mixed> The extension's issue result (token_sealed, epoch, nbf, exp, refresh_at, …).
	 */
	public static function issue(ClusterCrypto $rCrypto, array $rNode, int $rEpoch, string $rAgentEphPub): array {
		$rIssued = $rCrypto->tokenIssue([
			'node_uuid' => (string) $rNode['node_uuid'],
			'server_id' => (int) $rNode['server_id'],
			'gen' => (int) $rNode['gen'],
			'epoch' => $rEpoch,
			'node_sign_pub' => (string) $rNode['node_sign_pub'],
			'agent_eph_pub' => $rAgentEphPub,
			'rotation_min' => self::rotationMin(),
		]);
		$rServerID = (int) $rNode['server_id'];
		self::db()->query('DELETE FROM `cluster_node_epochs` WHERE `server_id` = ? AND `epoch` = ?;', $rServerID, $rEpoch);
		self::db()->query(
			'INSERT INTO `cluster_node_epochs` (`server_id`, `epoch`, `record`, `token_sealed`, `agent_eph_pub`, `nbf`, `exp`, `refresh_at`, `used`, `created_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?);',
			$rServerID,
			$rEpoch,
			(string) $rIssued['epoch_record'],
			(string) $rIssued['token_sealed'],
			$rAgentEphPub,
			(int) $rIssued['nbf'],
			(int) $rIssued['exp'],
			(int) $rIssued['refresh_at'],
			0,
			ClusterClock::now()
		);
		return $rIssued;
	}

	/** @return array<string, mixed>|null A live epoch row. */
	public static function epoch(int $rServerID, int $rEpoch): ?array {
		self::db()->query('SELECT * FROM `cluster_node_epochs` WHERE `server_id` = ? AND `epoch` = ? AND `exp` > ?;', $rServerID, $rEpoch, ClusterClock::now());
		return self::db()->num_rows() > 0 ? self::db()->get_row() : null;
	}

	/**
	 * The session keys of the epoch a request names. Throws the extension's
	 * ClusterRefusedException (REVOKED, CLOCK, EXPIRED, …) when it refuses.
	 *
	 * @param array<string, mixed> $rNode
	 */
	public static function session(ClusterCrypto $rCrypto, array $rNode, int $rEpoch): ?SessionKeys {
		$rRow = self::epoch((int) $rNode['server_id'], $rEpoch);
		if ($rRow === null) {
			return null;
		}
		$rHard = SettingsManager::get('lb_revocation_mode') === 'hard';
		return $rCrypto->session((string) $rRow['record'], (string) $rNode['node_uuid'], $rHard);
	}

	/**
	 * A request authenticated with this epoch: mark it used, and make it the
	 * node's current epoch when it is newer.
	 *
	 * @param array<string, mixed> $rNode
	 */
	public static function markUsed(array $rNode, int $rEpoch, int $rExp): void {
		self::db()->query('UPDATE `cluster_node_epochs` SET `used` = 1 WHERE `server_id` = ? AND `epoch` = ? AND `used` = 0;', (int) $rNode['server_id'], $rEpoch);
		if ($rEpoch > (int) $rNode['epoch']) {
			NodeRegistry::update((int) $rNode['server_id'], ['epoch' => $rEpoch, 'token_exp' => $rExp]);
		}
	}

	/**
	 * `token_refresh`: the next epoch's sealed token for this ephemeral key.
	 *
	 * @param array<string, mixed> $rNode
	 * @return array<string, mixed> token_sealed, epoch, nbf, exp, refresh_at, rotation_min, grace_min
	 */
	public static function refresh(ClusterCrypto $rCrypto, array $rNode, int $rAuthEpoch, string $rAgentEphPub): array {
		$rServerID = (int) $rNode['server_id'];
		self::db()->query('SELECT * FROM `cluster_node_epochs` WHERE `server_id` = ? AND `epoch` > ? AND `exp` > ? ORDER BY `epoch` DESC LIMIT 1;', $rServerID, $rAuthEpoch, ClusterClock::now());
		$rNext = self::db()->num_rows() > 0 ? self::db()->get_row() : null;
		if ($rNext !== null && (int) $rNext['used'] === 0 && hash_equals((string) $rNext['agent_eph_pub'], $rAgentEphPub)) {
			// A retry of a refresh whose reply was lost: the same token again.
			return [
				'token_sealed' => (string) $rNext['token_sealed'], 'epoch' => (int) $rNext['epoch'], 'nbf' => (int) $rNext['nbf'],
				'exp' => (int) $rNext['exp'], 'refresh_at' => (int) $rNext['refresh_at'], 'resent' => true,
			];
		}
		// An unused next epoch for another key is replaced (same number), so the
		// node never has more than its current epoch and one next.
		$rEpoch = ($rNext !== null && (int) $rNext['used'] === 0) ? (int) $rNext['epoch'] : max($rAuthEpoch, (int) $rNode['epoch']) + 1;
		$rIssued = self::issue($rCrypto, $rNode, $rEpoch, $rAgentEphPub);
		ClusterAudit::log('token.refresh', $rServerID, ['epoch' => $rEpoch]);
		$rIssued['resent'] = false;
		return $rIssued;
	}

	/** Drop expired epochs (erasing their z) and anything older than the current one's predecessor. */
	public static function prune(): void {
		self::db()->query('DELETE FROM `cluster_node_epochs` WHERE `exp` <= ?;', ClusterClock::now());
	}

	public static function graceMin(): int {
		return ClusterSettings::graceMin(self::rotationMin());
	}
}
