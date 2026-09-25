<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Process\ProcessManager;

/**
 * ProcessManager::countStreamProducers — total_running_streams in the watchdog
 * heartbeat and servers_stats. It was `ps ax | grep -c ffmpeg`, which counted
 * ffprobe (it lives under bin/ffmpeg_bin/) and shell wrappers, and missed the
 * fanout daemon's native remuxer (`xc_fanout remux`), so copy-only streams
 * under fanout supervision were not counted. Driven against a fixture /proc
 * tree whose pids are above the kernel's PID_MAX_LIMIT.
 */
final class StreamProducerCountTest extends TestCase {

	private const FFMPEG = "/home/xc_vm/bin/ffmpeg_bin/8.0/ffmpeg\0-y\0-i\0http://src/live.ts\0/home/xc_vm/content/streams/7_.m3u8\0";
	private const FFPROBE = "/home/xc_vm/bin/ffmpeg_bin/8.0/ffprobe\0-v\0quiet\0-show_streams\0http://src/live.ts\0";
	private const SHELL = "sh\0-c\0/home/xc_vm/bin/ffmpeg_bin/8.0/ffmpeg -i http://src/live.ts /home/xc_vm/content/streams/7_.m3u8\0";
	private const REMUX = "/home/xc_vm/bin/xc_fanout/xc_fanout\0remux\0-loglevel\0error\0-i\0http://src/live.ts\0/home/xc_vm/content/streams/8_.m3u8\0";
	private const DAEMON = "/home/xc_vm/bin/xc_fanout/xc_fanout\0-config\0/home/xc_vm/bin/xc_fanout/config.json\0";
	private const FPM = 'php-fpm: pool xc_vm';

	private string $rRoot;

	protected function setUp(): void {
		$this->rRoot = sys_get_temp_dir() . '/xcvm_proc_' . uniqid('', true);
		mkdir($this->rRoot, 0755, true);
	}

	protected function tearDown(): void {
		foreach (glob($this->rRoot . '/*/cmdline') ?: [] as $rFile) {
			unlink($rFile);
		}
		foreach (glob($this->rRoot . '/*') ?: [] as $rDir) {
			rmdir($rDir);
		}
		@rmdir($this->rRoot);
	}

	private function process(int $rPID, string $rCmdline): void {
		mkdir($this->rRoot . '/' . $rPID);
		file_put_contents($this->rRoot . '/' . $rPID . '/cmdline', $rCmdline);
	}

	public function testCountsFfmpegAndNativeRemuxProducersOnly(): void {
		$this->process(4300001, self::FFMPEG);
		$this->process(4300002, self::FFPROBE);
		$this->process(4300003, self::SHELL);
		$this->process(4300004, self::REMUX);
		$this->process(4300005, self::DAEMON);
		$this->process(4300006, self::FPM);
		// A kernel thread or a zombie has an empty cmdline.
		$this->process(4300007, '');

		$this->assertSame(2, ProcessManager::countStreamProducers($this->rRoot));
	}

	public function testAnEmptyProcRootCountsNothing(): void {
		$this->assertSame(0, ProcessManager::countStreamProducers($this->rRoot));
		$this->assertSame(0, ProcessManager::countStreamProducers($this->rRoot . '/missing'));
	}

	public function testTheProducerPredicate(): void {
		$this->assertTrue(ProcessManager::isStreamProducerCmdline(self::FFMPEG), 'ffmpeg');
		$this->assertTrue(ProcessManager::isStreamProducerCmdline(self::REMUX), 'xc_fanout remux');
		$this->assertTrue(ProcessManager::isStreamProducerCmdline("ffmpeg\0-i\0x\0"), 'ffmpeg found on PATH');

		$this->assertFalse(ProcessManager::isStreamProducerCmdline(self::FFPROBE), 'ffprobe');
		$this->assertFalse(ProcessManager::isStreamProducerCmdline(self::SHELL), 'a shell wrapper');
		$this->assertFalse(ProcessManager::isStreamProducerCmdline(self::DAEMON), 'the fanout daemon');
		$this->assertFalse(ProcessManager::isStreamProducerCmdline(self::FPM), 'a PHP-FPM worker');
		$this->assertFalse(ProcessManager::isStreamProducerCmdline(''), 'an empty cmdline');
		$this->assertFalse(ProcessManager::isStreamProducerCmdline("/usr/bin/xc_fanout_remux\0"), 'a lookalike name');
	}

	public function testGetStatsCountsProducersInsteadOfGreppingPs(): void {
		$rSource = (string) file_get_contents(MAIN_HOME . 'Core/Util/SystemInfo.php');

		$this->assertStringContainsString("\$rJSON['total_running_streams'] = ProcessManager::countStreamProducers();", $rSource);
		$this->assertStringNotContainsString('grep -c ffmpeg', $rSource);
	}
}
