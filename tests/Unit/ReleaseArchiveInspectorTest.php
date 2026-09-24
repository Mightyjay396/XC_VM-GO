<?php

use XcVm\Core\Updates\ReleaseArchiveInspector;
use PHPUnit\Framework\TestCase;

/**
 * ReleaseArchiveInspector — reading a subpath's file list out of a .tar.gz
 * without extracting the whole archive.
 */
final class ReleaseArchiveInspectorTest extends TestCase {

	private string $root;
	private string $archivePath;

	protected function setUp(): void {
		if (!class_exists('PharData')) {
			$this->markTestSkipped('PharData not available in this runtime.');
		}

		$this->root = sys_get_temp_dir() . '/xcvm_archive_test_' . uniqid('', true);
		mkdir($this->root . '/migrations', 0755, true);
		mkdir($this->root . '/other', 0755, true);
		file_put_contents($this->root . '/migrations/001_a.sql', 'SELECT 1;');
		file_put_contents($this->root . '/migrations/002_b.sql', 'SELECT 2;');
		file_put_contents($this->root . '/other/unrelated.txt', 'not a migration');

		$this->archivePath = sys_get_temp_dir() . '/xcvm_archive_test_' . uniqid('', true) . '.tar.gz';
		$tarPath = substr($this->archivePath, 0, -3); // strip .gz for PharData's own naming
		$phar = new PharData($tarPath);
		$phar->buildFromDirectory($this->root);
		$phar->compress(Phar::GZ, '.tar.gz');
		unlink($tarPath);
		rename($tarPath . '.gz', $this->archivePath);
	}

	protected function tearDown(): void {
		$this->rrmdir($this->root);
		@unlink($this->archivePath);
	}

	private function rrmdir(string $dir): void {
		if (!is_dir($dir)) {
			return;
		}
		foreach (scandir($dir) as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}
			$path = $dir . '/' . $entry;
			is_dir($path) ? $this->rrmdir($path) : unlink($path);
		}
		rmdir($dir);
	}

	public function testListsFilesUnderTheGivenSubpath(): void {
		$files = ReleaseArchiveInspector::listSubpathFiles($this->archivePath, 'migrations');

		sort($files);
		$this->assertSame(['001_a.sql', '002_b.sql'], $files);
	}

	public function testReturnsEmptyForASubpathTheArchiveDoesNotHave(): void {
		$files = ReleaseArchiveInspector::listSubpathFiles($this->archivePath, 'no_such_dir');

		$this->assertSame([], $files);
	}

	public function testThrowsForAMissingArchive(): void {
		$this->expectException(\RuntimeException::class);
		ReleaseArchiveInspector::listSubpathFiles('/no/such/archive.tar.gz', 'migrations');
	}
}
