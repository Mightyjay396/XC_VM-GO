<?php

namespace XcVm\Cli\CronJobs;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\CronTrait;
use XcVm\Core\Cluster\NodeLease;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Diagnostics\DiagnosticsService;
use XcVm\Core\Events\EventDispatcher;
use XcVm\Core\Events\Vod\MediaAnalyzedEvent;
use XcVm\Domain\Stream\ContentSink;
use XcVm\Domain\Stream\NodeStreams;
use XcVm\Domain\Stream\StreamProcess;
use XcVm\Domain\Stream\StreamSource; // QC-VOD-SELFHEAL
use XcVm\Core\Cluster\StreamRuntime; // QC-VOD-SELFHEAL
use XcVm\Domain\Stream\StreamSorter;
use XcVm\Domain\Stream\StreamStateWriter;
use XcVm\Streaming\Codec\FFmpegCommand;
use XcVm\Streaming\Codec\FFprobeRunner;
use XcVm\Streaming\Health\ProcessChecker;

/**
 * VodCronJob — vod cron job
 *
 * @package XC_VM_CLI_CronJobs
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class VodCronJob implements CommandInterface {
	use CronTrait;

	public function getName(): string {
		return 'cron:vod';
	}

	public function getDescription(): string {
		return 'Cron: check VOD/channels, start recordings, analyze media';
	}

	public function execute(array $rArgs): int {
		if (!$this->assertRunAsXcVm()) {
			return 1;
		}

		$this->initCron('XC_VM[VOD]');
		$this->loadCron();

		return 0;
	}

	// QC-VOD-SELFHEAL: every HEAL_EVERY s, at most HEAL_BATCH items, each once per HEAL_COOLDOWN.
	private const HEAL_EVERY = 900;
	private const HEAL_BATCH = 100;
	private const HEAL_RELINKS = 500;
	private const HEAL_COOLDOWN = 21600;

	/**
	 * A movie or episode whose link dangles (its file was renamed or replaced)
	 * or whose analysis failed (file mid-copy, ffprobe timeout on a busy disk)
	 * stayed on the offline video for good. When its source file is here it
	 * starts again: linked to the source and analysed by this cron.
	 */
	private function healVod(): void {
		global $db;
		$rStateFile = CACHE_TMP_PATH . 'vod_selfheal.json';
		$rState = json_decode((string) @file_get_contents($rStateFile), true);
		$rState = is_array($rState) ? $rState : [];
		$rNow = time();
		if ($rNow - intval($rState['run'] ?? 0) < self::HEAL_EVERY) {
			return;
		}
		$rTried = array_filter((array) ($rState['tried'] ?? []), fn($rAt) => $rNow - intval($rAt) < self::HEAL_COOLDOWN);
		file_put_contents($rStateFile, json_encode(['run' => $rNow, 'tried' => $rTried]));
		// Links whose file is gone first (renames: cheap, urgent), then failed analyses (this node's store, else MAIN's rows).
		$rCandidates = $rDangling = $rFailed = [];
		foreach (scandir(VOD_PATH) ?: [] as $rName) {
			if (preg_match('/^(\d+)\.[A-Za-z0-9]+$/', $rName, $rMatch) && is_link(VOD_PATH . $rName) && !file_exists(VOD_PATH . $rName)) {
				$rCandidates[intval($rMatch[1])] = 'link';
				$rDangling[intval($rMatch[1])][] = $rName;
			}
		}
		if (StreamSource::local()) {
			foreach (StreamRuntime::ids() as $rID) {
				$rFields = StreamRuntime::get($rID);
				if (intval($rFields['stream_status'] ?? 0) === 1 && intval($rFields['to_analyze'] ?? 0) !== 1 && intval($rFields['pid'] ?? 0) > 0) {
					$rCandidates[$rID] ??= 'failed';
					$rFailed[$rID] = true;
				}
			}
		} else {
			$db->query('SELECT `stream_id` FROM `streams_servers` WHERE `server_id` = ? AND `stream_status` = 1 AND `to_analyze` = 0 AND `pid` > 0;', SERVER_ID);
			foreach ($db->get_rows() as $rRow) {
				$rCandidates[intval($rRow['stream_id'])] ??= 'failed';
				$rFailed[intval($rRow['stream_id'])] = true;
			}
		}
		$rCount = ['link' => 0, 'failed' => 0];
		$rCaps = ['link' => self::HEAL_RELINKS, 'failed' => self::HEAL_BATCH];
		foreach ($rCandidates as $rID => $rKind) {
			if ($rCount[$rKind] >= $rCaps[$rKind] || ($rKind === 'failed' && isset($rTried[$rID]))) {
				continue;
			}
			$rStream = StreamSource::streamRow($rID, false, $db);
			$rSource = (string) ((json_decode((string) ($rStream['stream_source'] ?? ''), true) ?: [''])[0] ?? '');
			$rPath = preg_match('/^s:\d+:(\/.+)$/s', $rSource, $rMatch) ? $rMatch[1] : '';
			$rContainer = strtolower((string) ($rStream['target_container'] ?? ''));
			if ($rStream === null || intval($rStream['movie_symlink'] ?? 0) !== 1) {
				continue;
			}
			if ($rKind === 'link') {
				// A link left with an old container (the file was upgraded mkv -> mp4) serves nothing: removed.
				$rCurrent = false;
				foreach ($rDangling[$rID] as $rName) {
					if (strtolower(pathinfo($rName, PATHINFO_EXTENSION)) === $rContainer) {
						$rCurrent = true;
					} else {
						@unlink(VOD_PATH . $rName);
					}
				}
				if (!$rCurrent && !isset($rFailed[$rID])) {
					continue;
				}
				if (!$rCurrent) {
					$rKind = 'failed';
					if ($rCount['failed'] >= self::HEAL_BATCH || isset($rTried[$rID])) {
						continue;
					}
				}
			}
			if (!is_file($rPath)) {
				// Renamed/replaced by Radarr/Sonarr: link the new file now; MAIN's watch scan moves the source.
				$rMoved = $rPath !== '' ? self::movedFile($rPath) : null;
				if ($rMoved === null || strtolower(pathinfo($rMoved, PATHINFO_EXTENSION)) !== $rContainer) {
					continue;
				}
				$rTried[$rID] = $rNow;
				$rLink = VOD_PATH . $rID . '.' . $rContainer;
				@unlink($rLink . '.heal');
				if (@symlink($rMoved, $rLink . '.heal') && @rename($rLink . '.heal', $rLink)) {
					StreamStateWriter::update($rID, intval(SERVER_ID), ['to_analyze' => 1, 'stream_status' => 0], $db);
					StreamProcess::updateStream($rID);
					$rCount[$rKind]++;
				}
				continue;
			}
			if (strtolower(pathinfo($rPath, PATHINFO_EXTENSION)) !== $rContainer) {
				continue;
			}
			$rTried[$rID] = $rNow;
			if (StreamProcess::startMovie($rID) !== false) {
				$rCount[$rKind]++;
			}
		}
		file_put_contents($rStateFile, json_encode(['run' => $rNow, 'tried' => $rTried]));
		if ($rCount['link'] + $rCount['failed'] > 0) {
			echo '[*] Self-heal: relinked ' . $rCount['link'] . ', restarted ' . $rCount['failed'] . "\n";
		}
	}

	/** The one file of $rPath's folder with its release name up to the quality ("Show - S01E02 - ", "Title (Year) ["), else null. */
	private static function movedFile(string $rPath): ?string {
		$rDir = dirname($rPath);
		$rBase = basename($rPath);
		$rParts = explode(' - ', $rBase);
		if (count($rParts) >= 3 && preg_match('/^S\d+E\d+/i', $rParts[1])) {
			$rKey = $rParts[0] . ' - ' . $rParts[1] . ' - ';
		} else {
			$rPos = strpos($rBase, ' [');
			$rKey = $rPos !== false ? substr($rBase, 0, $rPos + 2) : pathinfo($rBase, PATHINFO_FILENAME) . '.';
		}
		$rFound = [];
		foreach (is_dir($rDir) ? (scandir($rDir) ?: []) : [] as $rName) {
			if (strpos($rName, $rKey) === 0 && preg_match('/\.(mkv|mp4|avi|m4v|ts|mov|wmv|mpg|flv)$/i', $rName) && is_file($rDir . '/' . $rName)) {
				$rFound[] = $rDir . '/' . $rName;
			}
		}
		return count($rFound) === 1 ? $rFound[0] : null;
	}

	private function loadCron(): void {
		global $db;

		// QC-VOD-SELFHEAL: dangling links and failed analyses start again, a batch at a time.
		$this->healVod();

		// This node's streams, from its replica and its own store where they
		// answer (NodeStreams), else from MAIN's database as before.
		$rStreams = NodeStreams::createdChannels($db);
		if (count($rStreams) > 0) {
			foreach ($rStreams as $rStream) {
				echo "\n\n" . '[*] Checking Stream ' . $rStream['stream_display_name'] . "\n";
				$rCreateFile = CREATED_PATH . $rStream['id'] . '_.create';
				$rPID = is_file($rCreateFile) ? intval(file_get_contents($rCreateFile)) : 0;
				if ($rPID && ProcessChecker::checkPID($rPID, 'XC_VMCreate[' . intval($rStream['id']) . ']')) {
					echo "\t" . 'Build Is Still Going!' . "\n";
				} else {
					$rSourcesLeft = array_diff(json_decode($rStream['stream_source'], true), json_decode($rStream['cchannel_rsources'], true));
					if (count($rSourcesLeft) > 0) {
						echo "\t" . 'Needs Updating!' . "\n";
						// The queue is MAIN's table: in mode 2 QueueSink asks MAIN.
						StreamProcess::queueChannel($rStream['id']);
					} else {
						if (file_exists(CREATED_PATH . $rStream['id'] . '_.info')) {
							$rCCInfo = file_get_contents(CREATED_PATH . $rStream['id'] . '_.info');
							StreamStateWriter::update(intval($rStream['id']), intval(SERVER_ID), ['cc_info' => $rCCInfo], $db);
							unlink(CREATED_PATH . $rStream['id'] . '_.info');
						}
						echo "\t" . 'Build Finished' . "\n";
					}
				}
			}
		}

		// Fenced: no recording starts (RecordCommand refuses too).
		$rRecordings = NodeLease::refusesEverything() ? [] : NodeStreams::recordingsDue($db);
		if (count($rRecordings) > 0) {
			foreach ($rRecordings as $rRow) {
				echo 'Start recording ID: ' . intval($rRow['id']) . "\n";
				shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php record ' . intval($rRow['id']) . ' > /dev/null 2>/dev/null &');
			}
		}

		exec("ps ax | grep 'ffmpeg' | awk '{print \$1}'", $rPIDs);

		$rCount = NodeStreams::analysisCount($db);

		if ($rCount > 0) {
			if ($rCount <= 1000) {
				$rSteps = [0, $rCount];
			} else {
				$rSteps = range(0, $rCount, 1000);
			}
			if ($rSteps === []) {
				$rSteps = [0];
			}

			foreach ($rSteps as $rStep) {
				$rRows = NodeStreams::analysis(intval($rStep), $db);
				if (count($rRows) <= 0) {
					continue;
				}

				foreach ($rRows as $rRow) {
					echo '[*] Checking Movie ' . $rRow['stream_display_name'] . ' ' . "\t\t" . '---> ';
					if (in_array($rRow['pid'], $rPIDs)) {
						echo 'ENCODING...' . "\n";
					} else {
						$rMoviePath = VOD_PATH . intval($rRow['stream_id']) . '.' . escapeshellcmd($rRow['target_container']);
						if ($rFFProbee = FFprobeRunner::probeStream($rMoviePath)) {
							if (!isset($rFFProbee['codecs']['video']) || !is_array($rFFProbee['codecs']['video'])) {
								// ffprobe opened the file but found no usable video stream
								// (e.g. a truncated/placeholder upload): parseFFProbe returns
								// '' for the missing codec, and the VALID branch below treats
								// it as an array ($rFFProbee['codecs']['video']['codec_name']),
								// which is a TypeError on PHP 8 that aborts the whole analyzer
								// run and leaves every remaining movie stuck in `to_analyze = 1`
								// (yellow) forever. Treat such a file as broken instead.
								StreamStateWriter::updateRow(intval($rRow['server_stream_id']), ['to_analyze' => 0, 'stream_status' => 1], $db);
								echo 'BROKEN (no video stream)' . "\n";
								StreamProcess::updateStream($rRow['stream_id']);
								continue;
							}
							// ffprobe (especially over network/rclone mounts) can still
							// return a partial result for an odd file — e.g. a video stream
							// but no audio, where parseFFProbe stores '' (a string) instead
							// of an array. The VALID branch dereferences these as arrays
							// ($rFFProbee['codecs']['audio']['codec_name']), a TypeError on
							// PHP 8 that aborts the whole run. Normalise to arrays.
							foreach (['video', 'audio'] as $rCodecKind) {
								if (!is_array($rFFProbee['codecs'][$rCodecKind] ?? null)) {
									$rFFProbee['codecs'][$rCodecKind] = [];
								}
							}
							$rDuration = (isset($rFFProbee['duration']) ? $rFFProbee['duration'] : 0);
							sscanf($rDuration, '%d:%d:%d', $rHours, $rMinutes, $rSeconds);
							$rSeconds = (isset($rSeconds) ? $rHours * 3600 + $rMinutes * 60 + $rSeconds : $rHours * 60 + $rMinutes);
							$rSize = filesize($rMoviePath);
							// Guard against a zero/unknown duration (ffprobe reports
							// 'N/A' for truncated or duration-less files): dividing by
							// it throws DivisionByZeroError on PHP 8, which aborts the
							// whole analyzer run and leaves every remaining movie stuck
							// in `to_analyze = 1` (yellow) forever.
							$rBitrate = ($rSeconds > 0 ? round(($rSize * 0.008) / $rSeconds) : 0);
							$rMovieProperties = json_decode($rRow['movie_properties'], true);
							if (!is_array($rMovieProperties)) {
								$rMovieProperties = [];
							}
							if (!isset($rMovieProperties['duration_secs']) || $rSeconds != $rMovieProperties['duration_secs']) {
								$rMovieProperties['duration_secs'] = $rSeconds;
								$rMovieProperties['duration'] = $rDuration;
							}
							if (!isset($rMovieProperties['video']) || ($rFFProbee['codecs']['video']['codec_name'] ?? null) != $rMovieProperties['video']) {
								$rMovieProperties['video'] = $rFFProbee['codecs']['video'];
							}
							if (!isset($rMovieProperties['audio']) || ($rFFProbee['codecs']['audio']['codec_name'] ?? null) != $rMovieProperties['audio']) {
								$rMovieProperties['audio'] = $rFFProbee['codecs']['audio'];
							}
							if (SettingsManager::get('extract_subtitles')) {
								if (!isset($rMovieProperties['subtitle']) || ($rFFProbee['codecs']['subtitle']['codec_name'] ?? null) != $rMovieProperties['subtitle']) {
									$rMovieProperties['subtitle'] = $rFFProbee['codecs']['subtitle'];
								}
							}
							if (!isset($rMovieProperties['bitrate']) || $rBitrate != $rMovieProperties['bitrate']) {
								if (0 < $rBitrate) {
									$rMovieProperties['bitrate'] = $rBitrate;
								} else {
									$rBitrate = $rMovieProperties['bitrate'];
								}
							}
							if (isset($rFFProbee['codecs']['subtitle']) && SettingsManager::get('extract_subtitles')) {
								$i = 0;
								foreach ($rFFProbee['codecs']['subtitle'] as $rSubtitle) {
									FFmpegCommand::extractSubtitle($rRow['stream_id'], $rMoviePath, $i);
									$i++;
								}
							}
							$rCompatible = intval(DiagnosticsService::checkCompatibility($rFFProbee, SettingsManager::get('player_allow_hevc')));
							$rAudioCodec = ($rFFProbee['codecs']['audio']['codec_name'] ?? null) ?: null; // a file without an audio track has none
							$rVideoCodec = ($rFFProbee['codecs']['video']['codec_name'] ?? null) ?: null;
							$rResolution = ($rFFProbee['codecs']['video']['height'] ?? null) ?: null;
							if ($rResolution) {
								$rResolution = StreamSorter::getNearest([240, 360, 480, 576, 720, 1080, 1440, 2160], $rResolution);
							}
							if (!ContentSink::movieProperties((int) $rRow['stream_id'], $rMovieProperties, $db) && NodeRole::refusesConnects()) {
								// Mode 2 and the agent took no event: still due, analysed again once it is back.
								echo 'DEFERRED' . "\n";
								continue;
							}
							StreamStateWriter::updateRow(intval($rRow['server_stream_id']), ['bitrate' => $rBitrate, 'to_analyze' => 0, 'stream_status' => 0, 'stream_info' => json_encode($rFFProbee, JSON_UNESCAPED_UNICODE), 'audio_codec' => $rAudioCodec, 'video_codec' => $rVideoCodec, 'resolution' => $rResolution, 'compatible' => $rCompatible], $db);
							echo 'VALID' . "\n";
							EventDispatcher::dispatch(new MediaAnalyzedEvent((int) $rRow['stream_id'], (int) $rRow['type']));
						} else {
							StreamStateWriter::updateRow(intval($rRow['server_stream_id']), ['to_analyze' => 0, 'stream_status' => 1], $db);
							echo 'BROKEN' . "\n";
						}
						StreamProcess::updateStream($rRow['stream_id']);
					}
				}
			}
		}
	}
}
