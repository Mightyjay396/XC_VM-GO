<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\NodeLease;
use XcVm\Tests\Support\NodeLeaseFixture;

/**
 * NodeLease and the extension's compiled lease verdict (ADR 0004, Phase 9;
 * xcvm_core ADR-002 "Lease verdict on a node"): consulted when the extension
 * offers it, kept for 2 s like the agent's file, fed the agent's newest lease,
 * and never needed — without it (the extension absent, as in this suite, or
 * too old) the agent's file decides as before.
 */
final class LeaseVerdictCacheTest extends TestCase {
	use NodeLeaseFixture;

	protected function setUp(): void {
		$this->leaseSetUp();
	}

	protected function tearDown(): void {
		$this->leaseTearDown();
	}

	public function testWithTheExtensionAbsentTheAgentsFileDecides(): void {
		if (class_exists('XC_VM') && method_exists('XC_VM', 'cluster_lease_state')) {
			$this->markTestSkipped('this PHP loads an xcvm_core that offers the verdict');
		}
		NodeLease::useExtension(null); // the real \XC_VM, which is not loaded here
		$this->agentFile(-5);
		$rVerdict = NodeLease::verdict();
		$this->assertSame([NodeLease::DRAINING, 'agent'], [$rVerdict['state'], $rVerdict['source']]);
	}

	public function testAnExtensionWithoutAVerdictFallsBack(): void {
		$this->withExtension();
		$this->rCompiledState = self::compiled('none', 0, 0, 0, 'NOT_PINNED');
		$this->agentFile(600);
		$rVerdict = NodeLease::verdict();
		$this->assertSame([NodeLease::SERVING, 'agent'], [$rVerdict['state'], $rVerdict['source']]);
		$this->assertSame(['cluster_lease_state'], $this->asked(), 'nothing is offered without a pin');
	}

	public function testALiveCompiledVerdictDecidesOverTheAgentsFile(): void {
		$this->withExtension();
		$rNow = time();
		$this->rCompiledState = self::compiled('live', $rNow + 600, $rNow, $rNow - 60);
		$this->agentFile(-3600); // the agent's file would fence
		$rVerdict = NodeLease::verdict();
		$this->assertSame([NodeLease::SERVING, 'extension', $rNow + 600], [$rVerdict['state'], $rVerdict['source'], $rVerdict['exp']]);
		$this->assertSame($rNow + 600 + 600, $rVerdict['drain_until']);
	}

	public function testAnExpiredCompiledVerdictDrainsThenFences(): void {
		$this->withExtension();
		$rNow = time();
		$this->agentFile(3600); // the agent's file would serve
		$this->rCompiledState = self::compiled('expired', $rNow - 5, $rNow, $rNow - 3600);
		$this->assertSame(NodeLease::DRAINING, NodeLease::state());
		NodeLease::usePath($this->rDir . '/lease_state.json');
		$this->rCompiledState = self::compiled('expired', $rNow - 601, $rNow, $rNow - 3600);
		$this->assertSame(NodeLease::FENCED, NodeLease::state());
	}

	public function testTheVerdictIsReadOnceEveryTwoSeconds(): void {
		$this->withExtension();
		$rNow = time();
		$this->rCompiledState = self::compiled('live', $rNow + 600, $rNow, $rNow);
		for ($i = 0; $i < 50; $i++) {
			NodeLease::refusesNewSessions();
			NodeLease::refusesEverything();
		}
		$this->assertLessThanOrEqual(2, count($this->asked()), 'at most one read per 2 s window (two if a second boundary fell inside the loop)');
	}

	public function testTheSwitchOffOrALegacyNodeAsksNothing(): void {
		$this->withExtension();
		\XcVm\Core\Config\SettingsManager::set(['lb_fence_drain_min' => 10]);
		NodeLease::verdict();
		$this->assertSame([], $this->asked());
	}

	public function testTheAgentsNewerLeaseIsOfferedOnceWithItsExactBytes(): void {
		$this->withExtension();
		$rNow = time();
		$this->agentLease($rNow - 10, '{"v":1,"typ":"xcvm-lease"}', str_repeat("\x01", 64));
		$this->rCompiledState = self::compiled('none', 0, 0, 0, 'NO_LEASE');
		$this->rStoreAnswer = self::compiled('live', $rNow + 3600, $rNow - 10, $rNow - 10);
		$rVerdict = NodeLease::verdict();
		$this->assertSame([NodeLease::SERVING, 'extension'], [$rVerdict['state'], $rVerdict['source']]);
		$this->assertSame(['cluster_lease_state', 'cluster_lease_store'], $this->asked());
		$this->assertSame(['{"v":1,"typ":"xcvm-lease"}', str_repeat("\x01", 64), '0f8fad5b-d9cb-469f-a165-70867728950e'], $this->rCalls[1][1]);
	}

	public function testALeaseTheExtensionRefusedIsNotOfferedAgain(): void {
		$this->withExtension();
		$rNow = time();
		$this->agentLease($rNow - 10);
		$this->rCompiledState = self::compiled('none', 0, 0, 0, 'NO_LEASE');
		$this->rStoreAnswer = false; // e.g. RECORD:stale, EXPIRED
		$this->agentFile(600);
		$this->assertSame('agent', NodeLease::verdict()['source']);
		NodeLease::usePath($this->rDir . '/lease_state.json'); // the next 2 s window
		NodeLease::verdict();
		$this->assertSame(['cluster_lease_state', 'cluster_lease_store', 'cluster_lease_state'], $this->asked());
	}

	public function testALeaseNoNewerThanTheOneHeldIsNotOffered(): void {
		$this->withExtension();
		$rNow = time();
		$this->agentLease($rNow - 10);
		$this->rCompiledState = self::compiled('live', $rNow + 3600, $rNow, $rNow - 10);
		NodeLease::verdict();
		$this->assertSame(['cluster_lease_state'], $this->asked());
	}

	public function testAnExtensionThatThrowsIsNoVerdict(): void {
		NodeLease::useExtension(static fn(string $rMethod): mixed => throw new \RuntimeException('boom'), $this->rDir . '/agent.json');
		$this->agentFile(600);
		$this->assertSame('agent', NodeLease::verdict()['source']);
	}
}
