<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Process\ProcessManager;

/**
 * ProcessManager::phpFpmWorkerPIDs — the pid list the watchdog publishes as
 * servers.php_pids. A connection row's pid is the PHP-FPM worker serving it
 * (getmypid() in live/vod/timeshift), so the list must hold the workers of
 * the xc_vm pool. It used to hold the FPM master pids from the pool pid files,
 * which never match a worker: in Redis mode MAIN's cron:users then closed
 * every PHP-served LB viewer about a minute after it connected.
 *
 * Driven against a fixture /proc tree. Its pids are above the kernel's
 * PID_MAX_LIMIT (4194304), so none can be this process's own pid, which
 * findProcessPIDs() skips.
 */
final class PhpFpmWorkerPidsTest extends TestCase {

	private string $rRoot;

	protected function setUp(): void {
		$this->rRoot = sys_get_temp_dir() . '/xcvm_proc_' . uniqid('', true);
		mkdir($this->rRoot, 0755, true);
	}

	protected function tearDown(): void {
		foreach (glob($this->rRoot . '/*/cmdline') ?: [] as $rFile) {
			unlink($rFile);
			rmdir(dirname($rFile));
		}
		@rmdir($this->rRoot);
	}

	private function process(int $rPID, string $rCmdline): void {
		mkdir($this->rRoot . '/' . $rPID);
		file_put_contents($this->rRoot . '/' . $rPID . '/cmdline', $rCmdline);
	}

	public function testListsTheXcVmPoolWorkersOnly(): void {
		$this->process(4300001, "php-fpm: master process (/home/xc_vm/bin/php/etc/1.conf)\0");
		// FPM overwrites the worker's argv with its title and pads the rest with NULs.
		$this->process(4300002, 'php-fpm: pool xc_vm' . str_repeat("\0", 40));
		$this->process(4300003, 'php-fpm: pool xc_vm');
		$this->process(4300004, "/home/xc_vm/bin/php/bin/php\0console.php\0watchdog\0");
		$this->process(4300005, 'php-fpm: pool www');
		// Globbed before 4300002 (string order); listed after it (numeric order).
		$this->process(43000040, 'php-fpm: pool xc_vm');

		$this->assertSame([4300002, 4300003, 43000040], ProcessManager::phpFpmWorkerPIDs($this->rRoot));
	}

	public function testNoWorkersYieldsAnEmptyList(): void {
		$this->process(4300001, "php-fpm: master process (/home/xc_vm/bin/php/etc/1.conf)\0");

		$this->assertSame([], ProcessManager::phpFpmWorkerPIDs($this->rRoot));
	}

	public function testFindProcessPIDsReadsTheGivenProcRoot(): void {
		$this->assertSame([], ProcessManager::findProcessPIDs(['x'], 0, $this->rRoot));

		$this->process(4300007, "ffmpeg\0-i\0x\0");
		$this->assertSame([4300007], ProcessManager::findProcessPIDs(['ffmpeg'], 0, $this->rRoot));
	}
}
