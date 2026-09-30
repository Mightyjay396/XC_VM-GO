<?php

namespace XcVm\Cli\CronJobs;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\CronTrait;
use XcVm\Core\Cluster\LogSink;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * ActivityCronJob — activity cron job
 *
 * @package XC_VM_CLI_CronJobs
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ActivityCronJob implements CommandInterface {
	use DatabaseAware;
	use CronTrait;

	/** Most rows per INSERT. */
	private const IMPORT_BATCH = 1000;

	/** Most spool bytes per INSERT: oversized rows still keep it far below max_allowed_packet (16M). */
	private const IMPORT_BYTES = 4194304;

	public function getName(): string {
		return 'cron:activity';
	}

	public function getDescription(): string {
		return 'Cron: import user activity logs into DB';
	}

	public function execute(array $rArgs): int {
		if (!$this->assertRunAsXcVm()) {
			return 1;
		}

		$this->initCron('XC_VM[Activity]');
		$this->loadCron();

		return 0;
	}

	private function loadCron(): void {
		$this->importFile(LOGS_TMP_PATH . 'activity');
	}

	/**
	 * Import a spool, claimed by renaming it to <spool>.import so rows written
	 * meanwhile start a fresh spool. A claim left by a run that died mid-import
	 * goes first, and the batches that run had inserted are inserted again
	 * (at-least-once). A batch the sink refuses stays claimed, with the rows
	 * after it, for the next run (parseLog), and the spool waits behind it.
	 *
	 * @param string $rLogFile Spool path.
	 * @return int Rows inserted.
	 */
	private function importFile(string $rLogFile): int {
		$rClaimed = $rLogFile . '.import';
		$rCount = 0;

		if (file_exists($rClaimed)) {
			$rCount += $this->parseLog($rClaimed);
			if (file_exists($rClaimed)) {
				return $rCount; // refused again: claiming the spool now would overwrite it
			}
		}
		if (file_exists($rLogFile) && rename($rLogFile, $rClaimed)) {
			$rCount += $this->parseLog($rClaimed);
		}

		return $rCount;
	}

	/**
	 * Insert every valid row of a claimed spool, up to IMPORT_BATCH rows or
	 * IMPORT_BYTES per INSERT, then delete it. A batch the sink refuses (the
	 * database unavailable, a deadlock, the node's agent unreachable: with the
	 * panel's non-strict sql_mode bad data only truncates) is kept, with the
	 * rest of the file and not the batches already in, for the next run.
	 */
	private function parseLog(string $rFile): int {
		$rRows = [];
		$rCount = $rBytes = $rBatchAt = 0;

		$rFP = fopen($rFile, 'r');
		if ($rFP === false) {
			return 0;
		}
		while (($rRaw = fgets($rFP)) !== false) {
			$rLine = trim($rRaw);
			if (empty($rLine)) {
				continue;
			}
			$rLine = json_decode(base64_decode($rLine), true);
			// A line's viewer, or an HMAC identity's (which has no line).
			if (!is_array($rLine) || empty($rLine['server_id']) || (empty($rLine['user_id']) && empty($rLine['hmac_id'])) || empty($rLine['stream_id']) || empty($rLine['user_ip'])) {
				continue;
			}
			$rRows[] = $rLine;
			$rBytes += strlen($rRaw);
			if (count($rRows) >= self::IMPORT_BATCH || $rBytes >= self::IMPORT_BYTES) {
				if (!$this->insertBatch($rRows)) {
					$this->keepFrom($rFP, $rFile, $rBatchAt);
					return $rCount;
				}
				$rCount += count($rRows);
				$rRows = [];
				$rBytes = 0;
				$rBatchAt = (int) ftell($rFP);
			}
		}

		if ($rRows !== [] && !$this->insertBatch($rRows)) {
			$this->keepFrom($rFP, $rFile, $rBatchAt);
			return $rCount;
		}
		$rCount += count($rRows);
		fclose($rFP);
		unlink($rFile);

		return $rCount;
	}

	// ponytail: a batch refused for good (a strict sql_mode) is retried every
	// run and holds the import up; drop it after N refusals if that is ever seen.
	/**
	 * Leave $rFile holding only what follows $rFrom (the refused batch and
	 * the rows after it), and close $rFP.
	 *
	 * @param resource $rFP
	 */
	private function keepFrom($rFP, string $rFile, int $rFrom): void {
		$rRest = $rFile . '.rest';
		$rOut = fopen($rRest, 'w');
		if ($rOut !== false && fseek($rFP, $rFrom) === 0 && stream_copy_to_stream($rFP, $rOut) !== false && fclose($rOut)) {
			rename($rRest, $rFile);
		}
		fclose($rFP);
	}

	/**
	 * Hand one batch to the log sink: MAIN's database as before, or a
	 * `log.activity` event for the node's agent where its LOGS flow is on
	 * (LogSink, which also records each line's last activity).
	 *
	 * @param list<array<string, mixed>> $rRows
	 * @return bool Whether they were written.
	 */
	private function insertBatch(array $rRows): bool {
		return LogSink::write('activity', $rRows, self::db());
	}
}
