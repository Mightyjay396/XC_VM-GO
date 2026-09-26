<?php

namespace XcVm\Core\Events\Server;

/**
 * Fired after an admin saves, adds or deletes a server or proxy (the rows
 * of `servers`).
 *
 * @package XC_VM_Core_Events
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class ServerSavedEvent {
	/**
	 * @param list<int> $serverIds The servers saved.
	 */
	public function __construct(
		public readonly array $serverIds,
	) {
	}
}
