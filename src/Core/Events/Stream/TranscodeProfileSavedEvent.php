<?php

namespace XcVm\Core\Events\Stream;

/**
 * Fired after an admin saves a transcoding profile (`profiles`): every
 * stream that transcodes with it runs with the new options.
 *
 * @package XC_VM_Core_Events
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class TranscodeProfileSavedEvent {
	public function __construct(
		public readonly int $profileId,
	) {
	}
}
