<?php

namespace XcVm\Core\Cluster;

use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * Writes signals as `signals` rows in MAIN's database — the pre-cluster-API
 * behaviour, column for column.
 *
 * @package XC_VM_Core_Cluster
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

final class LegacySqlSignalSink implements SignalSink {
	/** @param object|null $rDb A DatabaseHandler; null uses the process-wide one. */
	public function __construct(private ?object $rDb = null) {
	}

	public function insert(array $rRows): bool {
		if ($rRows === []) {
			return true;
		}
		$rColumns = array_keys($rRows[0]);
		$rTuples = [];
		$rParams = [];
		foreach ($rRows as $rRow) {
			$rValues = [];
			foreach ($rColumns as $rColumn) {
				if ($rColumn === 'time' && $rRow['time'] === null) {
					$rValues[] = 'UNIX_TIMESTAMP()';
				} else {
					$rValues[] = '?';
					$rParams[] = $rRow[$rColumn];
				}
			}
			$rTuples[] = '(' . implode(',', $rValues) . ')';
		}
		$rSql = 'INSERT INTO `signals`(`' . implode('`, `', $rColumns) . '`) VALUES' . implode(',', $rTuples) . ';';
		return (bool) $this->db()->query($rSql, ...$rParams);
	}

	public function pending(int $rServerID, string $rCustomData): bool {
		$rDb = $this->db();
		$rDb->query('SELECT COUNT(*) AS `count` FROM `signals` WHERE `server_id` = ? AND `cache` = 1 AND `custom_data` = ?;', $rServerID, $rCustomData);
		return intval($rDb->get_row()['count'] ?? 0) > 0;
	}

	private function db(): object {
		return $this->rDb ?? DatabaseFactory::get();
	}
}
