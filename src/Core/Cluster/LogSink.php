<?php

namespace XcVm\Core\Cluster;

use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * Where a node's log records go: client request logs, stream logs, stream and
 * panel errors, restream detections.
 *
 * The node-side crons (cron:lines_logs, cron:streams_logs, cron:errors) and
 * the cache handler collect records locally, then hand them here by type. The
 * legacy backend writes them into MAIN's database, one multi-row INSERT per
 * batch, which is what the callers used to build by hand. On a node whose
 * LOGS flow is on, they become `log.<type>` events for the agent instead
 * ({@see EventSpool}), redacted first ({@see Redactor}); MAIN's ingest writes
 * them with insert(). The SQL backend does not redact, as before.
 */
final class LogSink {
	/** Rows per INSERT: well under MySQL's placeholder limit for every type. */
	public const CHUNK = 1000;

	/**
	 * type => [table, columns, INSERT IGNORE].
	 *
	 * @var array<string, array{0: string, 1: list<string>, 2: bool}>
	 */
	public const TYPES = [
		'client'       => ['lines_logs', ['stream_id', 'user_id', 'client_status', 'query_string', 'user_agent', 'ip', 'extra_data', 'date'], false],
		'stream'       => ['streams_logs', ['stream_id', 'server_id', 'action', 'source', 'date'], false],
		'stream_error' => ['streams_errors', ['stream_id', 'server_id', 'date', 'error'], false],
		'panel_error'  => ['panel_logs', ['server_id', 'type', 'log_message', 'log_extra', 'line', 'date', 'file', 'env', 'version', 'unique'], true],
		'restream'     => ['detect_restream_logs', ['user_id', 'stream_id', 'ip', 'time'], false],
	];

	/** @var (callable(string, list<array<string, mixed>>, ?object): bool)|null */
	private static $rSink = null;

	/**
	 * Write records of one type. Each row maps the type's columns to values; a
	 * missing column is written as NULL.
	 *
	 * @param list<array<string, mixed>> $rRows
	 * @return bool True when every row was written.
	 */
	public static function write(string $rType, array $rRows, ?object $rDb = null): bool {
		if (!isset(self::TYPES[$rType])) {
			throw new \InvalidArgumentException('Unknown log type: ' . $rType);
		}
		if (empty($rRows)) {
			return true;
		}
		if (self::$rSink !== null) {
			return (bool) (self::$rSink)($rType, array_values($rRows), $rDb);
		}
		if (NodeFlows::on(NodeFlows::LOGS) && self::spool($rType, array_values($rRows))) {
			return true;
		}
		return self::insert($rType, $rRows, $rDb);
	}

	/**
	 * The SQL backend: MAIN's own writes, a legacy node's, and MAIN's ingest of
	 * a node's `log.*` events.
	 *
	 * @param list<array<string, mixed>> $rRows
	 */
	public static function insert(string $rType, array $rRows, ?object $rDb = null): bool {
		[$rTable, $rColumns, $rIgnore] = self::TYPES[$rType];
		$rDb ??= DatabaseFactory::get();
		$rOK = true;
		foreach (array_chunk(array_values($rRows), self::CHUNK) as $rChunk) {
			$rTuple = '(' . implode(',', array_fill(0, count($rColumns), '?')) . ')';
			$rParams = [];
			foreach ($rChunk as $rRow) {
				foreach ($rColumns as $rColumn) {
					$rParams[] = $rRow[$rColumn] ?? null;
				}
			}
			$rSql = 'INSERT ' . ($rIgnore ? 'IGNORE ' : '') . 'INTO `' . $rTable . '` (`' . implode('`,`', $rColumns) . '`) VALUES ' . implode(',', array_fill(0, count($rChunk), $rTuple)) . ';';
			$rOK = (bool) $rDb->query($rSql, ...$rParams) && $rOK;
		}
		return $rOK;
	}

	/**
	 * The cluster API backend (LOGS flow on): redacted `log.<type>` events on
	 * the agent's P1 lane, in chunks of CHUNK rows.
	 *
	 * @param list<array<string, mixed>> $rRows
	 */
	private static function spool(string $rType, array $rRows): bool {
		$rColumns = array_flip(self::TYPES[$rType][1]);
		$rEvents = [];
		foreach (array_chunk($rRows, self::CHUNK) as $rChunk) {
			$rRedacted = [];
			foreach ($rChunk as $rRow) {
				$rRedacted[] = Redactor::redactRow(array_intersect_key($rRow, $rColumns));
			}
			$rEvents[] = ['type' => 'log.' . $rType, 'd' => ['rows' => $rRedacted]];
		}
		return EventSpool::append('p1', $rEvents);
	}

	/** Replace the backend (tests). Null restores the default: events when LOGS is on, else SQL. */
	public static function useSink(?callable $rSink): void {
		self::$rSink = $rSink;
	}
}
