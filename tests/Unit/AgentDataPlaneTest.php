<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\AgentClient;
use XcVm\Core\Cluster\AgentDataPlane;

/**
 * The data plane's two local helpers. What matters on this side is the refusal:
 * a parent that cannot spend a nonce cannot tell a replay from a first attempt,
 * and an owner that cannot have its digest signed must serve nothing — so an
 * agent that does not answer must read as "I cannot prove this", never as yes.
 * The agent's own half is the Go package's (dataplane_test.go).
 */
final class AgentDataPlaneTest extends TestCase {

	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-dataplane-' . bin2hex(random_bytes(4));
		mkdir($this->rDir);
		// No agent listens here.
		AgentClient::useSocket($this->rDir . '/agent.sock');
	}

	protected function tearDown(): void {
		AgentClient::useSocket(null);
		array_map('unlink', glob($this->rDir . '/*') ?: []);
		rmdir($this->rDir);
	}

	public function testWithoutAnAgentNothingIsProven(): void {
		$this->assertNull(AgentDataPlane::nonceFresh(str_repeat('ab', 16)), 'not false: unknown is not "already spent"');
		$this->assertNull(AgentDataPlane::fileDigest('tid_0123456789', 5, 1234, str_repeat('cd', 32)));
	}

	public function testAMalformedNonceIsSpentByNobody(): void {
		// Not even asked: a header that shape never came from RelayAuth.
		$this->assertFalse(AgentDataPlane::nonceFresh('zz'));
		$this->assertFalse(AgentDataPlane::nonceFresh(str_repeat('ab', 15)));
	}

	public function testDigestFieldsAreCheckedBeforeTheAgentIsAsked(): void {
		$this->assertNull(AgentDataPlane::fileDigest('short', 5, 1, str_repeat('cd', 32)), 'ticket id');
		$this->assertNull(AgentDataPlane::fileDigest('tid_0123456789', 5, 1, 'CDCD'), 'sha256 must be lowercase hex, whole');
		$this->assertNull(AgentDataPlane::fileDigest('tid_0123456789', 0, 1, str_repeat('cd', 32)), 'owner');
		$this->assertNull(AgentDataPlane::fileDigest('tid_0123456789', 5, -1, str_repeat('cd', 32)), 'size');
	}
}
