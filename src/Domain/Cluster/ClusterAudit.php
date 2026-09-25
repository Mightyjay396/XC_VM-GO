<?php

namespace XcVm\Domain\Cluster;

use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * The cluster audit log (`cluster_audit`, kept cluster_audit_retention_days):
 * enrolments, rotations, revocations, quarantines and refused requests that
 * carried valid authentication. Unauthenticated failures are not logged here
 * (a flood must not fill it); they only charge the per-source rate limit.
 */
final class ClusterAudit {
	use DatabaseAware;

	public static function log(string $rEvent, ?int $rServerID = null, array|string $rDetail = '', ?string $rActor = null, ?string $rIP = null): void {
		$rText = is_array($rDetail) ? (string) json_encode($rDetail, JSON_UNESCAPED_SLASHES) : $rDetail;
		try {
			self::db()->query(
				'INSERT INTO `cluster_audit` (`time`, `server_id`, `actor`, `event`, `detail`, `ip`) VALUES (?, ?, ?, ?, ?, ?);',
				ClusterClock::now(),
				$rServerID,
				$rActor,
				substr($rEvent, 0, 64),
				mb_substr($rText, 0, 4000),
				$rIP
			);
		} catch (\Throwable) {
			// The audit trail must never break the request it describes.
		}
	}
}
