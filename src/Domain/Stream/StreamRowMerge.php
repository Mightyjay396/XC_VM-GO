<?php

namespace XcVm\Domain\Stream;

use XcVm\Core\Cluster\Redactor;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * Stream Row Merge
 *
 * MAIN's side of a node's stream runtime state: merges the fields of
 * {@see StreamStateWriter::STATE_FIELDS} into that node's `streams_servers`
 * row and nothing else. Today the writer's SQL backend calls it in-process
 * on every node, which is the same UPDATE as before. With the cluster API
 * (Phase 5), a node's `stream.state` event is merged here instead, keyed by
 * the node that sent it, so a node can never write another node's row or any
 * desired-state column.
 *
 * @package XC_VM_Domain_Stream
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class StreamRowMerge {
	/**
	 * Merge state a node reported for one of its streams.
	 *
	 * @param array<string, mixed> $rFields
	 */
	public static function mergeNode(int $rServerID, int $rStreamID, array $rFields, ?object $rDb = null): bool {
		return self::apply('`stream_id` = ? AND `server_id` = ?', $rFields, [$rStreamID, $rServerID], $rDb);
	}

	/**
	 * Merge state into a row addressed by its `server_stream_id` (local callers
	 * that hold the row id; the API path always uses mergeNode()).
	 *
	 * @param array<string, mixed> $rFields
	 */
	public static function mergeRow(int $rServerStreamID, array $rFields, ?object $rDb = null): bool {
		return self::apply('`server_stream_id` = ?', $rFields, [$rServerStreamID], $rDb);
	}

	/**
	 * The fields a `stream.state` event may carry: runtime-state columns only,
	 * with credentials stripped from the source URL. Unknown keys are dropped
	 * rather than refused, because the sender is a remote node.
	 *
	 * @param array<string, mixed> $rFields
	 * @return array<string, mixed>
	 */
	public static function eventFields(array $rFields): array {
		$rFields = array_intersect_key($rFields, array_flip(StreamStateWriter::STATE_FIELDS));
		if (isset($rFields['current_source']) && is_string($rFields['current_source'])) {
			$rFields['current_source'] = Redactor::redact($rFields['current_source']);
		}
		return $rFields;
	}

	/**
	 * The UPDATE itself.
	 *
	 * @param array<string, mixed> $rFields
	 * @param list<mixed> $rWhereValues
	 */
	public static function apply(string $rWhere, array $rFields, array $rWhereValues, ?object $rDb = null): bool {
		if (empty($rFields)) {
			return true;
		}
		$rUnknown = array_diff(array_keys($rFields), StreamStateWriter::STATE_FIELDS);
		if (!empty($rUnknown)) {
			throw new \InvalidArgumentException('Not stream runtime state: ' . implode(', ', $rUnknown));
		}
		$rSet = [];
		foreach (array_keys($rFields) as $rColumn) {
			$rSet[] = '`' . $rColumn . '` = ?';
		}
		$rDb ??= DatabaseFactory::get();
		return (bool) $rDb->query('UPDATE `streams_servers` SET ' . implode(', ', $rSet) . ' WHERE ' . $rWhere, ...array_values($rFields), ...$rWhereValues);
	}
}
