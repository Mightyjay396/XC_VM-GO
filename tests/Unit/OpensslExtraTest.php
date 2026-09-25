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
 * publish: what cron:servers adds to the node's server_hardware.
 *
 * signal / applySignal: the root signal server:sync-openssl-extra queues on
 * MAIN and cron:root_signals applies on an LB; both sides share it here.
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
		try {
			OpensslExtra::usePrevFile(null);
		} finally {
			exec('rm -rf ' . escapeshellarg($this->rDir));
		}
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

	public function testPublishAddsThisNodesFingerprintToItsHardware(): void {
		$rHardware = OpensslExtra::publish(['cores' => 4]);

		$this->assertSame(OpensslExtra::fingerprint(OPENSSL_EXTRA), OpensslExtra::reportedFingerprint(['server_hardware' => json_encode($rHardware)]));
		$this->assertSame(4, $rHardware['cores']);
	}

	/** An LB on an older or newer build must still recognise the action: its name is the wire contract. */
	public function testTheSignalCarriesTheValueUnderAFixedAction(): void {
		$this->assertSame('set_openssl_extra', OpensslExtra::SIGNAL_ACTION);
		$this->assertSame(['action' => 'set_openssl_extra', 'value' => 'main-extra'], json_decode(OpensslExtra::signal('main-extra'), true));
		// proxy_api.php leaves out the rows that match this, so no proxy is handed the value.
		$this->assertStringContainsString('"action":"' . OpensslExtra::SIGNAL_ACTION . '"', OpensslExtra::signal('main-extra'));
	}

	public function testAnLbAppliesTheSignalAndKeepsItsOldValueForTheWindow(): void {
		$this->assertTrue(OpensslExtra::applySignal(json_decode(OpensslExtra::signal('main-extra'), true), false, $this->rDir, 1000));

		$this->assertSame('main-extra', file_get_contents($this->rDir . 'openssl_extra'));
		$this->assertSame(['value' => OPENSSL_EXTRA, 'valid_until' => 1600], json_decode((string) file_get_contents($this->rDir . 'openssl_extra.prev'), true));
	}

	/** The main's value keys hmac_keys and image names: a signal never changes it. */
	public function testTheMainIgnoresTheSignal(): void {
		$this->assertNull(OpensslExtra::applySignal(json_decode(OpensslExtra::signal('main-extra'), true), true, $this->rDir, 1000));
		$this->assertSame([], array_values(array_diff(scandir($this->rDir), ['.', '..'])));
	}

	public function testASignalWithoutAStringValueIsNotApplied(): void {
		foreach ([['action' => OpensslExtra::SIGNAL_ACTION], ['action' => OpensslExtra::SIGNAL_ACTION, 'value' => 42], ['action' => OpensslExtra::SIGNAL_ACTION, 'value' => ['x']], ['action' => OpensslExtra::SIGNAL_ACTION, 'value' => '']] as $rData) {
			$this->assertFalse(OpensslExtra::applySignal($rData, false, $this->rDir, 1000), json_encode($rData));
		}
		$this->assertSame([], array_values(array_diff(scandir($this->rDir), ['.', '..'])));
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
