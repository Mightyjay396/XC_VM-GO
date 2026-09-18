<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\License\LicenseGate;

/**
 * Covers the soft, fail-open licence gate for the fanout daemon: without the
 * xcvm_core extension the gate must never block fanout, and fanoutUsable() must
 * report the daemon unusable when its control socket is absent.
 */
final class LicenseGateTest extends TestCase {
	/** No xcvm_core in dev/CI → the gate must fail open (fanout stays available). */
	public function testFanoutAllowedFailsOpenWithoutExtension(): void {
		if (class_exists('XC_VM') && method_exists('XC_VM', 'license_valid')) {
			$this->markTestSkipped('xcvm_core present — cannot assert the fail-open path.');
		}

		$this->assertTrue(LicenseGate::fanoutAllowed());
	}

	/** Fanout is unusable when the control socket is absent, regardless of licence. */
	public function testFanoutUnavailableWithoutControlSocket(): void {
		if (defined('FANOUT_CTL_SOCK') && file_exists(FANOUT_CTL_SOCK)) {
			$this->markTestSkipped('FANOUT_CTL_SOCK exists in this environment.');
		}

		$this->assertFalse(LicenseGate::fanoutUsable());
	}
}
