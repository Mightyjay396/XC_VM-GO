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

	// ── Cluster API ──────────────────────────────────────────────────

	public function testClusterOffAndEnrolledNothingAreBothOff(): void {
		$this->assertSame('off', DashboardController::clusterCheck(false, [], [], self::BIN)['state']);
		$this->assertSame('off', DashboardController::clusterCheck(true, [], [], self::BIN)['state'], 'on with no node is not a failure');
	}

	public function testASilentOrStoppedNodeFails(): void {
		$rSilent = DashboardController::clusterCheck(true, [
			$this->node('LB-1', 'active', 'ok'),
			$this->node('LB-2', 'active', 'offline'),
		], [], self::BIN);
		$this->assertSame('fail', $rSilent['state']);
		$this->assertStringContainsString('LB-2', $rSilent['detail']);
		$this->assertStringNotContainsString('LB-1', $rSilent['detail']);
		$this->assertNotSame('', $rSilent['help'], 'a failure says where to look');

		$rStopped = DashboardController::clusterCheck(true, [$this->node('LB-3', 'quarantined', 'quarantined')], [], self::BIN);
		$this->assertSame('fail', $rStopped['state']);
		$this->assertStringContainsString('LB-3', $rStopped['detail']);
	}

	public function testANodeWaitingForADecisionIsAWarningNotAFailure(): void {
		// It is not serving anything yet: nobody's viewers are affected.
		$rCode = DashboardController::clusterCheck(true, [$this->node('LB-1', 'active', 'ok')], [['server_name' => 'LB-9']], self::BIN);
		$this->assertSame('warn', $rCode['state']);
		$this->assertStringContainsString('LB-9', $rCode['detail']);

		$rEnrolling = DashboardController::clusterCheck(true, [$this->node('LB-4', 'enrolling', 'enrolling')], [], self::BIN);
		$this->assertSame('warn', $rEnrolling['state']);

		// A node that missed a heartbeat or two is suspect, not offline.
		$this->assertSame('warn', DashboardController::clusterCheck(true, [$this->node('LB-5', 'active', 'suspect')], [], self::BIN)['state']);
	}

	public function testEveryActiveNodeAnsweringIsOk(): void {
		$rCheck = DashboardController::clusterCheck(true, [
			$this->node('LB-1', 'active', 'ok'),
			$this->node('LB-2', 'active', 'ok'),
			// A revoked node is MAIN's decision, not a fleet failure... but it is
			// stopped, so it is named.
		], [], self::BIN);

		$this->assertSame('ok', $rCheck['state']);
		$this->assertSame('', $rCheck['help'], 'nothing to do, nothing to read');
		$this->assertStringContainsString('2', $rCheck['detail']);
	}

	/** @return array<string,mixed> A ClusterAdmin::nodes() row, as the checklist reads it. */
	private function node(string $name, string $state, string $health): array {
		return ['server_id' => 5, 'server_name' => $name, 'state' => $state, 'health' => $health];
	}
}
