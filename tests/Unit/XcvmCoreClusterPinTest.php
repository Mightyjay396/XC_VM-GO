<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\XcvmCoreCommand;

/**
 * An xcvm_core update must not drop the cluster API this panel speaks: the
 * installer rolls back a new .so whose API is outside
 * ClusterCryptoFactory::API_MIN..API_MAX when the old one was inside it.
 */
final class XcvmCoreClusterPinTest extends TestCase {
	public function testKeepsASupportedApi(): void {
		$this->assertTrue(XcvmCoreCommand::clusterApiKept(1, 1));
		$this->assertFalse(XcvmCoreCommand::clusterApiKept(1, 0), 'an extension without the cluster API');
		$this->assertFalse(XcvmCoreCommand::clusterApiKept(1, 2), 'an API this panel does not speak yet');
	}

	public function testNothingToLoseWhenNothingWorkedBefore(): void {
		$this->assertTrue(XcvmCoreCommand::clusterApiKept(0, 0));
		$this->assertTrue(XcvmCoreCommand::clusterApiKept(0, 1));
		$this->assertTrue(XcvmCoreCommand::clusterApiKept(2, 0));
	}
}
