<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Stream\StreamProcess;

/**
 * Stopping a movie ends the encodes writing that movie's output and, when
 * forced, deletes its files — and nothing belonging to another movie whose id
 * merely starts with the same digits (5 vs 50). Both run without a shell.
 */
final class StreamProcessStopMovieTest extends TestCase {

	/** @var array<int> */
	private array $rChildren = [];

	protected function tearDown(): void {
		foreach ($this->rChildren as $rPID) {
			posix_kill($rPID, 9);
			pcntl_waitpid($rPID, $rStatus);
		}
		$this->rChildren = [];
		foreach (glob(VOD_PATH . '*') ?: [] as $rFile) {
			@unlink($rFile);
		}
	}

	private static function call(string $rMethod, ...$rArgs) {
		$m = new ReflectionMethod(StreamProcess::class, $rMethod);
		$m->setAccessible(true);
		return $m->invoke(null, ...$rArgs);
	}

	/**
	 * A stand-in encode: a forked child whose command line (its process title)
	 * names `$rOutput`. The child only sleeps and is killed — it never returns
	 * into the test runner.
	 */
	private function encode(string $rOutput): int {
		$rPID = pcntl_fork();
		$this->assertGreaterThanOrEqual(0, $rPID, 'fork');
		if ($rPID === 0) {
			cli_set_process_title('encode ' . $rOutput);
			sleep(30);
			posix_kill(posix_getpid(), 9);
		}
		$this->rChildren[] = $rPID;
		for ($i = 0; $i < 100 && strpos((string) @file_get_contents('/proc/' . $rPID . '/cmdline'), $rOutput) === false; $i++) {
			usleep(10000);
		}
		$this->assertStringContainsString($rOutput, (string) file_get_contents('/proc/' . $rPID . '/cmdline'), 'the child shows the output path');
		return $rPID;
	}

	private static function running(int $rPID): bool {
		$rStat = @file_get_contents('/proc/' . $rPID . '/stat');
		// A killed child stays a zombie (state Z) until proc_close reaps it.
		return $rStat !== false && !preg_match('/^\d+ \(.*\) Z /', $rStat);
	}

	public function testKillsOnlyThisMoviesEncodes(): void {
		$rMovie5 = $this->encode(VOD_PATH . '5.mp4');
		$rMovie50 = $this->encode(VOD_PATH . '50.mp4');

		$rKilled = self::call('killMovieEncodes', 5);

		$this->assertSame([$rMovie5], $rKilled);
		for ($i = 0; $i < 100 && self::running($rMovie5); $i++) {
			usleep(10000);
		}
		$this->assertFalse(self::running($rMovie5), 'movie 5 encode is gone');
		$this->assertTrue(self::running($rMovie50), 'movie 50 encode keeps running');
	}

	public function testDeletesOnlyThisMoviesFiles(): void {
		foreach (['5.mp4', '5.errors', '50.mp4', '5_.pid'] as $rName) {
			touch(VOD_PATH . $rName);
		}
		// A movie served without transcoding is a symlink to its source.
		$rSource = tempnam(sys_get_temp_dir(), 'xcvm-src');
		symlink($rSource, VOD_PATH . '5.mkv');

		self::call('deleteMovieFiles', 5);

		$this->assertFalse(is_link(VOD_PATH . '5.mkv'), 'the symlink is removed');
		$this->assertFileExists($rSource, 'its source is not');
		unlink($rSource);
		$this->assertFileDoesNotExist(VOD_PATH . '5.mp4');
		$this->assertFileDoesNotExist(VOD_PATH . '5.errors');
		$this->assertFileExists(VOD_PATH . '50.mp4');
		$this->assertFileExists(VOD_PATH . '5_.pid', 'the old rm pattern 5.* never matched the pid file either');
	}

	/** Semgrep flags any non-constant command; stopMovie must not start a shell at all. */
	public function testStopMovieRunsNoShellCommand(): void {
		$m = new ReflectionMethod(StreamProcess::class, 'stopMovie');
		$rLines = array_slice(file($m->getFileName()), $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1);
		$this->assertDoesNotMatchRegularExpression('/\b(shell_exec|exec|system|passthru|popen|proc_open)\s*\(/', implode('', $rLines));
	}
}
