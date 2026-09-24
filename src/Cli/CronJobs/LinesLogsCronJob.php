<?php

namespace XcVm\Cli\CronJobs;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\CronTrait;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * LinesLogsCronJob — lines logs cron job
 *
 * @package XC_VM_CLI_CronJobs
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class LinesLogsCronJob implements CommandInterface {
	use DatabaseAware;
	use CronTrait;

	/** Rows per INSERT, well under MariaDB's max_allowed_packet. */
	private const IMPORT_BATCH = 1000;

	/** Spool keys, in `lines_logs` column order. */
	private const KEYS = ['stream_id', 'user_id', 'action', 'query_string', 'user_agent', 'user_ip', 'extra_data', 'time'];

	public function getName(): string {
		return 'cron:lines_logs';
	}

	public function getDescription(): string {
		return 'Cron: import client request logs into DB';
	}

	public function execute(array $rArgs): int {
		if (!$this->assertRunAsXcVm()) {
			return 1;
		}

		$this->initCron('XC_VM[Lines Logs]');
		$this->loadCron();

		return 0;
	}

	private function loadCron(): void {
		$this->importFile(LOGS_TMP_PATH . 'client_request.log');
	}

	/**
	 * Import a spool, claimed by renaming it to <spool>.import so rows written
	 * meanwhile start a fresh spool. A claim left by a run that died mid-import
	 * goes first. Rows whose INSERT fails are dropped.
	 *
	 * @param string $rLog Spool path.
	 * @return int Rows inserted.
	 */
	private function importFile(string $rLog): int {
		$rClaimed = $rLog . '.import';
		$rCount = 0;

		if (file_exists($rClaimed)) {
			$rCount += $this->parseLog($rClaimed);
		}
		if (file_exists($rLog) && rename($rLog, $rClaimed)) {
			$rCount += $this->parseLog($rClaimed);
		}

		return $rCount;
	}

	/** Insert every row of a claimed spool, IMPORT_BATCH per INSERT, then delete it. */
	private function parseLog(string $rLog): int {
		$rRows = [];
		$rCount = 0;

		$rFP = fopen($rLog, 'r');
		while (($rRaw = fgets($rFP)) !== false) {
			$rLine = trim($rRaw);
			if (empty($rLine)) {
				continue;
			}
			$rLine = json_decode(base64_decode($rLine), true);
			if (!is_array($rLine)) {
				continue;
			}
			$rRows[] = $rLine;
			if (count($rRows) >= self::IMPORT_BATCH) {
				$rCount += $this->insertBatch($rRows);
				$rRows = [];
			}
		}
		fclose($rFP);

		if (!empty($rRows)) {
			$rCount += $this->insertBatch($rRows);
		}
		unlink($rLog);

		return $rCount;
	}

	/** Insert one batch into `lines_logs`. */
	private function insertBatch(array $rRows): int {
		$db = self::db();
		$rQuery = '';

		foreach ($rRows as $rLine) {
			$rQuery .= '(' . implode(',', array_map(static fn($rKey) => $db->escape((string) ($rLine[$rKey] ?? '')), self::KEYS)) . '),';
		}

		if (!$db->query('INSERT INTO `lines_logs` (`stream_id`,`user_id`,`client_status`,`query_string`,`user_agent`,`ip`,`extra_data`,`date`) VALUES ' . rtrim($rQuery, ',') . ';')) {
			return 0;
		}

		return count($rRows);
	}
}
