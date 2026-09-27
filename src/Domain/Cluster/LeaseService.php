<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\ClusterSettings;
use XcVm\Core\Cluster\Crypto\ClusterCrypto;
use XcVm\Core\Cluster\Crypto\ClusterRefusedException;
use XcVm\Core\Config\SettingsManager;

/**
 * The lease that travels with a node's token: how long that node may keep
 * serving viewers once it can no longer reach MAIN (plan section 9; ADR-002,
 * "Lease").
 *
 * The extension signs the document (tag `lea`) and caps it itself at
 * `min(token_exp + lb_partition_tolerance_h · 3600, iat + 26 h)`, with the
 * tolerance clamped to 0-24 h. It signs only under a valid licence and only
 * above the node's revocation floor, and that is what stops a revoked panel's
 * fleet: MAIN hands out no further leases and every node runs out of the one it
 * holds. The node checks it against the pinned panel key on MAIN time, so
 * winding its own clock back gains it nothing.
 *
 * MAIN keeps none of them. A lease is minted whenever a token is handed to a
 * node, a resent token included, so there is nothing to hold in step with the
 * epoch rows and nothing to expire. A refusal is not the caller's error either:
 * the reply goes out without a lease and the node keeps the one it has until
 * its `exp`, which is the whole reason it holds one.
 *
 * @package XC_VM_Domain_Cluster
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class LeaseService {
	/**
	 * A lease for the token that expires at `$rTokenExp`.
	 *
	 * @param array<string, mixed> $rNode A `cluster_nodes` row.
	 * @return array{payload: string, sig: string, exp: int}|null Null when the extension signed none.
	 */
	public static function issue(ClusterCrypto $rCrypto, array $rNode, int $rTokenExp): ?array {
		try {
			$rLease = $rCrypto->leaseIssue([
				'node_uuid' => (string) $rNode['node_uuid'],
				'server_id' => (int) $rNode['server_id'],
				'gen' => (int) $rNode['gen'],
				'token_exp' => $rTokenExp,
				'tolerance_h' => self::toleranceHours(),
			]);
		} catch (ClusterRefusedException $rE) {
			// A licence gone, a clock the extension will not vouch for, a node
			// below its floor: the token still goes out, the lease does not.
			ClusterAudit::log('node.lease_refused', (int) $rNode['server_id'], ['reason' => $rE->reason(), 'token_exp' => $rTokenExp]);
			return null;
		}
		return [
			'payload' => (string) $rLease['payload'],
			'sig' => (string) $rLease['sig'],
			'exp' => (int) $rLease['exp'],
		];
	}

	/**
	 * The lease as a reply carries it, or nothing at all when none was signed.
	 * The document is base64 of the bytes MAIN signed and never re-encoded
	 * JSON: the node verifies the signature over exactly those bytes.
	 *
	 * @param array{payload: string, sig: string, exp: int}|null $rLease
	 * @return array{lease?: array{payload: string, sig: string, exp: int}}
	 */
	public static function wire(?array $rLease): array {
		if ($rLease === null) {
			return [];
		}
		return [
			'lease' => [
				'payload' => base64_encode($rLease['payload']),
				'sig' => base64_encode($rLease['sig']),
				'exp' => $rLease['exp'],
			],
		];
	}

	/** `lb_partition_tolerance_h`, as the extension's 0-24 h bound takes it. */
	public static function toleranceHours(): int {
		return ClusterSettings::clampInt(
			'lb_partition_tolerance_h',
			(int) (SettingsManager::get('lb_partition_tolerance_h') ?? ClusterSettings::INTS['lb_partition_tolerance_h'][0])
		);
	}
}
