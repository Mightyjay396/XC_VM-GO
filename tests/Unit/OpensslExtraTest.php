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
 *
 * previous: after a node switches to another value, the one it replaced is
 * kept for a short window, so tokens minted with it still open
 * (Encryption::readToken).
 */
final class OpensslExtraTest extends TestCase {

	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm_openssl_extra_' . uniqid() . '/';
		mkdir($this->rDir, 0700, true);
	}

	protected function tearDown(): void {
		OpensslExtra::usePrevFile(null);
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/** Write a previous-value file (JSON unless given raw) and point previous() at it. */
	private function writePrev($rData): void {
		file_put_contents($this->rDir . 'openssl_extra.prev', is_string($rData) ? $rData : json_encode($rData));
		OpensslExtra::usePrevFile($this->rDir . 'openssl_extra.prev');
	}

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

	public function testThePreviousValueIsAcceptedUntilItsWindowCloses(): void {
		$this->writePrev(['value' => 'old-extra', 'valid_until' => 1600]);

		$this->assertSame('old-extra', OpensslExtra::previous(1500));
		$this->assertSame('old-extra', OpensslExtra::previous(1600));
		$this->assertNull(OpensslExtra::previous(1601));
	}

	public function testThereIsNoPreviousValueWithoutAUsableFile(): void {
		OpensslExtra::usePrevFile($this->rDir . 'missing');
		$this->assertNull(OpensslExtra::previous(1000), 'no file');

		$this->writePrev('{"value": "old-extra", ');
		$this->assertNull(OpensslExtra::previous(1000), 'corrupt JSON');

		$this->writePrev(['value' => '', 'valid_until' => 1600]);
		$this->assertNull(OpensslExtra::previous(1000), 'empty value');

		$this->writePrev(['value' => 'old-extra']);
		$this->assertNull(OpensslExtra::previous(1000), 'no expiry');

		$this->writePrev(['value' => OPENSSL_EXTRA, 'valid_until' => 1600]);
		$this->assertNull(OpensslExtra::previous(1000), 'the value in use now');
	}
}
