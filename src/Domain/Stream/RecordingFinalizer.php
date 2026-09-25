<?php

namespace XcVm\Domain\Stream;

use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * Recording Finalizer
 *
 * Turns a finished recording into a VOD, in two steps around the node's
 * conversion of the recorded .ts to `VOD_PATH/<stream_id>.mp4`:
 *
 *  1. create() makes the VOD row (with its bouquets) and records it as the
 *     recording's `created_id`, so the node knows the file name. Asked again
 *     for the same recording it returns the same VOD: one recording, one VOD.
 *  2. finish() attaches the VOD to the node that holds the file (pid 1,
 *     to_analyze 1, as a converted movie) and marks the recording done.
 *
 * A legacy node calls both in-process, on MAIN's database, as RecordCommand
 * always did. On a node whose CONTENT flow is on, create() runs on MAIN behind
 * the `recording_complete` op and finish() behind a `recording.state` event
 * with status 2, both only for a recording whose `source_id` is that node.
 *
 * @package XC_VM_Domain_Stream
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class RecordingFinalizer {
	use DatabaseAware;

	public const DONE = 2;

	/**
	 * The recording's VOD, created on first call.
	 *
	 * @param string|null $rIcon The node's local copy of the icon (`s:<sid>:/images/<md5>.jpg`), if it made one.
	 * @return int|null The VOD's stream id; null when the recording is unknown or not the node's.
	 */
	public static function create(int $rRecordingID, int $rServerID, ?string $rIcon): ?int {
		$rDb = self::db();
		$rDb->query('SELECT * FROM `recordings` WHERE `id` = ? AND `source_id` = ?;', $rRecordingID, $rServerID);
		if ($rDb->num_rows() <= 0) {
			return null;
		}
		$rRec = $rDb->get_row();
		if (!empty($rRec['created_id'])) {
			return (int) $rRec['created_id'];
		}
		if ($rIcon !== null && !preg_match('#^s:' . $rServerID . ':/images/[0-9a-f]{32}\.jpg$#', $rIcon)) {
			$rIcon = null; // only the node's own image store, as RecordCommand writes it
		}
		$rSeconds = intval($rRec['end'] - $rRec['start']);
		$rRow = self::defaults('streams');
		$rRow['type'] = 2;
		$rRow['stream_source'] = '[]';
		$rRow['target_container'] = 'mp4';
		$rRow['stream_display_name'] = $rRec['title'];
		$rRow['year'] = date('Y');
		$rRow['movie_properties'] = ['kinopoisk_url' => null, 'tmdb_id' => null, 'name' => $rRec['title'], 'o_name' => $rRec['title'], 'cover_big' => $rIcon, 'movie_image' => $rIcon, 'release_date' => date('Y-m-d', (int) $rRec['start']), 'episode_run_time' => intval($rSeconds / 60), 'youtube_trailer' => null, 'director' => '', 'actors' => '', 'cast' => '', 'description' => trim((string) $rRec['description']), 'plot' => trim((string) $rRec['description']), 'age' => '', 'mpaa_rating' => '', 'rating_count_kinopoisk' => 0, 'country' => '', 'genre' => '', 'backdrop_path' => [], 'duration_secs' => $rSeconds, 'duration' => sprintf('%02d:%02d:%02d', $rSeconds / 3600, ($rSeconds / 60) % 60, $rSeconds % 60), 'video' => [], 'audio' => [], 'bitrate' => 0, 'rating' => 0];
		$rRow['rating'] = 0;
		$rRow['read_native'] = 0;
		$rRow['movie_symlink'] = 0;
		$rRow['remove_subtitles'] = 0;
		$rRow['transcode_profile_id'] = 0;
		$rRow['order'] = self::nextOrder();
		$rRow['added'] = time();
		$rRow['category_id'] = '[' . implode(',', array_map('intval', json_decode((string) $rRec['category_id'], true) ?: [])) . ']';
		$rColumns = $rValues = [];
		foreach ($rRow as $rKey => $rValue) {
			$rColumns[] = '`' . strtolower((string) preg_replace('/[^a-z0-9_]+/i', '', $rKey)) . '`';
			$rValues[] = is_array($rValue) ? json_encode($rValue, JSON_UNESCAPED_UNICODE) : $rValue;
		}
		if (!$rDb->query('INSERT INTO `streams`(' . implode(',', $rColumns) . ') VALUES(' . implode(',', array_fill(0, count($rValues), '?')) . ');', ...$rValues)) {
			return null;
		}
		$rID = (int) $rDb->last_insert_id();
		$rDb->query('UPDATE `streams` SET `stream_source` = ? WHERE `id` = ?;', json_encode([VOD_PATH . $rID . '.mp4']), $rID);
		foreach (json_decode((string) $rRec['bouquets'], true) ?: [] as $rBouquet) {
			self::addToBouquet((int) $rBouquet, $rID);
		}
		$rDb->query('UPDATE `recordings` SET `created_id` = ? WHERE `id` = ?;', $rID, $rRecordingID);
		return $rID;
	}

	/** The node converted the file: attach the VOD to it and mark the recording done. */
	public static function finish(int $rRecordingID, int $rServerID): bool {
		$rDb = self::db();
		$rDb->query('SELECT `created_id` FROM `recordings` WHERE `id` = ? AND `source_id` = ?;', $rRecordingID, $rServerID);
		$rCreated = $rDb->num_rows() > 0 ? (int) $rDb->get_row()['created_id'] : 0;
		if ($rCreated <= 0) {
			return false;
		}
		$rDb->query('SELECT COUNT(*) AS `n` FROM `streams_servers` WHERE `stream_id` = ? AND `server_id` = ?;', $rCreated, $rServerID);
		if ((int) ($rDb->get_row()['n'] ?? 0) === 0) {
			$rDb->query('INSERT INTO `streams_servers`(`stream_id`, `server_id`, `parent_id`, `pid`, `to_analyze`) VALUES(?, ?, NULL, 1, 1);', $rCreated, $rServerID);
		}
		$rDb->query('UPDATE `recordings` SET `status` = ? WHERE `id` = ?;', self::DONE, $rRecordingID);
		return true;
	}

	/**
	 * Every column of a table with its default, as a REPLACE of the full row
	 * needs on MySQL (strict mode refuses a NOT NULL column left out).
	 *
	 * @return array<string, mixed>
	 */
	private static function defaults(string $rTable): array {
		$rOut = [];
		try {
			self::db()->query('SELECT `column_name`, `column_default`, `is_nullable`, `data_type` FROM `information_schema`.`columns` WHERE `table_schema` = (SELECT DATABASE()) AND `table_name` = ? ORDER BY `ordinal_position`;', $rTable);
			$rRows = self::db()->get_rows();
		} catch (\Throwable) {
			return $rOut; // no information_schema (tests on SQLite): explicit columns only
		}
		foreach ($rRows as $rRow) {
			$rRow = array_change_key_case($rRow, CASE_LOWER);
			$rDefault = $rRow['column_default'] === 'NULL' ? null : $rRow['column_default'];
			if ($rRow['is_nullable'] === 'NO' && !$rDefault) {
				$rDefault = in_array($rRow['data_type'], ['int', 'float', 'tinyint', 'double', 'decimal', 'smallint', 'mediumint', 'bigint', 'bit'], true) ? 0 : '';
			}
			$rOut[$rRow['column_name']] = $rDefault;
		}
		unset($rOut['id']);
		return $rOut;
	}

	private static function nextOrder(): int {
		self::db()->query('SELECT MAX(`order`) AS `order` FROM `streams`;');
		return self::db()->num_rows() === 1 ? intval(self::db()->get_row()['order']) + 1 : 0;
	}

	private static function addToBouquet(int $rBouquetID, int $rID): void {
		$rDb = self::db();
		$rDb->query('SELECT `bouquet_movies` FROM `bouquets` WHERE `id` = ?;', $rBouquetID);
		if ($rDb->num_rows() !== 1) {
			return;
		}
		$rMovies = json_decode((string) $rDb->get_row()['bouquet_movies'], true) ?: [];
		if (!in_array($rID, $rMovies)) {
			$rMovies[] = $rID;
		}
		$rDb->query('UPDATE `bouquets` SET `bouquet_movies` = ? WHERE `id` = ?;', '[' . implode(',', array_map('intval', $rMovies)) . ']', $rBouquetID);
	}
}
