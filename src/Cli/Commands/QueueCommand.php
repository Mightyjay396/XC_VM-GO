<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\DaemonTrait;
use XcVm\Core\Cluster\NodeLease;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Core\Cluster\QueueSink;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Process\ProcessManager;
use XcVm\Core\Process\ProcessRunner;
use XcVm\Domain\Stream\StreamProcess;
use XcVm\Streaming\Health\ProcessChecker;

/**
 * QueueCommand — queue command
 *
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class QueueCommand implements CommandInterface {
	use DaemonTrait;

	public function getName(): string {
		return 'queue';
	}

	public function getDescription(): string {
		return 'Daemon: encoding queue management (movie/channel)';
	}

	public function execute(array $rArgs): int {
		if (!$this->assertRunAsXcVm()) {
			return 1;
		}
		if (!$this->acquireDaemonLock('queue')) {
			return 0;
		}

		global $db;

		$this->setProcessTitle('XC_VM[Queue]');
		$this->killStaleProcesses('console.php queue');
		$this->killStaleProcesses('XC_VM\\[Queue\\]');
		$this->initDaemonMD5();
		// A node in mode 2 has no database of MAIN's: its queue is claimed and
		// updated over the cluster API, and a pass pings nothing.
		$rApi = NodeRole::refusesConnects();

		while ($rApi || ($db && $db->ping())) {
			if (!$this->refreshOrBreak()) {
				break;
			}

			$rPids = $rDelete = [];
			$this->movies($rPids, $rDelete);
			$this->channels($rPids, $rDelete);
			QueueSink::update($rPids, $rDelete);

			QueueSink::waitPoke($this->slots('queue_loop', 5));
		}

		$this->restartDaemon('queue');
		return 0;
	}

	/**
	 * One pass of the movie queue: drop the rows whose encoder is gone, start
	 * what fits in the free slots.
	 *
	 * @param array<int, int> $rPids   Rows started this pass, id => pid.
	 * @param list<int>       $rDelete Rows to drop.
	 */
	private function movies(array &$rPids, array &$rDelete): void {
		// Fenced: nothing starts, and the pending rows wait for the fence to
		// lift. A running encode finishes (it serves no viewer, and killing it
		// would lose the work and fail the movie).
		if (NodeLease::refusesEverything()) {
			return;
		}
		$rMax = $this->slots('max_encode_movies', 50);
		$rQueue = QueueSink::claim('movie', $rMax);
		if ($rQueue === null) {
			return;
		}

		$rFree = $rMax;
		foreach ($rQueue['running'] as $rRow) {
			if (ProcessManager::isRunning($rRow['pid'], 'ffmpeg') || ProcessManager::isRunning($rRow['pid'], PHP_BIN)) {
				$rFree--;
			} else {
				$rDelete[] = $rRow['id'];
			}
		}

		foreach ($rQueue['pending'] as $rRow) {
			if ($rFree-- <= 0) {
				break;
			}
			$rPID = StreamProcess::startMovie($rRow['stream_id']);
			if ($rPID) {
				$rPids[$rRow['id']] = $rPID;
			} else {
				$rDelete[] = $rRow['id'];
			}
		}
	}

	/**
	 * The same pass for created channels, which are built by `console.php
	 * created` and report their pid through a file.
	 *
	 * @param array<int, int> $rPids
	 * @param list<int>       $rDelete
	 */
	private function channels(array &$rPids, array &$rDelete): void {
		if (NodeLease::refusesEverything()) {
			return; // fenced, as movies()
		}
		$rMax = $this->slots('max_encode_cc', 1);
		$rQueue = QueueSink::claim('channel', $rMax);
		if ($rQueue === null) {
			return;
		}

		$rFree = $rMax;
		foreach ($rQueue['running'] as $rRow) {
			if (ProcessManager::isRunning($rRow['pid'], PHP_BIN)) {
				$rFree--;
			} else {
				$rDelete[] = $rRow['id'];
			}
		}

		foreach ($rQueue['pending'] as $rRow) {
			if ($rFree-- <= 0) {
				break;
			}
			$rPID = $this->startChannel($rRow['stream_id']);
			if ($rPID) {
				$rPids[$rRow['id']] = $rPID;
			} else {
				$rDelete[] = $rRow['id'];
			}
		}
	}

	/**
	 * Build one created channel. A build for this stream may already be running
	 * (the row was re-added while the previous launch is still encoding):
	 * killing it and starting over would reset the build to 0% every time —
	 * adopt the live pid instead.
	 */
	private function startChannel(int $rStreamID): int {
		$rCreateFile = CREATED_PATH . $rStreamID . '_.create';
		$rExistingPID = (file_exists($rCreateFile) ? intval(file_get_contents($rCreateFile)) : 0);
		if ($rExistingPID && ProcessChecker::checkPID($rExistingPID, 'XC_VMCreate[' . $rStreamID . ']')) {
			return $rExistingPID;
		}
		if (file_exists($rCreateFile)) {
			unlink($rCreateFile);
		}

		ProcessRunner::start([PHP_BIN, MAIN_HOME . 'console.php', 'created', (string) $rStreamID]);
		// console.php bootstrap takes well over the old 300ms window; give the
		// spawned process up to 5s to write its pid file.
		foreach (range(1, 20) as $i) {
			if (file_exists($rCreateFile)) {
				return intval(file_get_contents($rCreateFile));
			}
			usleep(250000);
		}
		return 0;
	}

	/** A positive setting, or the default the daemon has always used. */
	private function slots(string $rSetting, int $rDefault): int {
		$rValue = intval(SettingsManager::get($rSetting)); // lb-settings: max_encode_movies, max_encode_cc, queue_loop
		return 0 < $rValue ? $rValue : $rDefault;
	}
}
