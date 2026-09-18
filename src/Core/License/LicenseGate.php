<?php

namespace XcVm\Core\License;

/**
 * Reads the panel's licence verdict for the one capability it gates: the
 * xc_fanout live-delivery daemon.
 *
 * The actual enforcement — HWID-bound activation key (tied to install_id),
 * the hidden method-selector bytes, the weekly re-validation request and the
 * 7-day offline grace — all live in the compiled xcvm_core extension. PHP only
 * asks it "may this install use fanout right now?" and never sees the key logic.
 *
 * Enforcement is SOFT: when the verdict is negative, {@see FanoutClient::available()}
 * reports the daemon as unavailable and live streams fall back to the legacy
 * delivery path automatically — nothing is hard-blocked and no data is touched.
 *
 * Fail-open: where the extension (or the method) is absent — dev/CI and installs
 * without xcvm_core — fanout stays available, so this never bricks a working panel.
 *
 * @package XC_VM_Core_License
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class LicenseGate {
	/**
	 * Whether the fanout daemon is reachable AND this install is licensed to use it.
	 *
	 * This is the single gate the delivery/supervision choke points consult
	 * (replacing bare file_exists(FANOUT_CTL_SOCK) checks). False means the control
	 * socket is absent OR the licence denies fanout; callers then fall back to the
	 * legacy delivery path automatically (soft enforcement).
	 *
	 * @return bool True when fanout should be used for this request.
	 */
	public static function fanoutUsable(): bool {
		return defined('FANOUT_CTL_SOCK') && file_exists(FANOUT_CTL_SOCK) && self::fanoutAllowed();
	}

	/**
	 * @return bool True when this install's licence permits the fanout daemon.
	 */
	public static function fanoutAllowed(): bool {
		if (class_exists('XC_VM') && method_exists('XC_VM', 'license_valid')) {
			return (bool) \XC_VM::license_valid();
		}

		return true;
	}
}
