<?php

namespace XcVm\Core\Events\Stream;

/**
 * Fired after MAIN changed what a node needs to run or serve these streams:
 * a stream saved (created or edited, alone or in bulk), assigned to or taken
 * off a server, its parent or on-demand flag changed, its options changed,
 * or a recording of it scheduled. Not for what nodes write back (pids,
 * status, codecs, progress): those are the nodes' own.
 *
 * `all` marks a change to every stream at once (a bulk rewrite of the source
 * URLs), whose ids the writer does not know: the node replica then checks
 * every stream again instead of taking a list.
 *
 * @package XC_VM_Core_Events
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class StreamsChangedEvent {
	/**
	 * @param list<int> $streamIds The streams changed (empty with $all).
	 */
	public function __construct(
		public readonly array $streamIds,
		public readonly bool $all = false,
	) {
	}

	/** Every stream changed. */
	public static function all(): self {
		return new self([], true);
	}
}
