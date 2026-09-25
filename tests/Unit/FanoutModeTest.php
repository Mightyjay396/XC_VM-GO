<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\License\LicenseGate;
use XcVm\Streaming\Fanout\FanoutMode;
use XcVm\Streaming\Fanout\IngestFeeder;

/**
 * The fanout master switch (settings.fanout_enabled): how it is read, what it
 * does to the node (flag file, stopping the supervisor and daemon), and that
 * the daemon-facing gates honour it.
 */
class FanoutModeTest extends TestCase {
	private array $rSaved;
	private string $rDir;

	protected function setUp(): void {
		$this->rSaved = SettingsManager::getAll();
		$this->rDir = sys_get_temp_dir() . '/xcvm-fanoutmode-' . getmypid() . '-' . uniqid();
		mkdir($this->rDir, 0777, true);
	}

	protected function tearDown(): void {
		SettingsManager::set($this->rSaved);
		@unlink($this->rDir . '/disabled');
		@rmdir($this->rDir);
	}

	public function testMissingOrEmptySettingReadsAsOn(): void {
		$this->assertTrue(FanoutMode::enabled([]));
		$this->assertTrue(FanoutMode::enabled(['fanout_enabled' => null]));
		$this->assertTrue(FanoutMode::enabled(['fanout_enabled' => '']));
		SettingsManager::set([]);
		$this->assertTrue(FanoutMode::enabled());
	}

	public function testSettingValues(): void {
		$this->assertTrue(FanoutMode::enabled(['fanout_enabled' => 1]));
		$this->assertTrue(FanoutMode::enabled(['fanout_enabled' => '1']));
		$this->assertFalse(FanoutMode::enabled(['fanout_enabled' => 0]));
		$this->assertFalse(FanoutMode::enabled(['fanout_enabled' => '0']));
		SettingsManager::set(['fanout_enabled' => 0]);
		$this->assertFalse(FanoutMode::enabled());
	}

	public function testLegacyDeliveryFollowsTheSwitch(): void {
		$this->assertTrue(FanoutMode::legacyDelivery(['fanout_enabled' => 0]));
		// Without xcvm_core the licence check fails open, so fanout on = daemon path.
		if (!class_exists('XC_VM')) {
			$this->assertFalse(FanoutMode::legacyDelivery(['fanout_enabled' => 1]));
		}
	}

	public function testDisablingWritesTheFlagAndStopsSupervisorThenDaemon(): void {
		$rCmds = [];
		$rExec = function (string $rCmd) use (&$rCmds): void {
			$rCmds[] = $rCmd;
		};
		$rFlag = $this->rDir . '/disabled';

		$this->assertTrue(FanoutMode::applyToNode(false, $rExec, $rFlag));
		$this->assertFileExists($rFlag);
		$this->assertCount(2, $rCmds);
		$this->assertStringContainsString("pkill -u xc_vm -f '" . $this->rDir . "/run.sh'", $rCmds[0], 'the supervisor goes first, so it cannot respawn the daemon');
		$this->assertStringContainsString('pkill -u xc_vm -x xc_fanout', $rCmds[1]);

		// Idempotent: already off is no change, but the kill is repeated (a
		// daemon started by hand since is stopped again).
		$this->assertFalse(FanoutMode::applyToNode(false, $rExec, $rFlag));
		$this->assertCount(4, $rCmds);
	}

	public function testEnablingRemovesTheFlagAndKillsNothing(): void {
		$rCmds = [];
		$rExec = function (string $rCmd) use (&$rCmds): void {
			$rCmds[] = $rCmd;
		};
		$rFlag = $this->rDir . '/disabled';
		file_put_contents($rFlag, '1');

		$this->assertTrue(FanoutMode::applyToNode(true, $rExec, $rFlag));
		$this->assertFileDoesNotExist($rFlag);
		$this->assertFalse(FanoutMode::applyToNode(true, $rExec, $rFlag));
		$this->assertSame([], $rCmds);
	}

	public function testFlagLivesNextToTheDaemon(): void {
		$this->assertSame(MAIN_HOME . 'bin/xc_fanout/disabled', FanoutMode::flagPath());
	}

	public function testFanoutUsableIsFalseWhenSwitchedOffEvenWithASocket(): void {
		if (!defined('FANOUT_CTL_SOCK')) {
			define('FANOUT_CTL_SOCK', $this->rDir . '/control.sock');
		}
		$rCreated = false;
		if (!file_exists(FANOUT_CTL_SOCK)) {
			@touch(FANOUT_CTL_SOCK);
			$rCreated = true;
		}
		try {
			SettingsManager::set(['fanout_enabled' => 0]);
			$this->assertFalse(LicenseGate::fanoutUsable());
		} finally {
			if ($rCreated) {
				@unlink(FANOUT_CTL_SOCK);
			}
		}
	}

	public function testFeederIsANoOpWhenSwitchedOff(): void {
		SettingsManager::set(['fanout_enabled' => 0]);
		$rFeeder = IngestFeeder::forStream(987654, false);
		$this->assertFalse($rFeeder->isEnabled());
		$this->assertFalse($rFeeder->connect());
		$rFeeder->write(str_repeat("\x47" . str_repeat("\0", 187), 4));
		$rFeeder->flush();
		$this->assertSame(0, $rFeeder->backlog(), 'nothing is buffered for a daemon that is not coming');
		$this->assertFalse($rFeeder->isConnected());
	}
}
