<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\SettingsAudit;
use XcVm\Core\Config\SettingsManager;

/**
 * `audit.settings_misses` on the node (cluster plan, section 9, R1
 * `settings`): on a node in mode 1 or 2, a read through SettingsManager of a
 * key outside lb_settings_keys.php is counted, without a database: per
 * process, merged into a per-day file, reported for the last seven days in
 * the audit.json the agent sends with its heartbeats. Bounded everywhere;
 * MAIN, legacy nodes and mode 0 count nothing.
 */
final class SettingsAuditTest extends TestCase {
	private string $rDir;

	private const NOW = 1800000000; // 2027-01-15 08:00 UTC

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-misses-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir . 'cluster', 0777, true);
		$this->mode(1);
		SettingsManager::set(['server_name' => 'Panel', 'api_pass' => 'x']);
	}

	protected function tearDown(): void {
		SettingsAudit::useDir(false);
		NodeFlows::usePath(null);
		SettingsManager::set([]);
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/** This node's mode as its agent wrote it (null: no agent file), and a process that decides afresh. */
	private function mode(?int $rMode): void {
		@unlink($this->rDir . 'flows.json');
		if ($rMode !== null) {
			file_put_contents($this->rDir . 'flows.json', json_encode(['mode' => $rMode, 'flows' => 63, 'state' => 'active']));
		}
		NodeFlows::usePath($this->rDir . 'flows.json');
		SettingsAudit::useDir($this->rDir . 'misses/', $this->rDir . 'cluster/');
	}

	/** @return array<string, int>|null */
	private function day(int $rAt = self::NOW): ?array {
		$rDay = json_decode((string) @file_get_contents($this->rDir . 'misses/' . gmdate('Ymd', $rAt) . '.json'), true);
		return is_array($rDay) ? $rDay : null;
	}

	/** @return array<string, mixed>|null */
	private function published(): ?array {
		$rDoc = json_decode((string) @file_get_contents($this->rDir . 'cluster/audit.json'), true);
		return is_array($rDoc) ? $rDoc : null;
	}

	public function testAnApiNodeCountsReadsTheReplicaWouldNotAnswer(): void {
		SettingsManager::get('server_name');
		SettingsManager::get('api_pass'); // withheld: a known read, the secret travels apart or not at all
		SettingsManager::get('not_a_setting');
		SettingsManager::getBool('not_a_setting');
		SettingsManager::getInt('another_key', 3);
		SettingsManager::getString('another_key');
		SettingsManager::getArray('third_key');
		SettingsManager::has('third_key');
		SettingsAudit::flush(self::NOW);
		$this->assertSame(['not_a_setting' => 2, 'another_key' => 2, 'third_key' => 2], $this->day());
		$this->assertSame(['settings_misses' => ['another_key' => 2, 'not_a_setting' => 2, 'third_key' => 2]], $this->published(), 'most missed first, then by name');

		// Another process the same day adds to the file.
		SettingsAudit::useDir($this->rDir . 'misses/', $this->rDir . 'cluster/');
		SettingsManager::get('third_key');
		SettingsAudit::flush(self::NOW + 60);
		$this->assertSame(['not_a_setting' => 2, 'another_key' => 2, 'third_key' => 3], $this->day());
		$this->assertSame(['third_key' => 3, 'another_key' => 2, 'not_a_setting' => 2], SettingsAudit::summary(self::NOW + 60));
	}

	public function testMainALegacyNodeAndModeZeroCountNothing(): void {
		foreach ([null, 0] as $rMode) {
			file_put_contents($this->rDir . 'cluster/audit.json', '{"settings_misses":{"old":1}}');
			$this->mode($rMode);
			SettingsManager::get('not_a_setting');
			SettingsAudit::flush(self::NOW);
			$this->assertNull($this->day());
			$this->assertFalse(SettingsAudit::publish(null, self::NOW));
			$this->assertFileDoesNotExist($this->rDir . 'cluster/audit.json', 'nothing counted: nothing for the agent to send');
		}
		// Off (the suite's default): nothing at all, not even a decision.
		SettingsAudit::useDir(false);
		$this->mode(2);
		SettingsAudit::useDir(false, $this->rDir . 'cluster/');
		SettingsManager::get('not_a_setting');
		SettingsAudit::flush(self::NOW);
		$this->assertNull($this->day());
		$this->assertFalse(SettingsAudit::publish(null, self::NOW));
	}

	public function testEveryCountIsBounded(): void {
		for ($i = 0; $i < SettingsAudit::MAX_KEYS + 6; $i++) {
			SettingsManager::get(sprintf('key_%03d', $i));
		}
		SettingsManager::get('Not A Name!');
		SettingsAudit::flush(self::NOW);
		$rDay = $this->day();
		$this->assertCount(SettingsAudit::MAX_KEYS + 1, $rDay);
		$this->assertSame(7, $rDay[SettingsAudit::OTHER], 'past the cap, and a key that is not a name');

		// A day at the cap keeps its names; a new key counts under OTHER.
		SettingsAudit::useDir($this->rDir . 'misses/', $this->rDir . 'cluster/');
		SettingsManager::get('key_000');
		SettingsManager::get('brand_new');
		SettingsAudit::flush(self::NOW);
		$rDay = $this->day();
		$this->assertSame([2, 8], [$rDay['key_000'], $rDay[SettingsAudit::OTHER]]);
		$this->assertArrayNotHasKey('brand_new', $rDay);

		// The report: at most MAX_KEYS names over the days, the rest under OTHER, last.
		file_put_contents($this->rDir . 'misses/' . gmdate('Ymd', self::NOW - 86400) . '.json', json_encode(['yesterday_key' => 50]));
		$rSummary = SettingsAudit::summary(self::NOW);
		$this->assertCount(SettingsAudit::MAX_KEYS + 1, $rSummary);
		$this->assertSame(['yesterday_key', 'key_000'], array_slice(array_keys($rSummary), 0, 2));
		$this->assertSame(SettingsAudit::OTHER, array_key_last($rSummary));
		$this->assertSame(8 + 1, $rSummary[SettingsAudit::OTHER], 'OTHER, and the one name that no longer fits');
		$this->assertLessThan(16384, strlen((string) file_get_contents($this->rDir . 'cluster/audit.json')));
	}

	public function testTheReportCoversSevenDaysAndOlderDaysArePruned(): void {
		foreach ([0 => 'today', 6 => 'six_days', 7 => 'seven_days', 9 => 'nine_days'] as $rDays => $rKey) {
			@mkdir($this->rDir . 'misses/', 0777, true);
			file_put_contents($this->rDir . 'misses/' . gmdate('Ymd', self::NOW - $rDays * 86400) . '.json', json_encode([$rKey => 1]));
		}
		$this->assertSame(['six_days' => 1, 'today' => 1], SettingsAudit::summary(self::NOW));
		$this->assertSame(1, SettingsAudit::prune(8, self::NOW));
		$this->assertFileDoesNotExist($this->rDir . 'misses/' . gmdate('Ymd', self::NOW - 9 * 86400) . '.json');
		$this->assertTrue(SettingsAudit::publish(null, self::NOW));
		$this->assertSame(['settings_misses' => ['six_days' => 1, 'today' => 1]], $this->published());

		// Nothing missed: an empty object, which clears what MAIN shows.
		exec('rm -rf ' . escapeshellarg($this->rDir . 'misses'));
		$this->assertTrue(SettingsAudit::publish(null, self::NOW));
		$this->assertSame('{"settings_misses":{}}', file_get_contents($this->rDir . 'cluster/audit.json'));
		// No agent here (MAIN, a legacy node): nothing written.
		$this->assertFalse(SettingsAudit::publish($this->rDir . 'no-agent/', self::NOW));
	}

	public function testALongRunningProcessMergesEveryMinute(): void {
		SettingsManager::get('first_key');
		$this->assertNull($this->day(time()), 'held in memory');
		(new \ReflectionProperty(SettingsAudit::class, 'rFlushedAt'))->setValue(null, time() - SettingsAudit::FLUSH_EVERY);
		SettingsManager::get('first_key');
		$this->assertSame(['first_key' => 2], $this->day(time()));
		$this->assertSame(['settings_misses' => ['first_key' => 2]], $this->published());
	}
}
