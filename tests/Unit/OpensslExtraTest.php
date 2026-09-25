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
 * install / previous: the root signal that brings an LB onto MAIN's value
 * keeps the value it replaced for a short window, so tokens the LB minted with
 * it still open (Encryption::readToken).
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

	public function testInstallWritesTheNewValueAndKeepsTheOldOneForTheWindow(): void {
		$this->assertTrue(OpensslExtra::install('new-extra', $this->rDir, 1000));

		$this->assertSame('new-extra', file_get_contents($this->rDir . 'openssl_extra'));
		$this->assertSame(0600, fileperms($this->rDir . 'openssl_extra') & 0777);
		$this->assertSame(['value' => OPENSSL_EXTRA, 'valid_until' => 1600], json_decode((string) file_get_contents($this->rDir . 'openssl_extra.prev'), true));
		$this->assertSame(0600, fileperms($this->rDir . 'openssl_extra.prev') & 0777);
		$this->assertSame(['openssl_extra', 'openssl_extra.prev'], array_values(array_diff(scandir($this->rDir), ['.', '..'])), 'no temporary file left behind');
	}

	public function testInstallReplacesAnExistingValue(): void {
		file_put_contents($this->rDir . 'openssl_extra', 'stale-extra');

		$this->assertTrue(OpensslExtra::install("  new-extra\n", $this->rDir, 1000, 60));

		$this->assertSame('new-extra', file_get_contents($this->rDir . 'openssl_extra'));
		$this->assertSame(1060, json_decode((string) file_get_contents($this->rDir . 'openssl_extra.prev'), true)['valid_until']);
	}

	/** Syncing a node that already holds the value must not cut short the window of an earlier change. */
	public function testInstallingTheCurrentValueKeepsAnEarlierPreviousValue(): void {
		$rEarlier = json_encode(['value' => 'older-extra', 'valid_until' => 1500]);
		file_put_contents($this->rDir . 'openssl_extra.prev', $rEarlier);

		$this->assertTrue(OpensslExtra::install(OPENSSL_EXTRA, $this->rDir, 1000));

		$this->assertSame(OPENSSL_EXTRA, file_get_contents($this->rDir . 'openssl_extra'));
		$this->assertSame($rEarlier, file_get_contents($this->rDir . 'openssl_extra.prev'));
	}

	public function testInstallRefusesAnEmptyValueOrAMissingDirectory(): void {
		$this->assertFalse(OpensslExtra::install(" \n", $this->rDir, 1000));
		$this->assertFileDoesNotExist($this->rDir . 'openssl_extra');
		$this->assertFileDoesNotExist($this->rDir . 'openssl_extra.prev');

		$this->assertFalse(OpensslExtra::install('new-extra', $this->rDir . 'missing/', 1000));
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
