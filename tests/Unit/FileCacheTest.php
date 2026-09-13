<?php

use XcVm\Core\Cache\FileCache;
use PHPUnit\Framework\TestCase;

/**
 * FileCache — the file-backed cache behind CacheInterface. Exercises the
 * instance API against a throwaway temp directory: set/get roundtrip through
 * (ig)binary serialization, miss semantics (false, not null), age-based
 * expiry via maxAge, delete/flush, and the path/age accessors.
 */
final class FileCacheTest extends TestCase {

	private string $dir;
	private FileCache $cache;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/xcvm_fc_' . uniqid('', true);
		$this->cache = new FileCache($this->dir);
	}

	protected function tearDown(): void {
		foreach (glob(rtrim($this->dir, '/') . '/*') ?: [] as $f) {
			if (is_file($f)) {
				unlink($f);
			}
		}
		@rmdir(rtrim($this->dir, '/'));
	}

	public function testSetGetRoundtripsStructuredData(): void {
		$payload = ['a' => 1, 'nested' => ['x', 'y'], 'flag' => true];
		$this->assertTrue($this->cache->set('key', $payload));
		$this->assertSame($payload, $this->cache->get('key'));
	}

	public function testGetMissReturnsFalse(): void {
		$this->assertFalse($this->cache->get('nope'));
	}

	public function testHasReflectsPresence(): void {
		$this->assertFalse($this->cache->has('k'));
		$this->cache->set('k', 'v');
		$this->assertTrue($this->cache->has('k'));
	}

	public function testDeleteRemovesEntryAndIsIdempotent(): void {
		$this->cache->set('k', 'v');
		$this->assertTrue($this->cache->delete('k'));
		$this->assertFalse($this->cache->has('k'));
		$this->assertTrue($this->cache->delete('k'), 'deleting a missing key is a no-op success');
	}

	public function testFlushClearsEverything(): void {
		$this->cache->set('a', 1);
		$this->cache->set('b', 2);
		$this->assertTrue($this->cache->flush());
		$this->assertFalse($this->cache->has('a'));
		$this->assertFalse($this->cache->has('b'));
	}

	public function testMaxAgeExpiresStaleEntries(): void {
		$this->cache->set('k', 'v');
		// Backdate the file 100s so age is deterministic.
		touch($this->cache->getPath('k'), time() - 100);

		$this->assertFalse($this->cache->get('k', 50), 'age 100 >= maxAge 50 → expired');
		$this->assertFalse($this->cache->has('k', 50));
		$this->assertSame('v', $this->cache->get('k', 200), 'age 100 < maxAge 200 → fresh');
		$this->assertTrue($this->cache->has('k', 200));
	}

	public function testPathAndAgeAccessors(): void {
		$this->assertSame(rtrim($this->dir, '/') . '/', $this->cache->getBasePath());
		$this->assertSame($this->cache->getBasePath() . 'k', $this->cache->getPath('k'));

		$this->assertFalse($this->cache->getAge('missing'));
		$this->cache->set('k', 'v');
		$this->assertLessThanOrEqual(2, $this->cache->getAge('k'), 'fresh entry age ~0');
	}
}
