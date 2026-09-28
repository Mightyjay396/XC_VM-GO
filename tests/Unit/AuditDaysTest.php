<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\AuditDays;

/**
 * The day-file handling SettingsAudit and ConnectAudit share: ranking with
 * a bound, the slot a new name goes to, the window read, the locked
 * rewrite and the prune (each audit its own extensions).
 */
final class AuditDaysTest extends TestCase {
	private const NOW = 1800000000; // 2027-01-15 08:00 UTC

	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-auditdays-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir, 0777, true);
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	private function day(int $rDaysAgo): string {
		return gmdate('Ymd', self::NOW - $rDaysAgo * 86400);
	}

	public function testTopRanksByCountThenNameAndFoldsTheRestIntoOther(): void {
		$this->assertSame(['b' => 5, 'a' => 3, 'c' => 3, '*' => 3], AuditDays::top(['c' => 3, 'a' => 3, '*' => 1, 'b' => 5, 'd' => 2], 3));
		$this->assertSame(['7' => 2, 'x' => 1], AuditDays::top(['x' => 1, '7' => 2], 5), 'numeric names stay names');
		$this->assertSame([], AuditDays::top([], 3));
	}

	public function testSlotKeepsKnownNamesAndSendsNewOnesPastTheBoundToOther(): void {
		$rCounts = ['a' => 1, 'b' => 1, '*' => 4];
		$this->assertSame('a', AuditDays::slot($rCounts, 'a', 2));
		$this->assertSame('*', AuditDays::slot($rCounts, 'c', 2), 'OTHER does not count against the bound');
		$this->assertSame('c', AuditDays::slot($rCounts, 'c', 3));
		$this->assertSame('*', AuditDays::slot($rCounts, '*', 2));
	}

	public function testPruneDeletesOnlyItsOwnOldDayFiles(): void {
		foreach ([$this->day(9) . '.json', $this->day(9) . '.ndjson', $this->day(8) . '.json', $this->day(0) . '.json', 'since', 'x' . $this->day(9) . '.json', $this->day(9) . '.json.tmp'] as $rName) {
			touch($this->rDir . $rName);
		}
		$this->assertSame(1, AuditDays::prune($this->rDir, ['json'], 8, self::NOW));
		$this->assertFileExists($this->rDir . $this->day(9) . '.ndjson', 'not this caller\'s extension');
		$this->assertSame(1, AuditDays::prune($this->rDir, ['json', 'ndjson'], 8, self::NOW));
		$rLeft = array_map('basename', glob($this->rDir . '*') ?: []);
		sort($rLeft);
		$rWant = [$this->day(0) . '.json', $this->day(8) . '.json', $this->day(9) . '.json.tmp', 'since', 'x' . $this->day(9) . '.json'];
		sort($rWant);
		$this->assertSame($rWant, $rLeft);
		$this->assertSame([$this->rDir . $this->day(8) . '.json' => $this->day(8), $this->rDir . $this->day(0) . '.json' => $this->day(0)], $this->sorted(AuditDays::files($this->rDir, ['json', 'ndjson'])));
	}

	public function testWindowReadsTheLastDaysNewestFirstFromADay(): void {
		foreach ([0 => ['n' => 0], 2 => ['n' => 2], 6 => ['n' => 6], 7 => ['n' => 7]] as $rAgo => $rDoc) {
			file_put_contents($this->rDir . $this->day($rAgo) . '.json', json_encode($rDoc));
		}
		file_put_contents($this->rDir . $this->day(1) . '.json', 'not json');
		$this->assertSame([['n' => 0], null, ['n' => 2], ['n' => 6]], AuditDays::window($this->rDir, 7, self::NOW));
		$this->assertSame([['n' => 0], null], AuditDays::window($this->rDir, 7, self::NOW, self::NOW - 86400), 'nothing before $rFrom\'s day');
		$this->assertSame([], AuditDays::window($this->rDir . 'absent/', 7, self::NOW), 'a directory not made yet holds no day');
	}

	public function testRewriteHandsTheDecodedDayAndWritesWhatComesBack(): void {
		$rFile = $this->rDir . $this->day(0) . '.json';
		$rSeen = [];
		$this->assertTrue(AuditDays::rewrite($rFile, static function (mixed $rDoc) use (&$rSeen): string {
			$rSeen[] = $rDoc;
			return '{"a":1}';
		}));
		$this->assertTrue(AuditDays::rewrite($rFile, static function (mixed $rDoc) use (&$rSeen): string {
			$rSeen[] = $rDoc;
			return '{}';
		}));
		$this->assertSame([null, ['a' => 1]], $rSeen);
		$this->assertSame('{}', file_get_contents($rFile), 'the old contents are gone, not overwritten in part');
		$this->assertSame('{}', AuditDays::readDay($rFile));
		$this->assertFalse(AuditDays::rewrite($this->rDir . 'no/such/dir/x.json', static fn(mixed $rDoc): string => ''));
		$this->assertFalse(AuditDays::readDay($this->rDir . 'absent.json'));
	}

	public function testMakeDirAndSearchable(): void {
		$this->assertTrue(AuditDays::makeDir($this->rDir . 'a/b/'));
		$this->assertDirectoryExists($this->rDir . 'a/b/');
		$this->assertSame(0750 & ~umask(), fileperms($this->rDir . 'a/b/') & 0777);
		$this->assertTrue(AuditDays::makeDir($this->rDir . 'a/b/'), 'there already');
		$this->assertTrue(AuditDays::searchable($this->rDir . 'a/b/'));
		$this->assertTrue(AuditDays::searchable($this->rDir . 'not/yet/'), 'the nearest level that exists');
	}

	/**
	 * @param array<string, string> $rFiles
	 * @return array<string, string>
	 */
	private function sorted(array $rFiles): array {
		ksort($rFiles);
		return $rFiles;
	}
}
