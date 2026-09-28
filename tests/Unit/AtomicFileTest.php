<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Util\AtomicFile;

/**
 * AtomicFile — replace a file so a reader sees the old content or the new,
 * never half: written aside in the same directory under a name of this
 * writer's own (pid and a random part, so concurrent writers never share
 * one), created exclusively, renamed in, and removed on any failure.
 */
final class AtomicFileTest extends TestCase {
	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm_atomic_' . uniqid('', true) . '/';
		mkdir($this->rDir, 0755);
	}

	protected function tearDown(): void {
		$this->remove($this->rDir);
	}

	private function remove(string $rPath): void {
		if (is_dir($rPath) && !is_link($rPath)) {
			foreach (scandir($rPath) ?: [] as $rName) {
				if ($rName !== '.' && $rName !== '..') {
					$this->remove(rtrim($rPath, '/') . '/' . $rName);
				}
			}
			@rmdir($rPath);
			return;
		}
		@unlink($rPath);
	}

	/** @return list<string> every name in the directory, dot files included */
	private function names(): array {
		return array_values(array_diff(scandir($this->rDir) ?: [], ['.', '..']));
	}

	public function testWritesANewFileAndReplacesAnOldOne(): void {
		$rPath = $this->rDir . 'state.json';
		$this->assertTrue(AtomicFile::write($rPath, '{"a":1}'));
		$this->assertSame('{"a":1}', file_get_contents($rPath));
		$this->assertTrue(AtomicFile::write($rPath, '{"a":2}'));
		$this->assertSame('{"a":2}', file_get_contents($rPath));
		$this->assertSame(['state.json'], $this->names(), 'nothing left aside');
	}

	public function testAnEmptyStringIsAnEmptyFile(): void {
		$this->assertTrue(AtomicFile::write($this->rDir . 'empty', ''));
		$this->assertSame('', file_get_contents($this->rDir . 'empty'));
	}

	public function testTheModeIsTheNewFilesAndNullKeepsTheUmasks(): void {
		$this->assertTrue(AtomicFile::write($this->rDir . 'secret', 'x', 0600));
		$this->assertTrue(AtomicFile::write($this->rDir . 'conf', 'x', 0644));
		$rUmask = umask(022);
		try {
			$this->assertTrue(AtomicFile::write($this->rDir . 'plain', 'x'));
		} finally {
			umask($rUmask);
		}
		clearstatcache();
		$this->assertSame(0600, fileperms($this->rDir . 'secret') & 0777);
		$this->assertSame(0644, fileperms($this->rDir . 'conf') & 0777);
		$this->assertSame(0644, fileperms($this->rDir . 'plain') & 0777);
		// A replaced file takes the new mode, not the old file's.
		$this->assertTrue(AtomicFile::write($this->rDir . 'conf', 'y', 0600));
		clearstatcache();
		$this->assertSame(0600, fileperms($this->rDir . 'conf') & 0777);
	}

	public function testTheSyncedWriteIsTheSame(): void {
		$this->assertTrue(AtomicFile::write($this->rDir . 'synced', 'data', 0600, true));
		$this->assertSame('data', file_get_contents($this->rDir . 'synced'));
		$this->assertSame(['synced'], $this->names());
	}

	public function testAMissingDirectoryIsAFailureAndMakesNothing(): void {
		$this->assertFalse(AtomicFile::write($this->rDir . 'no/such/file', 'x'));
		$this->assertSame([], $this->names());
	}

	public function testARenameThatFailsLeavesNothingAside(): void {
		// A directory holds the name: the data is written aside, the rename fails.
		mkdir($this->rDir . 'taken');
		$this->assertFalse(AtomicFile::write($this->rDir . 'taken', 'x'));
		$this->assertDirectoryExists($this->rDir . 'taken');
		$this->assertSame(['taken'], $this->names(), 'the file aside is removed');
	}

	public function testThePrepareStepSeesTheWrittenFileAsideAndCanAbort(): void {
		$rPath = $this->rDir . 'lane.ndjson';
		file_put_contents($rPath, 'old');
		$rSeen = [];
		$rPrepare = static function (string $rTmp) use (&$rSeen): bool {
			$rSeen[] = [$rTmp, file_get_contents($rTmp)];
			return false;
		};
		$this->assertFalse(AtomicFile::write($rPath, 'new', null, false, $rPrepare));
		$this->assertSame('old', file_get_contents($rPath), 'the target is as it was');
		$this->assertSame(['lane.ndjson'], $this->names(), 'the file aside is removed');
		$this->assertCount(1, $rSeen);
		[$rTmp, $rData] = $rSeen[0];
		$this->assertSame('new', $rData);
		$this->assertSame($this->rDir, dirname($rTmp) . '/', 'beside the target (one filesystem)');
		$this->assertStringStartsWith('.lane.ndjson.' . getmypid() . '.', basename($rTmp), 'hidden and this writer\'s');
		$this->assertStringEndsWith('.tmp', $rTmp);

		$this->assertTrue(AtomicFile::write($rPath, 'new', null, false, static fn(string $rTmp): bool => chmod($rTmp, 0640)));
		clearstatcache();
		$this->assertSame('new', file_get_contents($rPath));
		$this->assertSame(0640, fileperms($rPath) & 0777);
	}

	public function testEveryWriteGoesAsideUnderANameOfItsOwn(): void {
		$rTmps = [];
		for ($i = 0; $i < 50; $i++) {
			AtomicFile::write($this->rDir . 'f', (string) $i, null, false, static function (string $rTmp) use (&$rTmps): bool {
				$rTmps[] = $rTmp;
				return true;
			});
		}
		$this->assertCount(50, array_unique($rTmps), 'two writes of one process never share a file aside');
	}

	/** A link at the target is replaced, never written through. */
	public function testALinkAtTheTargetIsReplacedNotFollowed(): void {
		file_put_contents($this->rDir . 'elsewhere', 'not yours');
		symlink($this->rDir . 'elsewhere', $this->rDir . 'target');
		$this->assertTrue(AtomicFile::write($this->rDir . 'target', 'mine'));
		$this->assertFalse(is_link($this->rDir . 'target'));
		$this->assertSame('mine', file_get_contents($this->rDir . 'target'));
		$this->assertSame('not yours', file_get_contents($this->rDir . 'elsewhere'));
	}

	/**
	 * Concurrent writers (separate processes) replacing one file: a reader
	 * only ever sees one writer's whole content, every write lands, and
	 * nothing is left aside. A shared temporary name (`<file>.tmp`) fails
	 * this: one writer truncates and refills the file another is renaming in.
	 */
	public function testConcurrentWritersNeverTearTheFile(): void {
		if (!function_exists('pcntl_fork')) {
			$this->markTestSkipped('needs pcntl');
		}
		$rPath = $this->rDir . 'shared.json';
		$rLog = $this->rDir . 'failed.log';
		$rWriters = 4;
		$rRounds = 150;
		$rPayload = static fn(int $rWriter): string => str_repeat(chr(ord('a') + $rWriter), 64 * 1024 + $rWriter);
		$this->assertTrue(AtomicFile::write($rPath, $rPayload(0)));
		$rPIDs = [];
		for ($w = 0; $w < $rWriters; $w++) {
			$rPID = pcntl_fork();
			$this->assertGreaterThanOrEqual(0, $rPID, 'fork');
			if ($rPID === 0) {
				$rFailed = 0;
				for ($i = 0; $i < $rRounds; $i++) {
					$rFailed += AtomicFile::write($rPath, $rPayload($w)) ? 0 : 1;
				}
				file_put_contents($rLog, $w . ':' . $rFailed . "\n", FILE_APPEND | LOCK_EX);
				posix_kill(posix_getpid(), SIGKILL);
			}
			$rPIDs[] = $rPID;
		}
		$rValid = [];
		for ($w = 0; $w < $rWriters; $w++) {
			$rValid[$rPayload($w)] = true;
		}
		$rTorn = 0;
		$rReads = 0;
		do {
			$rRunning = false;
			foreach ($rPIDs as $rPID) {
				if (pcntl_waitpid($rPID, $rStatus, WNOHANG) === 0) {
					$rRunning = true;
				}
			}
			$rData = file_get_contents($rPath);
			$rReads++;
			if (!isset($rValid[$rData])) {
				$rTorn++;
			}
		} while ($rRunning);
		foreach ($rPIDs as $rPID) {
			pcntl_waitpid($rPID, $rStatus);
		}
		$this->assertSame(0, $rTorn, 'no read of ' . $rReads . ' saw a torn or mixed file');
		$this->assertTrue(isset($rValid[file_get_contents($rPath)]), 'the last write is whole');
		$rLines = file($rLog, FILE_IGNORE_NEW_LINES) ?: [];
		sort($rLines);
		$this->assertSame(array_map(static fn(int $w): string => $w . ':0', range(0, $rWriters - 1)), $rLines, 'every writer\'s every write landed');
		$this->assertSame(['failed.log', 'shared.json'], $this->names(), 'nothing left aside');
	}
}
