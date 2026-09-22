<?php

use PHPUnit\Framework\TestCase;
use XcVm\Public\Controllers\Reseller\ResellerDashboardController;

/**
 * ResellerDashboardController::buildConnectionMap — the top-countries panel and
 * the jsvectormap fills are both driven by what this returns.
 */
final class ResellerDashboardConnectionMapTest extends TestCase {

	public function testDecoratesRowsWithNameColourAndTotal(): void {
		[$map, $total] = ResellerDashboardController::buildConnectionMap([
			['geoip_country_code' => 'DE', 'count' => 30],
			['geoip_country_code' => 'eg', 'count' => 12],
		], false);

		$this->assertSame(42, $total);
		$this->assertSame('Germany', $map[0]['name']);
		$this->assertSame('Egypt', $map[1]['name']);
		// Lower-cased GeoIP codes are normalised so the map fills still match.
		$this->assertSame('EG', $map[1]['geoip_country_code']);
		// Each row gets its own [hex, bg class] pair, in list order.
		$this->assertSame(['#23b397', 'bg-success'], $map[0]['colour']);
		$this->assertSame(['#56c2d6', 'bg-info'], $map[1]['colour']);
	}

	public function testDropsRowsGeoipCouldNotResolve(): void {
		[$map, $total] = ResellerDashboardController::buildConnectionMap([
			['geoip_country_code' => '', 'count' => 99],
			['geoip_country_code' => null, 'count' => 5],
			['geoip_country_code' => 'FR', 'count' => 7],
		], false);

		$this->assertCount(1, $map);
		$this->assertSame(7, $total);
		$this->assertSame('FR', $map[0]['geoip_country_code']);
		// The surviving row still takes the first colour: unresolved rows must not
		// consume a slot, or the map fills drift out of step with the bars.
		$this->assertSame(['#23b397', 'bg-success'], $map[0]['colour']);
	}

	public function testUnknownCodeKeepsAPlaceholderName(): void {
		[$map] = ResellerDashboardController::buildConnectionMap([
			['geoip_country_code' => 'A1', 'count' => 3],
		], false);

		$this->assertSame('Unknown Country', $map[0]['name']);
	}

	public function testColourListRepeatsItsLastEntryOnceExhausted(): void {
		$rows = [];
		foreach (['DE', 'FR', 'IT', 'ES', 'PL', 'NL', 'BE', 'AT'] as $rCode) {
			$rows[] = ['geoip_country_code' => $rCode, 'count' => 1];
		}
		[$map] = ResellerDashboardController::buildConnectionMap($rows, false);

		$this->assertSame(['#98a6ad', 'bg-secondary'], $map[5]['colour']);
		$this->assertSame($map[5]['colour'], $map[7]['colour']);
	}

	public function testDarkThemeUsesTheDarkColourRamp(): void {
		[$map] = ResellerDashboardController::buildConnectionMap([
			['geoip_country_code' => 'DE', 'count' => 1],
		], true);

		$this->assertSame(['#7e8e9d', 'bg-map-dark-1'], $map[0]['colour']);
	}
}
