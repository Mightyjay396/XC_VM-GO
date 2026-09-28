<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\ClusterSettings;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ClusterPolicy;
use XcVm\Domain\Cluster\LeaseService;
use XcVm\Domain\Cluster\TokenService;
use XcVm\Public\Controllers\Admin\ClusterNodesController;

/**
 * ClusterSettings::int(): how every reader takes an integer setting, so a
 * default or a bound lives in INTS alone. A number clamps as normalize()
 * stores it; unset or not a number is the default; 0 is the default only for
 * ZERO_IS_UNSET.
 */
final class ClusterSettingsIntTest extends TestCase {
	public function testUnsetOrNotANumberIsTheDefault(): void {
		foreach (ClusterSettings::INTS as $rKey => [$rDefault]) {
			foreach ([null, '', 'lots', [], true] as $rValue) {
				$this->assertSame($rDefault, ClusterSettings::int($rKey, $rValue), $rKey . ' ' . var_export($rValue, true));
			}
		}
	}

	public function testANumberIsWhatNormalizeStores(): void {
		foreach (ClusterSettings::INTS as $rKey => [, $rMin, $rMax]) {
			foreach ([$rMin - 1, $rMin, $rMax, $rMax + 1, -5, 1, 7, 45, 9000, (string) $rMax, '3', ' 12', 2.9] as $rValue) {
				if ((int) $rValue === 0 && in_array($rKey, ClusterSettings::ZERO_IS_UNSET, true)) {
					continue;
				}
				[$rOut] = ClusterSettings::normalize([$rKey => $rValue], [], [], ['extension_ok' => true]);
				$this->assertSame($rOut[$rKey], ClusterSettings::int($rKey, $rValue), $rKey . ' ' . var_export($rValue, true));
			}
		}
	}

	public function testZeroIsTheFloorUnlessItMeansUnset(): void {
		$this->assertSame(5, ClusterSettings::int('lb_token_rotation_min', 0), 'a stored 0 clamps, as a save of 0 does');
		$this->assertSame(1, ClusterSettings::int('cluster_ingest_concurrency', '0'));
		$this->assertSame(0, ClusterSettings::int('lb_partition_tolerance_h', 0), '0 is in range');
		$this->assertSame(0, ClusterSettings::int('lb_fence_drain_min', '0'));
		foreach ([0, '0', '0.0', 0.4] as $rValue) {
			$this->assertSame(30, ClusterSettings::int('cluster_offline_after_sec', $rValue), var_export($rValue, true));
			$this->assertSame(2, ClusterSettings::int('lb_telemetry_interval_sec', $rValue), var_export($rValue, true));
		}
	}

	public function testZeroIsUnsetOnlyWhereZeroIsOutOfRange(): void {
		foreach (ClusterSettings::ZERO_IS_UNSET as $rKey) {
			$this->assertArrayHasKey($rKey, ClusterSettings::INTS);
			[$rDefault, $rMin, $rMax] = ClusterSettings::INTS[$rKey];
			$this->assertGreaterThan(0, $rMin, $rKey . ': a 0 in range is a value, never unset');
			$this->assertSame($rDefault, ClusterSettings::clampInt($rKey, $rDefault), $rKey . ': the default is in range');
			$this->assertLessThanOrEqual($rMax, $rDefault);
		}
	}

	public function testTheDefaultsAndBoundsTheReadersUsed(): void {
		// What the call sites hard-coded before they read INTS through int().
		$this->assertSame([30, 10, 300], ClusterSettings::INTS['cluster_offline_after_sec']);
		$this->assertSame([60, 5, 1440], ClusterSettings::INTS['lb_token_rotation_min']);
		$this->assertSame([10, 0, 60], ClusterSettings::INTS['lb_fence_drain_min']);
		$this->assertSame(120, ClusterSettings::INTS['cluster_orphan_conn_ttl_sec'][0]);
	}

	public function testANullColumnReadsAsTheDefault(): void {
		// The columns are nullable. UsersCronJob's getInt() used to pass a
		// NULL on as 0 (an immediate orphan purge); the cleanup pass used to
		// clamp intval(NULL) up to the 1-day floor. Both now take the default.
		$this->assertSame(120, ClusterSettings::int('cluster_orphan_conn_ttl_sec', null));
		$this->assertSame(30, ClusterSettings::int('servers_stats_retention_days', null));
		$this->assertSame(30, ClusterSettings::int('cluster_audit_retention_days', null));
		$this->assertSame(30, ClusterSettings::int('cluster_orphan_conn_ttl_sec', 0), 'a hand-edited 0: clamped to the floor');
	}

	public function testEachReaderTakesItsOwnKey(): void {
		// Every key holds a different out-of-range value, so a reader that
		// took the wrong key, or skipped int(), returns a different number.
		$rSaved = SettingsManager::getAll();
		SettingsManager::set(['lb_token_rotation_min' => 2, 'lb_partition_tolerance_h' => 99, 'cluster_offline_after_sec' => 7, 'lb_telemetry_interval_sec' => 9]);
		try {
			$this->assertSame(5, TokenService::rotationMin());
			$this->assertSame(24, LeaseService::toleranceHours());
			SettingsManager::set([]);
			$this->assertSame(60, TokenService::rotationMin(), 'unset: the INTS default');
			$this->assertSame(12, LeaseService::toleranceHours(), 'unset: the INTS default');
		} finally {
			SettingsManager::set($rSaved);
		}
		$rSettings = ['cluster_offline_after_sec' => 7, 'lb_telemetry_interval_sec' => 9];
		$this->assertSame(10, ClusterNodesController::offlineAfter($rSettings));
		$this->assertSame(3, ClusterPolicy::heartbeatSec($rSettings));
		$this->assertSame(30, ClusterNodesController::offlineAfter([]));
		$this->assertSame(2, ClusterPolicy::heartbeatSec([]), 'unset: the default, not the 1 s floor');
	}
}
