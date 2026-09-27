<?php

namespace XcVm\Core\Cluster;

use XcVm\Core\Exception\XcVmException;

/**
 * A connect to MAIN's MySQL or Redis refused on a node in cluster API mode
 * (mode 2; plan, section 10, step 1). Such a node takes its state from the
 * API and its replica, so the connect names a path the migration missed:
 * ConnectAudit has counted it, with its site, for the heartbeat's `audit`.
 * Thrown before anything is opened, graceful caller or not: a refusal is not
 * an outage to wait out.
 *
 * Lives in Core: it ships to LBs, where Domain\Cluster does not.
 */
final class LbDatabaseAccessException extends XcVmException {
	/**
	 * @param string $rKind ConnectAudit::SQL or ConnectAudit::REDIS
	 * @param string $rSite the caller, "path:line" (ConnectAudit::site)
	 */
	public function __construct(public readonly string $rKind, public readonly string $rSite) {
		parent::__construct(($rKind === ConnectAudit::REDIS ? 'Redis' : 'MySQL') . ': refused on a node in cluster API mode (mode 2), at ' . $rSite);
	}
}
