<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\GeoIP\GeoIPService;

/**
 * Coverage for GeoIPService::readMmdb — the guard extracted from
 * getIPInfo()/getISP() that stops a missing or corrupt MaxMind database from
 * throwing a fatal (the "white screen" on live streams). It must return false
 * for a missing constant, a missing binary and any reader error.
 */
final class GeoIPServiceTest extends TestCase {

	/** Invoke the private static readMmdb() via reflection. */
	private function readMmdb(string $rBinConst, string $rIP) {
		$rM = new ReflectionMethod(GeoIPService::class, 'readMmdb');
		$rM->setAccessible(true);
		return $rM->invoke(null, $rBinConst, $rIP);
	}

	public function testReturnsFalseWhenConstantUndefined(): void {
		$this->assertFalse($this->readMmdb('XCVM_UNDEFINED_MMDB_CONST', '8.8.8.8'));
	}

	public function testReturnsFalseWhenBinaryMissing(): void {
		define('XCVM_TEST_MMDB_MISSING', '/nonexistent/path/GeoLite2.mmdb');
		$this->assertFalse($this->readMmdb('XCVM_TEST_MMDB_MISSING', '8.8.8.8'));
	}

	public function testReturnsFalseWhenDatabaseIsCorrupt(): void {
		// Point at a real file that is not a MaxMind database: the reader throws,
		// and the catch must swallow it into false rather than fatal the request.
		define('XCVM_TEST_MMDB_BAD', __FILE__);
		$this->assertFalse($this->readMmdb('XCVM_TEST_MMDB_BAD', '8.8.8.8'));
	}
}
