<?php

namespace XcVm\Domain\Cluster;

use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * The replay cache for authenticated requests: a (node, nonce) pair is
 * accepted once within its 180 s lifetime. It is written only after the MAC
 * or signature has verified, so unauthenticated requests burn no nonce.
 *
 * MySQL-backed until the cluster bus exists (Phase 2 hardening); the table's
 * primary key makes the check-and-insert atomic.
 */
final class NonceStore {
	use DatabaseAware;

	public const TTL = 180;

	/** Record a nonce; false when it was already used (a replay). */
	public static function claim(string $rNode, string $rNonce): bool {
		try {
			return (bool) self::db()->query('INSERT INTO `cluster_nonces` (`node`, `nonce`, `exp`) VALUES (?, ?, ?);', $rNode, $rNonce, ClusterClock::now() + self::TTL);
		} catch (\Throwable) {
			return false; // duplicate key
		}
	}

	public static function purge(): void {
		self::db()->query('DELETE FROM `cluster_nonces` WHERE `exp` < ?;', ClusterClock::now());
	}
}
