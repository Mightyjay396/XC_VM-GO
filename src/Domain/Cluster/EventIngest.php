<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\LogSink;
use XcVm\Core\Cluster\Redactor;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Stream\ContentSink;
use XcVm\Domain\Stream\RecordingFinalizer;
use XcVm\Domain\Stream\StreamProcess;
use XcVm\Domain\Stream\StreamRowMerge;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * MAIN's side of a node's `events` op (plan, sections 7 and 8, Phase 5): a
 * batch from one lane, numbered by the node from `first_useq`, applied in
 * order and at most once.
 *
 * ```text
 * p0  stream.state, stream.worker, stream.monitor,   gap-checked: first_useq must be
 *     recording.state, vod.analysis,                 useq_p0 + 1, else 409 {expected_useq}
 *     conn.upsert, conn.remove, conn.close, conn.limit
 *                                                    and the node rewinds
 * p1  log.<type>, skip                               high-water: numbers at or below
 *                                                    useq_p1 are skipped, gaps are fine
 * ```
 *
 * Every event is applied as the sending node: a stream's state goes to that
 * node's own `streams_servers` row and nothing else, log rows get its
 * server_id. A flow that is off refuses its events (dropped and counted), so
 * nothing is written twice. A batch and the new cursor commit together; a
 * node sends one batch per lane at a time.
 */
final class EventIngest {
	use DatabaseAware;

	/** Events per batch. */
	public const MAX_EVENTS = 5000;

	/** Lane of each event type, and the flow it needs. */
	private const TYPES = [
		'stream.state' => ['p0', NodeRegistry::FLOW_STREAMS],
		'stream.worker' => ['p0', NodeRegistry::FLOW_STREAMS],
		'stream.monitor' => ['p0', NodeRegistry::FLOW_STREAMS],
		'recording.state' => ['p0', NodeRegistry::FLOW_CONTENT],
		'conn.upsert' => ['p0', NodeRegistry::FLOW_CONNECTIONS],
		'conn.remove' => ['p0', NodeRegistry::FLOW_CONNECTIONS],
		'conn.close' => ['p0', NodeRegistry::FLOW_CONNECTIONS],
		'conn.limit' => ['p0', NodeRegistry::FLOW_CONNECTIONS],
		'vod.analysis' => ['p0', NodeRegistry::FLOW_CONTENT],
		'skip' => ['p1', NodeRegistry::FLOW_LOGS],
	];

	/** State columns that do not change what the stream cache holds. */
	private const CACHE_NEUTRAL = ['progress_info', 'delay_available_at'];

	/** @var (callable(int): mixed)|null */
	private static $rOnStreamChanged = null;

	/**
	 * What runs when an event changed a stream's routing state (tests; by
	 * default StreamProcess::updateStream, the cache signal the node used to
	 * write itself). Null restores the default.
	 *
	 * @param (callable(int): mixed)|null $rHook
	 */
	public static function onStreamChanged(?callable $rHook): void {
		self::$rOnStreamChanged = $rHook;
	}

	/**
	 * @param array<string, mixed> $rNode cluster_nodes row
	 * @param list<mixed> $rEvents
	 * @return array{ok: bool, useq: int, applied?: int, dropped?: int, expected_useq?: int}
	 */
	public static function ingest(array $rNode, string $rLane, int $rFirst, array $rEvents): array {
		$rServerID = (int) $rNode['server_id'];
		$rColumn = 'useq_' . $rLane;
		$rCursor = (int) $rNode[$rColumn];
		$rLast = $rFirst + count($rEvents) - 1;
		if ($rEvents === [] || $rLast <= $rCursor) {
			return ['ok' => true, 'useq' => $rCursor, 'applied' => 0, 'dropped' => 0]; // a repeat of what was applied
		}
		if ($rLane === 'p0' && $rFirst !== $rCursor + 1) {
			return ['ok' => false, 'useq' => $rCursor, 'expected_useq' => $rCursor + 1];
		}
		if ($rFirst <= $rCursor) {
			$rEvents = array_slice($rEvents, $rCursor - $rFirst + 1); // p1: the part already applied
		}
		$rDb = self::db();
		$rTx = method_exists($rDb, 'beginTransaction') && $rDb->beginTransaction();
		try {
			$rApplied = 0;
			$rDropped = 0;
			foreach ($rEvents as $rEvent) {
				if (self::apply($rNode, $rLane, $rEvent)) {
					$rApplied++;
				} else {
					$rDropped++;
				}
			}
			$rDb->query('UPDATE `cluster_nodes` SET `' . $rColumn . '` = ? WHERE `server_id` = ? AND `' . $rColumn . '` < ?;', $rLast, $rServerID, $rLast);
			if ($rTx) {
				$rDb->commit();
			}
		} catch (\Throwable $rE) {
			if ($rTx) {
				$rDb->rollback();
			}
			throw $rE;
		}
		return ['ok' => true, 'useq' => $rLast, 'applied' => $rApplied, 'dropped' => $rDropped];
	}

	/** Apply one event; false when it is refused (unknown, wrong lane, flow off, not the node's). */
	private static function apply(array $rNode, string $rLane, mixed $rEvent): bool {
		if (!is_array($rEvent) || !is_string($rEvent['type'] ?? null) || !is_array($rEvent['d'] ?? null)) {
			return false;
		}
		$rType = $rEvent['type'];
		$rData = $rEvent['d'];
		$rLog = str_starts_with($rType, 'log.') ? substr($rType, 4) : null;
		[$rWantLane, $rFlow] = $rLog !== null && isset(LogSink::TYPES[$rLog]) ? ['p1', NodeRegistry::FLOW_LOGS] : (self::TYPES[$rType] ?? [null, 0]);
		if ($rWantLane !== $rLane || ((int) $rNode['flows'] & $rFlow) === 0) {
			return false;
		}
		$rServerID = (int) $rNode['server_id'];
		if ($rLog !== null) {
			return self::logs($rServerID, $rLog, $rData);
		}
		switch ($rType) {
			case 'stream.state':
				return self::streamState($rServerID, $rData);
			case 'stream.worker':
				return self::streamWorker($rServerID, $rData);
			case 'stream.monitor':
				return self::streamMonitor($rServerID, $rData);
			case 'recording.state':
				return self::recordingState($rServerID, $rData);
			case 'vod.analysis':
				return self::vodAnalysis($rServerID, $rData);
			case 'conn.upsert':
				return is_array($rData['record'] ?? null) && ConnectionIngest::upsert($rServerID, $rData['record']);
			case 'conn.remove':
				return ConnectionIngest::remove($rServerID, (string) ($rData['uuid'] ?? ''));
			case 'conn.close':
				return ConnectionIngest::close($rServerID, (string) ($rData['uuid'] ?? ''));
			case 'conn.limit':
				return ConnectionLimits::queue($rServerID, $rData);
		}
		// skip: the node dropped logs past its cap.
		ClusterAudit::log('events.skip', $rServerID, ['count' => max(0, (int) ($rData['count'] ?? 0))], 'node');
		return true;
	}

	/** @param array<string, mixed> $rData {stream_id, server_id?, fields} or {ssid, fields} */
	private static function streamState(int $rServerID, array $rData): bool {
		$rFields = is_array($rData['fields'] ?? null) ? StreamRowMerge::eventFields($rData['fields']) : [];
		if ($rFields === []) {
			return false;
		}
		foreach ($rFields as $rValue) {
			if (!is_scalar($rValue) && $rValue !== null) {
				return false;
			}
		}
		if (isset($rData['ssid'])) {
			self::db()->query('SELECT `stream_id` FROM `streams_servers` WHERE `server_stream_id` = ? AND `server_id` = ?;', (int) $rData['ssid'], $rServerID);
			$rStreamID = self::db()->num_rows() > 0 ? (int) self::db()->get_row()['stream_id'] : 0;
			if ($rStreamID <= 0) {
				return false; // another node's row
			}
		} elseif (!isset($rData['stream_id']) || (isset($rData['server_id']) && (int) $rData['server_id'] !== $rServerID)) {
			return false; // another node's row
		} else {
			$rStreamID = (int) $rData['stream_id'];
		}
		if (!StreamRowMerge::mergeNode($rServerID, $rStreamID, $rFields, self::db())) {
			return false;
		}
		if (array_diff(array_keys($rFields), self::CACHE_NEUTRAL) !== []) {
			self::streamChanged($rStreamID);
		}
		return true;
	}

	/**
	 * The fanout's view of a stream it supervises on the node, as the agent
	 * followed it: the row follows as PHP's reconcile would set it
	 * (StreamProcess::supervisedRowUpdate), against MAIN's own copy. A row the
	 * panel has stopped is left alone; the node's reconcile releases it.
	 *
	 * @param array<string, mixed> $rData {stream_id, state}
	 */
	private static function streamMonitor(int $rServerID, array $rData): bool {
		$rStreamID = is_int($rData['stream_id'] ?? null) ? $rData['stream_id'] : 0;
		$rState = $rData['state'] ?? null;
		if ($rStreamID <= 0 || !is_array($rState) || empty($rState['supervised'])) {
			return false;
		}
		self::db()->query('SELECT `stream_id`, `pid`, `monitor_pid`, `stream_status`, `current_source`, `stream_started`, `stream_info`, `audio_codec`, `video_codec`, `resolution`, `bitrate`, `compatible` FROM `streams_servers` WHERE `server_id` = ? AND `stream_id` = ?;', $rServerID, $rStreamID);
		$rRow = self::db()->num_rows() > 0 ? self::db()->get_row() : null;
		if ($rRow === null || (is_null($rRow['monitor_pid']) && is_null($rRow['pid']) && intval($rRow['stream_status']) === 0)) {
			return false;
		}
		$rSet = StreamRowMerge::eventFields(StreamProcess::supervisedRowUpdate($rRow, $rState, (bool) SettingsManager::get('player_allow_hevc'), ClusterClock::now()));
		if ($rSet === []) {
			return true;
		}
		if (!StreamRowMerge::mergeNode($rServerID, $rStreamID, $rSet, self::db())) {
			return false;
		}
		self::streamChanged($rStreamID);
		return true;
	}

	/** @param array<string, mixed> $rData {stream_id, worker, pid} */
	private static function streamWorker(int $rServerID, array $rData): bool {
		$rWorker = $rData['worker'] ?? null;
		if (!in_array($rWorker, ContentSink::WORKERS, true) || !is_int($rData['stream_id'] ?? null) || !is_int($rData['pid'] ?? null)) {
			return false;
		}
		// Only for a stream whose worker runs on this node.
		self::db()->query('SELECT COUNT(*) AS `n` FROM `streams` WHERE `id` = ? AND `' . $rWorker . '_server_id` = ?;', $rData['stream_id'], $rServerID);
		if ((int) (self::db()->get_row()['n'] ?? 0) === 0) {
			return false;
		}
		self::db()->query('UPDATE `streams` SET `' . $rWorker . '_pid` = ? WHERE `id` = ?;', $rData['pid'], $rData['stream_id']);
		self::streamChanged($rData['stream_id']);
		return true;
	}

	/** @param array<string, mixed> $rData {id, status} */
	private static function recordingState(int $rServerID, array $rData): bool {
		$rID = is_int($rData['id'] ?? null) ? $rData['id'] : 0;
		$rStatus = $rData['status'] ?? null;
		if ($rID <= 0 || !in_array($rStatus, [1, 2, 3], true)) {
			return false;
		}
		if ($rStatus === RecordingFinalizer::DONE) {
			return RecordingFinalizer::finish($rID, $rServerID);
		}
		self::db()->query('SELECT COUNT(*) AS `n` FROM `recordings` WHERE `id` = ? AND `source_id` = ?;', $rID, $rServerID);
		if ((int) (self::db()->get_row()['n'] ?? 0) === 0) {
			return false; // another node's recording
		}
		self::db()->query('UPDATE `recordings` SET `status` = ? WHERE `id` = ?;', $rStatus, $rID);
		return true;
	}

	/** @param array<string, mixed> $rData {stream_id, props}: merged into MAIN's movie_properties */
	private static function vodAnalysis(int $rServerID, array $rData): bool {
		$rStreamID = is_int($rData['stream_id'] ?? null) ? $rData['stream_id'] : 0;
		$rProps = is_array($rData['props'] ?? null) ? array_intersect_key($rData['props'], array_flip(ContentSink::ANALYSIS_KEYS)) : [];
		if ($rStreamID <= 0 || $rProps === []) {
			return false;
		}
		// Only a movie this node holds.
		self::db()->query('SELECT `movie_properties` FROM `streams` t1 INNER JOIN `streams_servers` t2 ON t2.`stream_id` = t1.`id` AND t2.`server_id` = ? WHERE t1.`id` = ? AND t1.`type` IN (2, 5);', $rServerID, $rStreamID);
		if (self::db()->num_rows() === 0) {
			return false;
		}
		$rCurrent = json_decode((string) self::db()->get_row()['movie_properties'], true);
		$rMerged = array_replace(is_array($rCurrent) ? $rCurrent : [], $rProps);
		self::db()->query('UPDATE `streams` SET `movie_properties` = ? WHERE `id` = ?;', json_encode($rMerged, JSON_UNESCAPED_UNICODE), $rStreamID);
		self::streamChanged($rStreamID);
		return true;
	}

	private static function streamChanged(int $rStreamID): void {
		if (self::$rOnStreamChanged !== null) {
			(self::$rOnStreamChanged)($rStreamID);
			return;
		}
		StreamProcess::updateStream($rStreamID);
	}

	/** @param array<string, mixed> $rData {rows} */
	private static function logs(int $rServerID, string $rType, array $rData): bool {
		$rRows = $rData['rows'] ?? null;
		if (!is_array($rRows) || $rRows === [] || count($rRows) > LogSink::CHUNK) {
			return false;
		}
		$rColumns = array_flip(LogSink::TYPES[$rType][1]);
		$rClean = [];
		foreach ($rRows as $rRow) {
			if (!is_array($rRow)) {
				return false;
			}
			$rRow = array_filter(array_intersect_key($rRow, $rColumns), static fn($rValue) => is_scalar($rValue) || $rValue === null);
			if (isset($rColumns['server_id'])) {
				$rRow['server_id'] = $rServerID;
			}
			$rClean[] = Redactor::redactRow($rRow);
		}
		return LogSink::insert($rType, $rClean, self::db());
	}
}
