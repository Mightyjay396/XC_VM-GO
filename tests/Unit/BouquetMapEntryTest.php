<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Bouquet\BouquetService;

/**
 * BouquetService::getMapEntry() — the stream → bouquets lookup stream auth
 * checks every playback against. It is memoised per request, so it must still
 * see a rebuilt map file.
 *
 * @covers BouquetService
 */
final class BouquetMapEntryTest extends TestCase {

	private string $rPath;

	private ?string $rSaved = null;

	protected function setUp(): void {
		if (!defined('CACHE_TMP_PATH')) {
			$dir = sys_get_temp_dir() . '/xcvm_bouquet_map_test/';
			@mkdir($dir, 0755, true);
			define('CACHE_TMP_PATH', $dir);
		}
		@mkdir(CACHE_TMP_PATH, 0755, true);
		$this->rPath = CACHE_TMP_PATH . 'bouquet_map';
		if (is_file($this->rPath)) {
			$this->rSaved = file_get_contents($this->rPath);
		}
	}

	protected function tearDown(): void {
		if ($this->rSaved !== null) {
			file_put_contents($this->rPath, $this->rSaved);
		} else {
			@unlink($this->rPath);
		}
	}

	private function writeMap(array $rMap, int $rMtime): void {
		file_put_contents($this->rPath, igbinary_serialize($rMap));
		touch($this->rPath, $rMtime);
	}

	public function testReturnsTheBouquetsHoldingAStream(): void {
		$this->writeMap([5 => [1, 2]], time() - 100);
		$this->assertSame([1, 2], BouquetService::getMapEntry(5));
		$this->assertSame([], BouquetService::getMapEntry(9));
	}

	public function testSeesARebuiltMap(): void {
		$this->writeMap([5 => [1]], time() - 50);
		$this->assertSame([1], BouquetService::getMapEntry(5));
		// The cache pass rewrites the file: the memo must not keep the old answer.
		$this->writeMap([5 => [3]], time() - 10);
		$this->assertSame([3], BouquetService::getMapEntry(5));
	}
}
