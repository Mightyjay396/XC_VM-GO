<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\Crypto\Canonical;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Domain\Cluster\EnrolmentService;
use XcVm\Domain\Cluster\NodeRegistry;

/**
 * What the cluster code keeps in one place: the flow bits (NodeFlows, which
 * LBs have too; NodeRegistry's are the same) and the node uuid's pattern
 * (Canonical::NODE_UUID, which every check of one uses).
 */
final class ClusterSharedConstantsTest extends TestCase {
	public function testTheRegistrysFlowBitsAreNodeFlows(): void {
		$this->assertSame(
			[1, 2, 4, 8, 16, 32, 64, 128],
			[NodeFlows::TELEMETRY, NodeFlows::COMMANDS, NodeFlows::LOGS, NodeFlows::STREAMS, NodeFlows::CONTENT, NodeFlows::CONFIG, NodeFlows::CONNECTIONS, NodeFlows::DATAPLANE]
		);
		$this->assertSame(
			[NodeFlows::TELEMETRY, NodeFlows::COMMANDS, NodeFlows::LOGS, NodeFlows::STREAMS, NodeFlows::CONTENT, NodeFlows::CONFIG, NodeFlows::CONNECTIONS, NodeFlows::DATAPLANE],
			[NodeRegistry::FLOW_TELEMETRY, NodeRegistry::FLOW_COMMANDS, NodeRegistry::FLOW_LOGS, NodeRegistry::FLOW_STREAMS, NodeRegistry::FLOW_CONTENT, NodeRegistry::FLOW_CONFIG, NodeRegistry::FLOW_CONNECTIONS, NodeRegistry::FLOW_DATAPLANE]
		);
	}

	public function testANodeUuidIsLowerCaseHexAndNothingElse(): void {
		$rGood = '0f8e2b1c-3d4a-4b5c-8d6e-7f8091a2b3c4';
		$rBad = ['', $rGood . "\n", strtoupper($rGood), ' ' . $rGood, substr($rGood, 1), $rGood . '0', str_replace('-', '', $rGood), 'sid:1', '0f8e2b1c-3d4a-4b5c-8d6e-7f8091a2b3cg'];
		$this->assertTrue(Canonical::validUuid($rGood));
		$this->assertTrue(EnrolmentService::validUuid($rGood));
		$this->assertTrue(Canonical::validNode($rGood));
		foreach ($rBad as $rUuid) {
			$this->assertFalse(Canonical::validUuid($rUuid), var_export($rUuid, true));
			$this->assertFalse(EnrolmentService::validUuid($rUuid), var_export($rUuid, true));
		}
		$this->assertTrue(Canonical::validNode('sid:1'), 'a header may carry a server id before enrolment');
		$this->assertFalse(Canonical::validNode($rGood . "\n"));
		$this->assertFalse(Canonical::validNode('sid:0'));
	}
}
