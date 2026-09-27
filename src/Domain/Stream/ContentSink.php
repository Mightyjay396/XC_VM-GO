<?php

namespace XcVm\Domain\Stream;

use XcVm\Core\Cluster\EventSpool;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Core\Cluster\StreamRuntime;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * Content Sink
 *
 * The content a node reports about itself beyond `streams_servers`: its
 * recordings' progress, the pids of the archive and thumbnail workers it
 * runs, and the ffprobe results of the movies it holds. A legacy node writes
 * them into MAIN's database, as before. A node whose flow is on sends them
 * as P0 events through its agent instead ({@see EventSpool}), and MAIN applies
 * them only where the node is the owner (EventIngest):
 *
 * ```text
 * recording.state  {id, status}                 CONTENT  recordings.source_id = node
 * stream.worker    {stream_id, worker, pid}      STREAMS  streams.<worker>_server_id = node
 * vod.analysis     {stream_id, props}            CONTENT  the node holds the movie
 * ```
 *
 * While the node's STREAMS flow is on, the node also keeps the workers'
 * pids and its recordings' statuses in its own store ({@see StreamRuntime}),
 * which its readers take (the recording's status wins over the one its R2
 * record carries). A node in mode 2 whose agent takes no event keeps them
 * there alone: it has no database to write.
 *
 * @package XC_VM_Domain_Stream
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class ContentSink {
	/** Workers whose pid lives on the `streams` row. */
	public const WORKERS = ['tv_archive', 'vframes'];

	/** The `movie_properties` keys a node's analysis may set. */
	public const ANALYSIS_KEYS = ['duration_secs', 'duration', 'video', 'audio', 'subtitle', 'bitrate'];

	/** A recording's status (1 recording, 2 done, 3 failed). Status 2 goes through recordingDone(). */
	public static function recordingState(int $rRecordingID, int $rStatus, ?object $rDb = null): bool {
		$rKept = self::keepRecording($rRecordingID, $rStatus);
		if (NodeFlows::on(NodeFlows::CONTENT) && EventSpool::append('p0', [['type' => 'recording.state', 'd' => ['id' => $rRecordingID, 'status' => $rStatus]]])) {
			return true;
		}
		if ($rKept && NodeRole::refusesConnects()) {
			return false;
		}
		return (bool) ($rDb ?? DatabaseFactory::get())->query('UPDATE `recordings` SET `status` = ? WHERE `id` = ?;', $rStatus, $rRecordingID);
	}

	/** The node converted the recording into its VOD's file. */
	public static function recordingDone(int $rRecordingID, int $rServerID): bool {
		self::keepRecording($rRecordingID, RecordingFinalizer::DONE);
		if (NodeFlows::on(NodeFlows::CONTENT) && EventSpool::append('p0', [['type' => 'recording.state', 'd' => ['id' => $rRecordingID, 'status' => RecordingFinalizer::DONE]]])) {
			return true;
		}
		return RecordingFinalizer::finish($rRecordingID, $rServerID);
	}

	/** The pid of a stream's archive or thumbnail worker on this node. */
	public static function workerPid(int $rStreamID, string $rWorker, int $rPid, ?object $rDb = null): bool {
		if (!in_array($rWorker, self::WORKERS, true)) {
			throw new \InvalidArgumentException('Unknown worker: ' . $rWorker);
		}
		if (NodeFlows::on(NodeFlows::STREAMS)) {
			if (StreamRuntime::keep(['stream_id' => $rStreamID], [$rWorker . '_pid' => $rPid], static fn (): bool => EventSpool::append('p0', [['type' => 'stream.worker', 'd' => ['stream_id' => $rStreamID, 'worker' => $rWorker, 'pid' => $rPid]]]))) {
				return true;
			}
			if (NodeRole::refusesConnects()) {
				return false;
			}
		} else {
			StreamRuntime::lapse();
		}
		return (bool) ($rDb ?? DatabaseFactory::get())->query('UPDATE `streams` SET `' . $rWorker . '_pid` = ? WHERE `id` = ?', $rPid, $rStreamID);
	}

	/**
	 * The node's own record of a recording's status (STREAMS on), which its
	 * readers take over the R2 record's; with STREAMS off MAIN's row alone
	 * has it, so the node's goes.
	 */
	private static function keepRecording(int $rRecordingID, int $rStatus): bool {
		if (StreamRuntime::keeps()) {
			return StreamRuntime::recording($rRecordingID, $rStatus);
		}
		StreamRuntime::forgetRecording($rRecordingID);
		return false;
	}

	/**
	 * A movie's `movie_properties` after the node analysed its file. Legacy
	 * writes the whole document, as before; the event carries only the keys an
	 * analysis sets, and MAIN merges them into its own copy.
	 *
	 * @param array<string, mixed> $rProperties
	 */
	public static function movieProperties(int $rStreamID, array $rProperties, ?object $rDb = null): bool {
		if (NodeFlows::on(NodeFlows::CONTENT)) {
			$rProps = array_intersect_key($rProperties, array_flip(self::ANALYSIS_KEYS));
			if (EventSpool::append('p0', [['type' => 'vod.analysis', 'd' => ['stream_id' => $rStreamID, 'props' => (object) $rProps]]])) {
				return true;
			}
		}
		return (bool) ($rDb ?? DatabaseFactory::get())->query('UPDATE `streams` SET `movie_properties` = ? WHERE `id` = ?', json_encode($rProperties, JSON_UNESCAPED_UNICODE), $rStreamID);
	}
}
