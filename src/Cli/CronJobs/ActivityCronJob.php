<?php

namespace XcVm\Cli\CronJobs;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\CronTrait;
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

	/** Rows per INSERT, well under MariaDB's max_allowed_packet. */
	private const IMPORT_BATCH = 1000;

	/** Spool keys, in `lines_activity` column order. */
	private const COLUMNS = ['server_id', 'proxy_id', 'user_id', 'isp', 'external_device', 'stream_id', 'date_start', 'user_agent', 'user_ip', 'date_end', 'container', 'geoip_country_code', 'divergence', 'hmac_id', 'hmac_identifier'];

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
	 * goes first. Rows whose INSERT fails are dropped.
	 *
	 * @param string $rLogFile Spool path.
	 * @return int Rows inserted.
	 */
	private function importFile(string $rLogFile): int {
		$rClaimed = $rLogFile . '.import';
		$rCount = 0;

		if (file_exists($rClaimed)) {
			$rCount += $this->parseLog($rClaimed);
		}
		if (file_exists($rLogFile) && rename($rLogFile, $rClaimed)) {
			$rCount += $this->parseLog($rClaimed);
		}

		return $rCount;
	}

	/** Insert every valid row of a claimed spool, IMPORT_BATCH per INSERT, then delete it. */
	private function parseLog(string $rFile): int {
		$rRows = [];
		$rCount = 0;

		$rFP = fopen($rFile, 'r');
		while (($rRaw = fgets($rFP)) !== false) {
			$rLine = trim($rRaw);
			if (empty($rLine)) {
				continue;
			}
			$rLine = json_decode(base64_decode($rLine), true);
			if (!is_array($rLine) || empty($rLine['server_id']) || empty($rLine['user_id']) || empty($rLine['stream_id']) || empty($rLine['user_ip'])) {
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
		unlink($rFile);

		return $rCount;
	}

	/** Insert one batch into `lines_activity` and record it as each line's last activity. */
	private function insertBatch(array $rRows): int {
		$db = self::db();
		$rQuery = $rUpdateQuery = '';

		foreach ($rRows as $rLine) {
			$rQuery .= '(' . implode(',', array_map(static fn($rKey) => $db->escape((string) ($rLine[$rKey] ?? '')), self::COLUMNS)) . '),';
		}

		if (!$db->query('INSERT INTO `lines_activity` (`server_id`,`proxy_id`,`user_id`,`isp`,`external_device`,`stream_id`,`date_start`,`user_agent`,`user_ip`,`date_end`,`container`,`geoip_country_code`,`divergence`,`hmac_id`,`hmac_identifier`) VALUES ' . rtrim($rQuery, ','))) {
			return 0;
		}

		// A multi-row INSERT reports its first id; the rest follow consecutively.
		$rFirstID = (int) $db->last_insert_id();
		foreach ($rRows as $i => $rLine) {
			$rUpdateQuery .= '(' . intval($rLine['user_id']) . ',' . $db->escape((string) $rLine['user_ip']) . ',' . ($rFirstID + $i) . ',' . $db->escape(json_encode(['date_end' => $rLine['date_end'] ?? null, 'stream_id' => $rLine['stream_id']])) . '),';
		}
		$db->query('INSERT INTO `lines`(`id`,`last_ip`,`last_activity`,`last_activity_array`) VALUES ' . rtrim($rUpdateQuery, ',') . ' ON DUPLICATE KEY UPDATE `id`=VALUES(`id`), `last_ip`=VALUES(`last_ip`), `last_activity`=VALUES(`last_activity`), `last_activity_array`=VALUES(`last_activity_array`);');

		return count($rRows);
	}
}
