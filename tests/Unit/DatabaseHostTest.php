<?php

use XcVm\Core\Database\Database;
use PHPUnit\Framework\TestCase;

/**
 * Database::normalizeHost — regression for a production CLI-boot crash.
 *
 * The panel constructs `new DatabaseHandler()` with no arguments, so $host is
 * null and the real credentials are resolved by the bundled XC_VM extension in
 * db_connect(). A strict `normalizeHost(string $rHost)` hint made that null a
 * fatal TypeError at boot; the parameter must accept null and pass it through.
 *
 * The class can't be instantiated here (db_connect() needs the XC_VM
 * extension), so the private method is exercised on a constructor-less instance
 * via reflection.
 */
final class DatabaseHostTest extends TestCase {

	private function normalize(?string $host)
	{
		$instance = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
		$method = new ReflectionMethod(Database::class, 'normalizeHost');
		$method->setAccessible(true);
		return $method->invoke($instance, $host);
	}

	public function testNullHostPassesThroughInsteadOfThrowing(): void {
		// Regression: a null host (the no-arg DatabaseHandler() boot path) must
		// not raise a TypeError.
		$this->assertNull($this->normalize(null));
	}

	public function testLocalhostIsForcedToTcpLoopback(): void {
		$this->assertSame('127.0.0.1', $this->normalize('localhost'));
	}

	public function testOtherHostsAreUnchanged(): void {
		$this->assertSame('db.internal', $this->normalize('db.internal'));
		$this->assertSame('10.0.0.5', $this->normalize('10.0.0.5'));
	}
}
