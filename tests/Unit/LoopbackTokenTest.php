<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\LoopbackToken;
use XcVm\Core\Cluster\RelayGuard;

/**
 * A node's own loopback pull (the recorder reading its own /admin/live and
 * /admin/timeshift) carries a token only that node can mint, for one stream,
 * rather than the fleet's live_streaming_pass; RelayGuard takes it from
 * 127.0.0.1 or ::1 only (ADR 0004, Phase 8).
 */
final class LoopbackTokenTest extends TestCase {
	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-loopback-' . bin2hex(random_bytes(4));
		LoopbackToken::useKeyPath($this->rDir . '/cluster/loopback.key');
	}

	protected function tearDown(): void {
		LoopbackToken::useKeyPath(null);
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	public function testATokenOpensItsStreamOnlyUntilItExpires(): void {
		$this->assertFalse(LoopbackToken::verify(7, 'lb1-9999999999-' . str_repeat('0', 64)), 'no key yet: nothing verifies');
		$rToken = LoopbackToken::issue(7, 1800000000);
		$this->assertIsString($rToken);
		$this->assertSame(0600, fileperms($this->rDir . '/cluster/loopback.key') & 0777, 'the key is the node\'s own');
		$this->assertTrue(LoopbackToken::verify(7, $rToken, 1800000000));
		$this->assertFalse(LoopbackToken::verify(8, $rToken, 1800000000), 'another stream');
		$this->assertFalse(LoopbackToken::verify(7, $rToken, 1800000000 + LoopbackToken::TTL + 1), 'expired');
		$this->assertFalse(LoopbackToken::verify(7, substr($rToken, 0, -1) . (substr($rToken, -1) === '0' ? '1' : '0'), 1800000000), 'tampered');
		$this->assertTrue(LoopbackToken::verify(7, (string) LoopbackToken::issue(7, 1800000000), 1800000000), 'the key is kept, not made again');
	}

	public function testRelayGuardTakesItFromTheNodeItselfOnly(): void {
		$rToken = (string) LoopbackToken::issue(7);
		$this->assertSame(RelayGuard::LOOPBACK, RelayGuard::admit(7, $rToken, '127.0.0.1', []));
		$this->assertSame(RelayGuard::LOOPBACK, RelayGuard::admit(7, $rToken, '::1', []));
		$this->assertNull(RelayGuard::admit(7, $rToken, '10.0.0.4', []), 'from another address: refused, never taken as the password');
		$this->assertNull(RelayGuard::admit(8, $rToken, '127.0.0.1', []), 'another stream');
		$this->assertNull(RelayGuard::admit(7, $rToken, '127.0.0.1', [], false), 'an endpoint that takes no password (thumb)');
	}
}
