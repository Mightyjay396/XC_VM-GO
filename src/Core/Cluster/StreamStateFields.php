<?php

namespace XcVm\Core\Cluster;

/**
 * A stream's runtime state as it leaves the node or lands in its store: the
 * one column that can carry credentials, the current source (a URL), goes
 * through {@see Redactor}; every other column is kept as is. The writer's
 * `stream.state` event, its resend(), MAIN's merge of that event
 * (StreamRowMerge::eventFields) and the node's own store (StreamRuntime: a
 * kept write, a seed from MAIN's rows) all take it from here, so none of them
 * can let a source through unredacted.
 *
 * In Core, not beside StreamStateWriter: StreamRuntime (Core) calls it, and
 * the columns are the caller's to name (StreamStateWriter::STATE_FIELDS).
 */
final class StreamStateFields {
	/** The runtime-state column that holds a source URL. */
	public const SOURCE = 'current_source';

	/** One column's value as it may leave the node: the source redacted, anything else as is. */
	public static function value(int|string $rColumn, mixed $rValue): mixed {
		return $rColumn === self::SOURCE && is_string($rValue) ? Redactor::redact($rValue) : $rValue;
	}

	/**
	 * The fields with the source redacted (a null or absent source stays so).
	 *
	 * @param array<string, mixed> $rFields
	 * @return array<string, mixed>
	 */
	public static function redact(array $rFields): array {
		if (isset($rFields[self::SOURCE]) && is_string($rFields[self::SOURCE])) {
			$rFields[self::SOURCE] = Redactor::redact($rFields[self::SOURCE]);
		}
		return $rFields;
	}

	/**
	 * Only the fields named in $rColumns (the rest dropped, not refused), with
	 * the source redacted.
	 *
	 * @param array<string, mixed> $rFields
	 * @param list<string> $rColumns
	 * @return array<string, mixed>
	 */
	public static function pick(array $rFields, array $rColumns): array {
		return self::redact(array_intersect_key($rFields, array_flip($rColumns)));
	}
}
