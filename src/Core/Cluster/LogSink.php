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
 * batch, which is what the callers used to build by hand. The cluster API
 * (Phase 5) replaces the backend with `log.*` events. Those events are
 * redacted first ({@see Redactor}); the SQL backend is not, so nothing
 * changes today.
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
	private static $rSink;

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
		if ($rRows === []) {
			return true;
		}
		if (self::$rSink !== null) {
			return (bool) (self::$rSink)($rType, array_values($rRows), $rDb);
		}
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

	/** Replace the backend (tests; later the cluster API). Null restores the SQL backend. */
	public static function useSink(?callable $rSink): void {
		self::$rSink = $rSink;
	}
}
