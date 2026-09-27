<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\AgentClient;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Streaming\Lifecycle\ShutdownHandler;

/**
 * A viewer's request ends: the HLS close belongs in this node's own registry
 * where its agent holds it (CONNECTIONS on), and in MAIN's store otherwise.
 * What must never happen is the close being dropped: without an answer from the
 * agent the caller falls back, so it is the false answer that is tested here
 * (the agent's own half is AgentAdmissionTest's).
 */
final class ShutdownCloseTest extends TestCase {

	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-shutdown-' . bin2hex(random_bytes(4));
		mkdir($this->rDir);
		AgentClient::useSocket($this->rDir . '/agent.sock');
	}

	protected function tearDown(): void {
		NodeFlows::usePath(null);
		AgentClient::useSocket(null);
		array_map('unlink', glob($this->rDir . '/*') ?: []);
		rmdir($this->rDir);
	}

	private function flows(int $rFlows): void {
		file_put_contents($this->rDir . '/flows.json', (string) json_encode(['mode' => 1, 'flows' => $rFlows, 'state' => 'active']));
		NodeFlows::usePath($this->rDir . '/flows.json');
	}

	public function testWithoutTheConnectionsFlowTheCloseIsMainsToWrite(): void {
		$this->flows(NodeFlows::STREAMS);
		$this->assertFalse(ShutdownHandler::closeInRegistry('u-1', 42, 1700000000));
	}

	public function testAnAgentThatDoesNotAnswerLeavesTheCloseToMain(): void {
		// CONNECTIONS on, no agent on the socket: the record cannot be read, so
		// the caller must write MAIN's store rather than count it closed.
		$this->flows(NodeFlows::CONNECTIONS | NodeFlows::COMMANDS | NodeFlows::STREAMS);
		$this->assertFalse(ShutdownHandler::closeInRegistry('u-1', 42, 1700000000));
	}

	public function testAViewerWithoutAUuidIsNotAsked(): void {
		$this->flows(NodeFlows::CONNECTIONS | NodeFlows::COMMANDS | NodeFlows::STREAMS);
		$this->assertFalse(ShutdownHandler::closeInRegistry('', 42, 1700000000));
	}
}
