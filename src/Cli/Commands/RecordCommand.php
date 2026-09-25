<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Cluster\AgentClient;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Process\ProcessManager;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Domain\Stream\ContentSink;
use XcVm\Domain\Stream\RecordingFinalizer;
use XcVm\Infrastructure\Database\DatabaseAware;
use XcVm\Streaming\Codec\FfmpegPaths;

/**
 * RecordCommand — record command
 *
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class RecordCommand implements CommandInterface {
	use DatabaseAware;

	public function getName(): string {
		return 'record';
	}

	public function getDescription(): string {
		return 'Record — record stream to MP4';
	}

	public function execute(array $rArgs): int {
		if (posix_getpwuid(posix_geteuid())['name'] != 'xc_vm') {
			echo "Please run as XC_VM!\n";
			return 1;
		}

		if (empty($rArgs[0])) {
			return 0;
		}

		$recordingID = intval($rArgs[0]);

		register_shutdown_function(function () use ($recordingID) {
			if (file_exists(ARCHIVE_PATH . $recordingID . '_.record')) {
				unlink(ARCHIVE_PATH . $recordingID . '_.record');
			}
			self::db()->close_mysql();
		});

		$this->checkRunning($recordingID);
		set_time_limit(0);
		cli_set_process_title('Record[' . $recordingID . ']');

		$db = self::db();

		$db->query('SELECT * FROM `recordings` WHERE `id` = ?;', $recordingID);
		if ($db->num_rows() <= 0) {
			echo "Recording entry doesn't exist.\n";
			return 0;
		}

		$rFails = $totalBytes = 0;
		$isComplete = false;
		$recordingData = $db->get_row();

		if (($recordingData['start'] - 60 > time() || time() > $recordingData['end']) && !$recordingData['archive']) {
			echo "Programme is not currently airing.\n";
			ContentSink::recordingState($recordingID, 3, $db);
			@unlink(ARCHIVE_PATH . $recordingID . '.ts');
			return 0;
		}

		$rPID = (file_exists(STREAMS_PATH . $recordingData['stream_id'] . '_.pid') ? intval(file_get_contents(STREAMS_PATH . $recordingData['stream_id'] . '_.pid')) : 0);
		$rPlaylist = STREAMS_PATH . $recordingData['stream_id'] . '_.m3u8';

		if ($rPID <= 0 || !file_exists($rPlaylist)) {
			echo "Channel is not running.\n";
			$this->finishRecording($recordingID, false);
			return 0;
		}

		ContentSink::recordingState($recordingID, 1, $db);
		$db->close_mysql();

		while (ProcessManager::isStreamRunning($rPID, $recordingData['stream_id'])) {
			if ($recordingData['archive'] && time() < $recordingData['end'] + 65) {
				sleep(5);
				continue;
			}
			if ($recordingData['archive']) {
				$rDuration = intval(($recordingData['end'] - $recordingData['start']) / 60);
				$rSource = 'http://127.0.0.1:' . ServerRepository::getAll()[SERVER_ID]['http_broadcast_port'] . '/admin/timeshift?password=' . SettingsManager::get('live_streaming_pass') . '&stream=' . $recordingData['stream_id'] . '&start=' . $recordingData['start'] . '&duration=' . $rDuration . '&extension=ts';
			} else {
				$rSource = 'http://127.0.0.1:' . ServerRepository::getAll()[SERVER_ID]['http_broadcast_port'] . '/admin/live?password=' . SettingsManager::get('live_streaming_pass') . '&stream=' . $recordingData['stream_id'] . '&extension=ts';
			}
			$rFP = @fopen($rSource, 'r');
			if ($rFP) {
				echo "Recording...\n";
				if ($recordingData['archive']) {
					$rWriteFile = fopen(ARCHIVE_PATH . $recordingID . '.ts', 'w');
				} else {
					$rWriteFile = fopen(ARCHIVE_PATH . $recordingID . '.ts', 'a');
				}
				while (!feof($rFP)) {
					$rData = stream_get_line($rFP, 4096);
					if (!empty($rData)) {
						$totalBytes += strlen($rData);
						fwrite($rWriteFile, $rData);
						fflush($rWriteFile);
						$rFails = 0;
					}
					if ($recordingData['end'] <= time() && !$recordingData['archive']) {
						$isComplete = true;
						fclose($rWriteFile);
						break;
					}
				}
				fclose($rFP);
				if ($recordingData['archive']) {
					$isComplete = true;
				}
			}
			if ($isComplete) {
				break;
			}
			$rFails++;
			if ($rFails == 5) {
				if ($totalBytes >= 10485760) {
					$isComplete = true;
				}
				echo "Too many fails!\n";
				break;
			}
			echo "Broken pipe! Restarting...\n";
			sleep(1);
		}

		if (!$db->connected) {
			$db->db_connect();
		}

		if ($isComplete) {
			$this->processRecording($recordingID, $recordingData);
		} else {
			$this->finishRecording($recordingID, false);
		}

		return 0;
	}

	private function processRecording($recordingID, $recordingData): void {
		if (!file_exists(ARCHIVE_PATH . $recordingID . '.ts') || filesize(ARCHIVE_PATH . $recordingID . '.ts') <= 0) {
			echo "Recording size is 0 bytes.\n";
			$this->finishRecording($recordingID, false);
			return;
		}

		echo "Recording complete! Converting to MP4...\n";
		$rIcon = empty($recordingData['stream_icon']) ? null : $this->downloadAndSaveImage($recordingData['stream_icon']);
		// The VOD row comes first: its id names the file. On a node whose
		// CONTENT flow is on, MAIN creates it (recording_complete, through the
		// agent); otherwise it is created here, in MAIN's database, as before.
		if (NodeFlows::on(NodeFlows::CONTENT)) {
			$rReply = AgentClient::main('recording_complete', ['recording_id' => (int) $recordingID, 'stream_icon' => $rIcon]);
			$rInsertID = (int) ($rReply['stream_id'] ?? 0);
		} else {
			$rInsertID = (int) RecordingFinalizer::create((int) $recordingID, SERVER_ID, $rIcon);
		}
		if ($rInsertID <= 0) {
			echo "Failed to insert into database!\n";
			$this->finishRecording($recordingID, false);
			return;
		}

		shell_exec((FfmpegPaths::cpu() ?: FFMPEG_BIN_40) . " -i '" . ARCHIVE_PATH . $recordingID . '.ts' . "' -c:v copy -c:a copy '" . VOD_PATH . $rInsertID . '.mp4' . "'");
		@unlink(ARCHIVE_PATH . $recordingID . '.ts');

		if (!file_exists(VOD_PATH . $rInsertID . '.mp4')) {
			echo "Couldn't convert to MP4\n";
			$this->finishRecording($recordingID, false);
			return;
		}
		ContentSink::recordingDone((int) $recordingID, SERVER_ID);
	}

	private function finishRecording($recordingID, $success): void {
		$db = self::db();
		if (!$success) {
			echo "Recording incomplete!\n";
			ContentSink::recordingState((int) $recordingID, 3, $db);
			@unlink(ARCHIVE_PATH . $recordingID . '.ts');
		}
	}

	private function downloadAndSaveImage($rImage) {
		if (strlen($rImage) <= 0 || substr(strtolower($rImage), 0, 4) != 'http') {
			return null;
		}
		$rFilename = md5($rImage);
		$rExt = 'jpg';
		$rPrevPath = IMAGES_PATH . $rFilename . '.' . $rExt;
		if (file_exists($rPrevPath)) {
			return 's:' . SERVER_ID . ':/images/' . $rFilename . '.' . $rExt;
		}
		$rCurl = curl_init();
		curl_setopt($rCurl, CURLOPT_URL, $rImage);
		curl_setopt($rCurl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($rCurl, CURLOPT_CONNECTTIMEOUT, 5);
		curl_setopt($rCurl, CURLOPT_TIMEOUT, 5);
		$rData = curl_exec($rCurl);
		if (strlen($rData) <= 0) {
			return null;
		}
		$rPath = IMAGES_PATH . $rFilename . '.' . $rExt;
		file_put_contents($rPath, $rData);
		if (file_exists($rPath)) {
			return 's:' . SERVER_ID . ':/images/' . $rFilename . '.' . $rExt;
		}
		return null;
	}

	private function checkRunning($recordingID): void {
		clearstatcache(true);
		$rPID = null;
		if (file_exists(ARCHIVE_PATH . $recordingID . '_.record')) {
			$rPID = intval(file_get_contents(ARCHIVE_PATH . $recordingID . '_.record'));
		}
		if (empty($rPID)) {
			$rPIDs = [];
			exec("ps -ef | grep 'Record\\[" . intval($recordingID) . "\\]' | grep -v grep | awk '{print \$2}'", $rPIDs);
			foreach ($rPIDs as $rKillPID) {
				$rKillPID = intval(trim($rKillPID));
				if ($rKillPID > 0) {
					@posix_kill($rKillPID, 9);
				}
			}
		} else {
			if (file_exists('/proc/' . $rPID)) {
				$rCommand = trim(file_get_contents('/proc/' . $rPID . '/cmdline'));
				if ($rCommand == 'Record[' . $recordingID . ']' && 0 < $rPID) {
					posix_kill($rPID, 9);
				}
			}
		}
		file_put_contents(ARCHIVE_PATH . $recordingID . '_.record', getmypid());
	}
}
