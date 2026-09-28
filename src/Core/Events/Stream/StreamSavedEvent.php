<?php

namespace XcVm\Core\Events\Stream;

/**
 * StreamSavedEvent — live streams were written by the stream form, an import
 * or the admin API (StreamService::process), once per save, after every row.
 *
 * $moduleFields is what the form posted under `module[<id>]` for the tabs the
 * admin may see (StreamFormRegistry). A module only touches its own data when
 * its id is present: an import, an API call or a form without the tab carries
 * none, and must not wipe what the module stored.
 *
 * @package XC_VM_Core_Events_Stream
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class StreamSavedEvent {
	/**
	 * @param int[]                $streamIds    The streams written.
	 * @param bool                 $isNew        True when they were created, false for an edit.
	 * @param string               $source       'form' (the form or the API), 'import' (M3U) or 'review' (Import & Review).
	 * @param array<string, array> $moduleFields Tab id => its posted fields.
	 */
	public function __construct(
		public readonly array $streamIds,
		public readonly bool $isNew,
		public readonly string $source,
		public readonly array $moduleFields,
	) {
	}
}
