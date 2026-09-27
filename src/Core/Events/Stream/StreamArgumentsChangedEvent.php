<?php

namespace XcVm\Core\Events\Stream;

/**
 * Fired after the definition of stream arguments changed (`streams_arguments`,
 * e.g. the default user agent or proxy in the settings): every stream with
 * an option for one of them runs with the new definition.
 *
 * @package XC_VM_Core_Events
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class StreamArgumentsChangedEvent {
	/**
	 * @param list<string> $argumentKeys The `argument_key`s changed.
	 */
	public function __construct(
		public readonly array $argumentKeys,
	) {
	}
}
