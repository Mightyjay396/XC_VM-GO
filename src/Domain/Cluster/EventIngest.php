<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\LogSink;
use XcVm\Core\Cluster\Redactor;
use XcVm\Domain\Stream\StreamRowMerge;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * MAIN's side of a node's `events` op (plan, sections 7 and 8, Phase 5): a
 * batch from one lane, numbered by the node from `first_useq`, applied in
 * order and at most once.
 *
 * ```text
 * p0  stream.state                  gap-checked: first_useq must be useq_p0 + 1,
 *                                   else 409 {expected_useq} and the node rewinds
 * p1  log.<type>, skip              high-water: numbers at or below useq_p1 are
 *                                   skipped, gaps are fine (dropped logs)
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
		'skip' => ['p1', NodeRegistry::FLOW_LOGS],
	];

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
		if ($rType === 'stream.state') {
			return self::streamState($rServerID, $rData);
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
			return StreamRowMerge::apply('`server_stream_id` = ? AND `server_id` = ?', $rFields, [(int) $rData['ssid'], $rServerID], self::db());
		}
		if (!isset($rData['stream_id']) || (isset($rData['server_id']) && (int) $rData['server_id'] !== $rServerID)) {
			return false; // another node's row
		}
		return StreamRowMerge::mergeNode($rServerID, (int) $rData['stream_id'], $rFields, self::db());
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
