<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\NodeLease;
use XcVm\Tests\Support\NodeLeaseFixture;

/**
 * The clock a node's lease is judged on never runs backwards (ADR 0004,
 * Phase 9). With the extension's verdict it is the extension's anchor
 * (monotonic, only moved forward by a verified lease; its own tests live in
 * xcvm_core). Without it — the extension absent, as here — it is the agent's
 * anchor plus the time since the agent wrote it, never less than the anchor:
 * a node whose clock was set back gains no time.
 */
final class TimeAnchorMonotoneTest extends TestCase {
	use NodeLeaseFixture;

	protected function setUp(): void {
		$this->leaseSetUp();
	}

	protected function tearDown(): void {
		$this->leaseTearDown();
	}

	public function testAClockSetBackLeavesTheAnchorWhereTheAgentPutIt(): void {
		$rAnchorMs = (int) round(microtime(true) * 1000);
		// Written "in the future": this machine's clock went back 30 s after the
		// agent wrote the file.
		$this->agentFile(-1, -30000, $rAnchorMs);
		$rVerdict = NodeLease::verdict();
		$this->assertSame(intdiv($rAnchorMs, 1000), $rVerdict['anchor'], 'never below what the agent vouched for');
		$this->assertSame(NodeLease::DRAINING, $rVerdict['state'], 'the lease ended on MAIN\'s clock, whatever this one says');
	}

	public function testTheAnchorAdvancesWithTheTimeSinceTheAgentWrote(): void {
		$rAnchorMs = (int) round(microtime(true) * 1000) - 20000;
		$this->agentFile(10, 20000, $rAnchorMs); // 20 s old, the lease had 10 s left
		$rVerdict = NodeLease::verdict();
		$this->assertGreaterThanOrEqual(intdiv($rAnchorMs, 1000) + 19, $rVerdict['anchor']);
		$this->assertSame(NodeLease::DRAINING, $rVerdict['state']);
	}

	public function testAClockSetForwardMakesTheFileStaleWhichServes(): void {
		// The fallback's limit, kept on purpose: a stale file means the agent is
		// not running, and a stopped agent must not take a fleet off the air.
		$this->agentFile(-86400, (NodeLease::STALE_SEC + 5) * 1000);
		$this->assertSame(NodeLease::SERVING, NodeLease::state());
	}

	public function testWithTheExtensionTheAnchorIsItsOwn(): void {
		$this->withExtension();
		$rMain = time() - 7200; // the extension's anchor, two hours behind this clock
		$this->rCompiledState = self::compiled('live', $rMain + 600, $rMain, $rMain - 60);
		$this->agentFile(-3600);
		$rVerdict = NodeLease::verdict();
		$this->assertSame('extension', $rVerdict['source']);
		$this->assertGreaterThanOrEqual($rMain, $rVerdict['anchor']);
		$this->assertLessThanOrEqual($rMain + 2, $rVerdict['anchor'], 'moved only by the monotonic time since the read, at most 2 s');
		$this->assertSame(NodeLease::SERVING, $rVerdict['state']);
	}
}
