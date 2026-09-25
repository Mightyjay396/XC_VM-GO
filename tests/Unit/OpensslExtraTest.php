<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\OpensslExtra;

/**
 * OpensslExtra — a MAIN and its LBs must hold the same OPENSSL_EXTRA, or the
 * tokens MAIN mints for its redirects are rejected by the LB.
 *
 * fingerprint / reportedFingerprint: what each node publishes in
 * servers.server_hardware, so the values can be compared without leaving the
 * node.
 */
final class OpensslExtraTest extends TestCase {

	public function testFingerprintIsAShortStableDigestThatHidesTheValue(): void {
		$rSecret = 'fNiu3XD448xTDa27xoY4';
		$rPrint = OpensslExtra::fingerprint($rSecret);

		$this->assertSame($rPrint, OpensslExtra::fingerprint($rSecret));
		$this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $rPrint);
		$this->assertNotSame(OpensslExtra::fingerprint('a'), OpensslExtra::fingerprint('b'));
		$this->assertStringNotContainsString($rSecret, $rPrint);
		$this->assertNotSame(substr(hash('sha256', $rSecret), 0, 16), $rPrint, 'keyed, not a bare hash of the value');
		$this->assertNotSame(substr(md5($rSecret), 0, 16), $rPrint);
	}

	public function testReportedFingerprintIsReadFromServerHardware(): void {
		$rPrint = OpensslExtra::fingerprint('x');

		$this->assertSame($rPrint, OpensslExtra::reportedFingerprint(['server_hardware' => json_encode(['cores' => 4, OpensslExtra::HARDWARE_KEY => $rPrint])]));
		$this->assertNull(OpensslExtra::reportedFingerprint(['server_hardware' => json_encode(['cores' => 4])]), 'node not updated yet');
		$this->assertNull(OpensslExtra::reportedFingerprint(['server_hardware' => json_encode([OpensslExtra::HARDWARE_KEY => ''])]));
		$this->assertNull(OpensslExtra::reportedFingerprint(['server_hardware' => json_encode([OpensslExtra::HARDWARE_KEY => 12])]));
		$this->assertNull(OpensslExtra::reportedFingerprint(['server_hardware' => 'not json']));
		$this->assertNull(OpensslExtra::reportedFingerprint(['server_hardware' => null]));
		$this->assertNull(OpensslExtra::reportedFingerprint([]));
	}
}
