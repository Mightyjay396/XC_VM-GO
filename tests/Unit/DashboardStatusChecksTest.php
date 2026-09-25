<?php

use PHPUnit\Framework\TestCase;
use XcVm\Public\Controllers\Admin\DashboardController;

/**
 * DashboardController status checks — the "Service Status" checklist rows.
 * Assertions target state/help, not wording, so they survive translation edits.
 */
final class DashboardStatusChecksTest extends TestCase {

	private const BIN = ['{bin}' => 'php'];
	private const NOW = 1_800_000_000;

	public function testServersOkWhenEveryEnabledServerIsOnline(): void {
		$check = DashboardController::serversCheck([
			['server_name' => 'Main', 'enabled' => 1, 'server_online' => 1],
			// Disabled servers are expected to be offline and must not count.
			['server_name' => 'Spare', 'enabled' => 0, 'server_online' => 0],
		]);

		$this->assertSame('ok', $check['state']);
		$this->assertSame('', $check['help']);
	}

	public function testServersFailNamesTheOfflineOnes(): void {
		$check = DashboardController::serversCheck([
			['server_name' => 'Main', 'enabled' => 1, 'server_online' => 1],
			['server_name' => 'LB-2', 'enabled' => 1, 'server_online' => 0],
		]);

		$this->assertSame('fail', $check['state']);
		$this->assertStringContainsString('LB-2', $check['detail']);
		$this->assertStringNotContainsString('Main', $check['detail']);
	}

	public function testSchemaOkOnlyWhenWatermarkMatchesVersion(): void {
		$this->assertSame('ok', DashboardController::schemaCheck(md5('2.5.3'), '2.5.3', self::BIN)['state']);

		$stale = DashboardController::schemaCheck(md5('2.5.2'), '2.5.3', self::BIN);
		$this->assertSame('warn', $stale['state']);
		$this->assertNotSame('', $stale['help']);

		$this->assertSame('warn', DashboardController::schemaCheck('', '2.5.3', self::BIN)['state']);
	}

	public function testCronFreshWithinTenMinutes(): void {
		$this->assertSame('ok', DashboardController::cronCheck(self::NOW - 600, self::NOW, self::BIN)['state']);

		$stale = DashboardController::cronCheck(self::NOW - 601, self::NOW, self::BIN);
		$this->assertSame('fail', $stale['state']);
		$this->assertNotSame('', $stale['help']);
	}

	public function testCronNeverRanFails(): void {
		$check = DashboardController::cronCheck(null, self::NOW, self::BIN);

		$this->assertSame('fail', $check['state']);
		$this->assertNotSame('', $check['help']);
	}

	public function testFanoutDisabledIsOffNotFailure(): void {
		$check = DashboardController::fanoutCheck(false, [$this->fanoutServer('Main', false)], self::NOW, self::BIN);

		$this->assertSame('off', $check['state']);
		$this->assertSame('', $check['help']);
	}

	public function testFanoutFailsWhenAnyFreshServerReportsDown(): void {
		$check = DashboardController::fanoutCheck(true, [
			$this->fanoutServer('Main', true),
			$this->fanoutServer('LB-2', false),
		], self::NOW, self::BIN);

		$this->assertSame('fail', $check['state']);
		$this->assertNotSame('', $check['help']);
	}

	public function testFanoutIgnoresStaleWatchdogReports(): void {
		$check = DashboardController::fanoutCheck(true, [
			$this->fanoutServer('Main', true),
			// Last heartbeat two minutes ago: its "down" is not current evidence.
			$this->fanoutServer('LB-2', false, self::NOW - 120),
		], self::NOW, self::BIN);

		$this->assertSame('ok', $check['state']);
	}

	public function testFanoutWithoutReportsIsOff(): void {
		$check = DashboardController::fanoutCheck(true, [
			['server_name' => 'Main', 'watchdog_data' => '{}', 'last_check_ago' => self::NOW],
		], self::NOW, self::BIN);

		$this->assertSame('off', $check['state']);
	}

	/** @return array<string,mixed> */
	private function fanoutServer(string $name, bool $running, int $lastCheck = self::NOW): array {
		return ['server_name' => $name, 'watchdog_data' => json_encode(['fanout' => ['running' => $running]]), 'last_check_ago' => $lastCheck];
	}
}
