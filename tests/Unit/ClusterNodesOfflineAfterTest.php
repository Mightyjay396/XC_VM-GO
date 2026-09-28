<?php

use PHPUnit\Framework\TestCase;
use XcVm\Public\Controllers\Admin\ClusterNodesController;

/**
 * The Cluster Nodes page reads cluster_offline_after_sec as every other
 * reader does, through ClusterSettings::int() (0 or unset is 30, within
 * 10–300). It used `?? 30` alone, so a stored 0 showed nodes offline after
 * 10 s while the servers list, the dashboard and the liveness tick waited 30 s.
 */
final class ClusterNodesOfflineAfterTest extends TestCase {
	public function testUnsetOrZeroIsThirtySeconds(): void {
		foreach ([[], ['cluster_offline_after_sec' => null], ['cluster_offline_after_sec' => 0], ['cluster_offline_after_sec' => '0'], ['cluster_offline_after_sec' => '']] as $rSettings) {
			$this->assertSame(30, ClusterNodesController::offlineAfter($rSettings), var_export($rSettings, true));
		}
	}

	public function testAValueIsKeptWithinTenToThreeHundred(): void {
		$this->assertSame(45, ClusterNodesController::offlineAfter(['cluster_offline_after_sec' => '45']));
		$this->assertSame(10, ClusterNodesController::offlineAfter(['cluster_offline_after_sec' => 3]));
		$this->assertSame(10, ClusterNodesController::offlineAfter(['cluster_offline_after_sec' => -5]));
		$this->assertSame(300, ClusterNodesController::offlineAfter(['cluster_offline_after_sec' => 9000]));
	}

	public function testItMatchesTheOtherReaders(): void {
		foreach ([[null, 30], [0, 30], ['0', 30], [3, 10], [45, 45], [9000, 300]] as [$rValue, $rExpected]) {
			$this->assertSame(
				$rExpected,
				ClusterNodesController::offlineAfter(['cluster_offline_after_sec' => $rValue]),
				var_export($rValue, true)
			);
		}
	}
}
