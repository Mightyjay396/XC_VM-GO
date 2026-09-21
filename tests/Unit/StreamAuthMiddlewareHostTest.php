<?php

use PHPUnit\Framework\TestCase;
use XcVm\Streaming\Auth\StreamAuthMiddleware;

/**
 * Coverage for StreamAuthMiddleware::uniqueHeaderDomain — the HOST-guarded
 * cookie-domain resolver. It must yield a dotted domain for a host name, and
 * null for an undefined/empty host or a bare IP (a dot-prefixed IP is not a
 * valid cookie domain and previously produced a broken "." domain).
 */
final class StreamAuthMiddlewareHostTest extends TestCase {

	private function domain(): ?string {
		$rM = new ReflectionMethod(StreamAuthMiddleware::class, 'uniqueHeaderDomain');
		$rM->setAccessible(true);
		return $rM->invoke(null);
	}

	protected function setUp(): void {
		if (defined('HOST')) {
			$this->markTestSkipped('HOST constant already defined; $_SERVER path not reachable');
		}
	}

	public function testHostNameYieldsDottedDomain(): void {
		$_SERVER['HTTP_HOST'] = 'cdn.example.com';
		$this->assertSame('.cdn.example.com', $this->domain());
	}

	public function testBareIpYieldsNull(): void {
		$_SERVER['HTTP_HOST'] = '203.0.113.9';
		$this->assertNull($this->domain());
	}

	public function testEmptyHostYieldsNull(): void {
		$_SERVER['HTTP_HOST'] = '';
		$this->assertNull($this->domain());
	}
}
