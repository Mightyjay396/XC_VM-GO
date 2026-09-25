<?php

namespace XcVm\Core\Cluster;

/**
 * Where SignalDispatcher writes. A row holds `server_id` and `time` (null =
 * the database's clock), plus either `pid` (and `rtmp`) for a kill, or
 * `custom_data` (and `cache` = 1 for a cache job).
 *
 * @package XC_VM_Core_Cluster
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

interface SignalSink {
	/** @param list<array<string, int|string|null>> $rRows All of one shape. */
	public function insert(array $rRows): bool;

	/** Is an identical cache signal for this node still pending? */
	public function pending(int $rServerID, string $rCustomData): bool;
}
