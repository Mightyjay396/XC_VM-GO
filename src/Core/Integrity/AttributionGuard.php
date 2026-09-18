<?php

namespace XcVm\Core\Integrity;

use XcVm\Core\Util\AdminHelpers;

/**
 * Verifies that the panel still carries its required author/licence attribution.
 *
 * AGPL-3.0 §7(b) permits requiring preservation of the attribution notice. The
 * notice has a single source ({@see AdminHelpers::getAttribution()}); this guard
 * checks that the load-bearing markers are still present in it. A boot stage
 * ({@see \XcVm\Core\Bootstrap\Stage\AttributionVerificationStage}) runs the check
 * on every HTTP request to the panel UI and locks the panel (reversibly, without
 * touching data) when the attribution has been stripped.
 *
 * Note: this is a readable-PHP check — a determined actor who removes the notice
 * can also remove this guard. It deters casual rebranding; tamper-resistant
 * enforcement belongs in the compiled xcvm_core extension.
 *
 * @package XC_VM_Core_Integrity
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class AttributionGuard {
	/**
	 * Substrings that the rendered attribution MUST contain. Kept to the stable,
	 * Vateron-specific parts (author repo link, author name, licence link).
	 *
	 * @var string[]
	 */
	private const MARKERS = [
		'https://github.com/Vateron-Media/XC_VM',
		'>Vateron Media<',
		'https://www.gnu.org/licenses/agpl-3.0.html',
	];

	/**
	 * @return bool True when the panel's attribution notice still carries every marker.
	 */
	public static function verify(): bool {
		return self::inspect(AdminHelpers::getAttribution());
	}

	/**
	 * Prefer the tamper-resistant compiled check when xcvm_core exposes it: that
	 * logic lives in the ioncube-protected extension and cannot be stripped from
	 * readable PHP. Fall back to the in-PHP marker scan (a deterrent only) where
	 * the extension or the method is absent (dev/CI, installs without xcvm_core).
	 *
	 * @param string $rHtml Rendered attribution HTML to inspect.
	 */
	private static function inspect(string $rHtml): bool {
		if (class_exists('XC_VM') && method_exists('XC_VM', 'verify_branding')) {
			return (bool) \XC_VM::verify_branding($rHtml);
		}

		return self::hasMarkers($rHtml);
	}

	/**
	 * @param string $rHtml Rendered attribution HTML to inspect.
	 * @return bool True when every required marker is present in $rHtml.
	 */
	public static function hasMarkers(string $rHtml): bool {
		foreach (self::MARKERS as $rMarker) {
			if (strpos($rHtml, $rMarker) === false) {
				return false;
			}
		}

		return true;
	}
}
