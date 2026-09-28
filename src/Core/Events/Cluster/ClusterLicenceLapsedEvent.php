<?php

namespace XcVm\Core\Events\Cluster;

/**
 * Fired when `xcvm_core` refuses to sign a node's lease for want of a licence
 * (ADR 0004, Phase 9; plan section 4, "Licence revocation stops token
 * generation"): the token still goes out, the lease does not, and the node
 * serves on the lease it already holds until that runs out.
 *
 * Dispatched once per refused issue (each token handed to a node: enrolment,
 * `token_refresh`, `token_rekey`), so a lapsed panel with a fleet sees it with
 * every refresh. Refusals for any other reason (the extension's clock gate, a
 * node below its revocation floor) are audited as `node.lease_refused` only.
 *
 * @package XC_VM_Core_Events
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class ClusterLicenceLapsedEvent {
	/**
	 * @param int $serverId The node whose lease was refused.
	 * @param int $tokenExp When the token handed out without it expires (MAIN's clock, unix seconds).
	 * @param int $toleranceH `lb_partition_tolerance_h` as the refused lease would have carried it:
	 *                        the lease the node holds ran to its own token's expiry plus this.
	 * @param string $reason The extension's refusal code (`LICENCE`).
	 */
	public function __construct(
		public readonly int $serverId,
		public readonly int $tokenExp,
		public readonly int $toleranceH,
		public readonly string $reason,
	) {
	}
}
