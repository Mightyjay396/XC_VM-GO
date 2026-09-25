<?php

namespace XcVm\Domain\Stream;

use XcVm\Core\Cluster\EventSpool;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\Redactor;

/**
 * Stream State Writer
 *
 * The one way a node records the runtime state of a stream it runs: process
 * ids, status, probe results, progress. Everything else in `streams_servers`
 * (what should run where, parents, on-demand, …) is desired state and belongs
 * to MAIN. Callers used to UPDATE the row directly from ~30 places; they now
 * pass the fields to update() or updateRow(), which refuse any column outside
 * STATE_FIELDS, and the writer applies them through a sink.
 *
 * On a legacy node the writer merges into the row in MAIN's database through
 * StreamRowMerge, as before. On a node whose STREAMS flow is on it sends a
 * `stream.state` event through the agent instead ({@see EventSpool}), which
 * MAIN merges into that node's own row (EventIngest).
 *
 * @package XC_VM_Domain_Stream
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

final class StreamStateWriter {
	/** Columns of `streams_servers` a node may write about its own streams. */
	public const STATE_FIELDS = [
		'pid', 'monitor_pid', 'delay_pid',
		'stream_status', 'stream_started', 'to_analyze', 'delay_available_at',
		'stream_info', 'progress_info', 'current_source',
		'bitrate', 'audio_codec', 'video_codec', 'resolution', 'compatible',
		'cc_info', 'cchannel_rsources', 'ondemand_check',
	];

	/** @var (callable(string, array<string, mixed>, list<mixed>, ?object): bool)|null */
	private static $rSink = null;

	/**
	 * Update this node's row for a stream, keyed by (stream_id, server_id).
	 *
	 * @param array<string, mixed> $rFields column => value; null writes NULL.
	 * @param object|null $rDb The caller's DatabaseHandler, if it holds its own.
	 */
	public static function update(int $rStreamID, int $rServerID, array $rFields, ?object $rDb = null): bool {
		return self::write('`stream_id` = ? AND `server_id` = ?', $rFields, [$rStreamID, $rServerID], $rDb, ['stream_id' => $rStreamID, 'server_id' => $rServerID]);
	}

	/**
	 * Update a row by its `server_stream_id`.
	 *
	 * @param array<string, mixed> $rFields column => value; null writes NULL.
	 */
	public static function updateRow(int $rServerStreamID, array $rFields, ?object $rDb = null): bool {
		return self::write('`server_stream_id` = ?', $rFields, [$rServerStreamID], $rDb, ['ssid' => $rServerStreamID]);
	}

	/**
	 * Replace the sink (tests). It receives the WHERE
	 * clause, the fields and the WHERE values. Null restores the default
	 * (events when STREAMS is on, else SQL).
	 *
	 * @param (callable(string, array<string, mixed>, list<mixed>, ?object): bool)|null $rSink
	 */
	public static function useSink(?callable $rSink): void {
		self::$rSink = $rSink;
	}

	/**
	 * The cluster API backend (STREAMS flow on): a `stream.state` event on the
	 * agent's P0 lane. MAIN merges it into the sender's own row only.
	 *
	 * @param array<string, int> $rKey
	 * @param array<string, mixed> $rFields
	 */
	private static function spool(array $rKey, array $rFields): bool {
		if (isset($rFields['current_source']) && is_string($rFields['current_source'])) {
			$rFields['current_source'] = Redactor::redact($rFields['current_source']);
		}
		return EventSpool::append('p0', [['type' => 'stream.state', 'd' => $rKey + ['fields' => (object) $rFields]]]);
	}

	/**
	 * @param array<string, mixed> $rFields
	 * @param list<mixed> $rWhereValues
	 * @param array<string, int> $rKey how a `stream.state` event names the row
	 */
	private static function write(string $rWhere, array $rFields, array $rWhereValues, ?object $rDb, array $rKey): bool {
		if (empty($rFields)) {
			return true;
		}
		$rUnknown = array_diff(array_keys($rFields), self::STATE_FIELDS);
		if (!empty($rUnknown)) {
			throw new \InvalidArgumentException('Not stream runtime state: ' . implode(', ', $rUnknown));
		}
		if (self::$rSink !== null) {
			return (bool) (self::$rSink)($rWhere, $rFields, $rWhereValues, $rDb);
		}
		if (NodeFlows::on(NodeFlows::STREAMS) && self::spool($rKey, $rFields)) {
			return true;
		}
		// Legacy backend: merge into the row in MAIN's database directly.
		return StreamRowMerge::apply($rWhere, $rFields, $rWhereValues, $rDb);
	}
}
