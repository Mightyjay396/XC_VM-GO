<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\NodeLease;
use XcVm\Tests\Support\NodeLeaseFixture;

/**
 * A MAIN whose clock was set back cannot lengthen a node's lease (ADR 0004,
 * Phase 9). MAIN's own extension refuses to issue with its clock more than
 * 5 minutes behind its high-water; a lease it issued anyway carries an older
 * `iat`, which the node's extension refuses as stale and which never pulls its
 * anchor back (xcvm_core's MainClockRollback tests). Here: the node's PHP
 * follows the compiled verdict over the agent's file, whose anchor re-bases on
 * whatever MAIN last said — and without the extension, the agent's file is
 * all there is (the fallback's documented limit).
 */
final class MainClockRollbackTest extends TestCase {
	use NodeLeaseFixture;

	protected function setUp(): void {
		$this->leaseSetUp();
	}

	protected function tearDown(): void {
		$this->leaseTearDown();
	}

	public function testTheCompiledVerdictOutranksAnAgentAnchorPulledBack(): void {
		$this->withExtension();
		$rNow = time();
		// MAIN's clock went back two hours: the agent re-anchored on it, and by
		// that anchor the lease has an hour left.
		$this->agentFile(3600, 0, ($rNow - 7200) * 1000);
		// The extension's anchor did not move back: the lease ended 5 s ago.
		$this->rCompiledState = self::compiled('expired', $rNow - 5, $rNow, $rNow - 3600);
		$rVerdict = NodeLease::verdict();
		$this->assertSame([NodeLease::DRAINING, 'extension'], [$rVerdict['state'], $rVerdict['source']]);
	}

	public function testAnOlderLeaseFromARolledBackMainIsNotOffered(): void {
		$this->withExtension();
		$rNow = time();
		// The agent kept a lease MAIN issued after its clock went back: older than
		// the one the extension holds.
		$this->agentLease($rNow - 7200);
		$this->rCompiledState = self::compiled('live', $rNow + 600, $rNow, $rNow - 60);
		$this->assertSame(NodeLease::SERVING, NodeLease::state());
		$this->assertSame(['cluster_lease_state'], $this->asked(), 'never handed to the extension');
	}

	public function testWithoutTheExtensionTheAgentsAnchorDecides(): void {
		// Extension absent: the fallback. The agent's anchor is where MAIN last
		// put it; a rolled-back MAIN moves it, which is why the compiled verdict
		// is preferred wherever it exists.
		$rNow = time();
		$this->agentFile(3600, 0, ($rNow - 7200) * 1000);
		$rVerdict = NodeLease::verdict();
		$this->assertSame([NodeLease::SERVING, 'agent'], [$rVerdict['state'], $rVerdict['source']]);
		$this->agentFile(-5, 0, $rNow * 1000);
		$this->assertSame(NodeLease::DRAINING, NodeLease::state());
	}
}
