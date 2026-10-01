<?php

use XcVm\Cli\Commands\UpdateCommand;
use PHPUnit\Framework\TestCase;

/**
 * UpdateCommand::pinned() — the release MAIN names to a load balancer.
 *
 * A MAIN on the dev channel names its nightly (x.y.z-dev.N); dropping it sent
 * the LB to its own channel lookup instead of MAIN's build.
 */
class UpdateCommandPinnedTest extends TestCase {
	public function testAcceptsReleaseAndNightlyTags(): void {
		$this->assertSame('1.2.3', UpdateCommand::pinned(' 1.2.3 '));
		$this->assertSame('1.2.3-dev.7', UpdateCommand::pinned('1.2.3-dev.7'));
	}

	public function testRejectsAnythingElse(): void {
		foreach ([null, '', '1.2', '1.2.3-beta.1', '1.2.3-dev', '1.2.3;id', 123] as $rBad) {
			$this->assertNull(UpdateCommand::pinned($rBad));
		}
	}
}
