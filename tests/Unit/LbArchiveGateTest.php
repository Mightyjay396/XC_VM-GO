<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * tools/ci/verify-lb-archive.sh (make verify-lb-archive) guards the Makefile's
 * LB lists. It must fail on an entry that matches no tracked path (a strip rule
 * that became a no-op after a rename, as the old www/* entries did), on
 * privileged code left in the manifest, and on a stripped file that
 * lb_configs/nginx.conf still routes to.
 *
 * Each case overrides one Makefile list through MAKEFLAGS, as `make VAR=...`
 * on the command line would, and runs the real script against the checkout.
 */
#[Group('skip-on-panel')]
final class LbArchiveGateTest extends TestCase {

	private string $rRoot;

	protected function setUp(): void {
		// The gate reads the Makefile and `git ls-files`, so it only runs in a
		// git checkout of the repo (not on a flat /home/xc_vm deployment).
		$this->rRoot = dirname(__DIR__, 2);
		if (!is_file($this->rRoot . '/Makefile') || !is_file($this->rRoot . '/tools/ci/verify-lb-archive.sh') || !file_exists($this->rRoot . '/.git')) {
			$this->markTestSkipped('the LB archive gate runs only in a git checkout of the repo');
		}
	}

	public function testCurrentListsPass(): void {
		[$rCode, $rOutput] = $this->runGate([]);

		$this->assertSame(0, $rCode, $rOutput);
		$this->assertStringStartsWith('OK: ', $rOutput);
		$this->assertDoesNotMatchRegularExpression('/^(STALE|LEAK|MISSING|FAIL):/m', $rOutput);
	}

	public function testStaleFileEntryFails(): void {
		[$rCode, $rOutput] = $this->runGate(['LB_FILES_TO_REMOVE' => array_merge($this->makeList('LB_FILES_TO_REMOVE'), ['www/admin/api.php'])]);

		$this->assertSame(1, $rCode, $rOutput);
		$this->assertStringContainsString("STALE: 'www/admin/api.php'", $rOutput);
	}

	public function testStaleDirectoryEntryFails(): void {
		[$rCode, $rOutput] = $this->runGate(['LB_DIRS' => array_merge($this->makeList('LB_DIRS'), ['www'])]);

		$this->assertSame(1, $rCode, $rOutput);
		$this->assertStringContainsString("STALE: 'www'", $rOutput);
	}

	public function testPrivilegedFileLeftInTheManifestFails(): void {
		$rFiles = array_values(array_diff($this->makeList('LB_FILES_TO_REMOVE'), ['Public/stream/auth.php']));
		[$rCode, $rOutput] = $this->runGate(['LB_FILES_TO_REMOVE' => $rFiles]);

		$this->assertSame(1, $rCode, $rOutput);
		$this->assertStringContainsString("LEAK: 'Public/stream/auth.php'", $rOutput);
	}

	/** @return array<string, array{string}> */
	public static function routedFiles(): array {
		return [
			'SCRIPT_FILENAME' => ['Public/index.php'],
			'stream handler'  => ['Public/stream/live.php'],
			'admin handler'   => ['Public/admin/vod.php'],
		];
	}

	#[DataProvider('routedFiles')]
	public function testStrippingARoutedFileFails(string $rPath): void {
		[$rCode, $rOutput] = $this->runGate(['LB_FILES_TO_REMOVE' => array_merge($this->makeList('LB_FILES_TO_REMOVE'), [$rPath])]);

		$this->assertSame(1, $rCode, $rOutput);
		$this->assertMatchesRegularExpression('#^MISSING: .*' . preg_quote($rPath, '#') . '#m', $rOutput);
	}

	/** @return string[] The current value of a Makefile list. */
	private function makeList(string $rName): array {
		[$rCode, $rOutput] = $this->runProcess(['make', '-s', 'print-' . $rName], []);
		$this->assertSame(0, $rCode, $rOutput);

		return preg_split('/\s+/', trim($rOutput), -1, PREG_SPLIT_NO_EMPTY);
	}

	/**
	 * @param array<string, string[]> $rOverrides Makefile lists to replace.
	 * @return array{int, string} Exit code and combined output.
	 */
	private function runGate(array $rOverrides): array {
		$rFlags = [];
		foreach ($rOverrides as $rName => $rValues) {
			$this->assertSame([], preg_grep('#^[A-Za-z0-9_./-]+$#', $rValues, PREG_GREP_INVERT), 'a path MAKEFLAGS cannot carry');
			// MAKEFLAGS separates words with spaces; a space inside a value is escaped.
			$rFlags[] = $rName . '=' . implode('\\ ', $rValues);
		}

		return $this->runProcess(['bash', 'tools/ci/verify-lb-archive.sh'], $rFlags);
	}

	/**
	 * @param string[] $rCommand
	 * @param string[] $rFlags Variable overrides passed to every make call.
	 * @return array{int, string}
	 */
	private function runProcess(array $rCommand, array $rFlags): array {
		$rEnv = getenv();
		unset($rEnv['MAKEFLAGS'], $rEnv['MFLAGS'], $rEnv['MAKELEVEL'], $rEnv['MAKEOVERRIDES']);
		if ($rFlags !== []) {
			$rEnv['MAKEFLAGS'] = '-- ' . implode(' ', $rFlags);
		}
		$rProcess = proc_open($rCommand, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $rPipes, $this->rRoot, $rEnv);
		if (!is_resource($rProcess)) {
			$this->fail('could not start ' . implode(' ', $rCommand));
		}
		$rOutput = (string) stream_get_contents($rPipes[1]);
		fclose($rPipes[1]);

		return [proc_close($rProcess), $rOutput];
	}
}
