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

	/** Most rows per INSERT. */
	private const IMPORT_BATCH = 1000;

	/** Most spool bytes per INSERT: oversized rows still keep it far below max_allowed_packet (16M). */
	private const IMPORT_BYTES = 4194304;

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
	 * goes first, and the batches that run had inserted are inserted again
	 * (at-least-once). Rows whose INSERT fails are dropped.
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

	/** Insert every valid row of a claimed spool, up to IMPORT_BATCH rows or IMPORT_BYTES per INSERT, then delete it. */
	private function parseLog(string $rFile): int {
		$rRows = [];
		$rCount = $rBytes = 0;

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
			if (!is_array($rLine) || empty($rLine['server_id']) || empty($rLine['user_id']) || empty($rLine['stream_id']) || empty($rLine['user_ip'])) {
				continue;
			}
			$rRows[] = $rLine;
			$rBytes += strlen($rRaw);
			if (count($rRows) >= self::IMPORT_BATCH || $rBytes >= self::IMPORT_BYTES) {
				$rCount += $this->insertBatch($rRows);
				$rRows = [];
				$rBytes = 0;
			}
		}
		fclose($rFP);

		if ($rRows !== []) {
			$rCount += $this->insertBatch($rRows);
		}
		unlink($rFile);

		return $rCount;
	}

	/** Insert one batch into `lines_activity` and record it as each line's last activity. */
	private function insertBatch(array $rRows): int {
		$db = self::db();
		$rQuery = $rIPs = $rActivityIDs = $rArrays = '';
		$rLast = [];

		foreach ($rRows as $rLine) {
			$rQuery .= '(' . implode(',', array_map(static fn($rKey) => $db->escape((string) ($rLine[$rKey] ?? '')), self::COLUMNS)) . '),';
		}

		if (!$db->query('INSERT INTO `lines_activity` (`server_id`,`proxy_id`,`user_id`,`isp`,`external_device`,`stream_id`,`date_start`,`user_agent`,`user_ip`,`date_end`,`container`,`geoip_country_code`,`divergence`,`hmac_id`,`hmac_identifier`) VALUES ' . rtrim($rQuery, ','))) {
			return 0;
		}

		// A multi-row INSERT reports its first id; the rest follow consecutively.
		// Each line gets its latest row, in id order so concurrent nodes lock
		// alike. An UPDATE cannot recreate a deleted line, and keeping `updated`
		// spares cache_engine a rebuild (the line cache holds none of these).
		$rFirstID = (int) $db->last_insert_id();
		foreach ($rRows as $i => $rLine) {
			$rLast[intval($rLine['user_id'])] = [$rFirstID + $i, $rLine];
		}
		ksort($rLast);
		foreach ($rLast as $rUserID => [$rActivityID, $rLine]) {
			$rIPs .= ' WHEN ' . $rUserID . ' THEN ' . $db->escape((string) $rLine['user_ip']);
			$rActivityIDs .= ' WHEN ' . $rUserID . ' THEN ' . $rActivityID;
			$rArrays .= ' WHEN ' . $rUserID . ' THEN ' . $db->escape(json_encode(['date_end' => $rLine['date_end'] ?? null, 'stream_id' => $rLine['stream_id']]));
		}
		$db->query('UPDATE `lines` SET `last_ip` = CASE `id`' . $rIPs . ' END, `last_activity` = CASE `id`' . $rActivityIDs . ' END, `last_activity_array` = CASE `id`' . $rArrays . ' END, `updated` = `updated` WHERE `id` IN (' . implode(',', array_keys($rLast)) . ');');

		return count($rRows);
	}
}
