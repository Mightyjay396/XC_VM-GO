<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Util\SystemInfo;

/**
 * SystemInfo::aggregateNetwork — the bandwidth figures of the watchdog
 * heartbeat and of servers_stats. The per-second rates were summed over every
 * selected interface, but the lifetime totals were assigned, so only the last
 * interface was kept, and the received total read tx_bytes. Driven against a
 * fixture /sys/class/net tree.
 */
final class SystemInfoNetworkTest extends TestCase {

	private string $rRoot;

	protected function setUp(): void {
		$this->rRoot = sys_get_temp_dir() . '/xcvm_sysnet_' . uniqid('', true) . '/';
		$this->iface('eth0', 1000, 10, '1000');
		$this->iface('eth1', 2000, 20, '10000');
	}

	protected function tearDown(): void {
		foreach (glob($this->rRoot . '*/statistics/*') ?: [] as $rFile) {
			unlink($rFile);
		}
		foreach (glob($this->rRoot . '*/speed') ?: [] as $rFile) {
			unlink($rFile);
		}
		foreach (glob($this->rRoot . '*/statistics') ?: [] as $rDir) {
			rmdir($rDir);
		}
		foreach (glob($this->rRoot . '*') ?: [] as $rDir) {
			rmdir($rDir);
		}
		@rmdir($this->rRoot);
	}

	private function iface(string $rName, ?int $rTx, ?int $rRx, ?string $rSpeed): void {
		mkdir($this->rRoot . $rName . '/statistics', 0755, true);
		if ($rTx !== null) {
			file_put_contents($this->rRoot . $rName . '/statistics/tx_bytes', $rTx . "\n");
		}
		if ($rRx !== null) {
			file_put_contents($this->rRoot . $rName . '/statistics/rx_bytes', $rRx . "\n");
		}
		if ($rSpeed !== null) {
			file_put_contents($this->rRoot . $rName . '/speed', $rSpeed . "\n");
		}
	}

	private static function rates(int $rIn, int $rOut): array {
		return ['in_bytes' => $rIn, 'in_packets' => 0, 'in_errors' => 0, 'out_bytes' => $rOut, 'out_packets' => 0, 'out_errors' => 0];
	}

	public function testTotalsAreSummedOverTheSelectedInterfaces(): void {
		$rNetwork = SystemInfo::aggregateNetwork(['eth0' => self::rates(1, 2), 'eth1' => self::rates(3, 4)], $this->rRoot);

		$this->assertSame(6, $rNetwork['bytes_sent']);
		$this->assertSame(4, $rNetwork['bytes_received']);
		$this->assertSame(3000, $rNetwork['bytes_sent_total']);
		$this->assertSame(30, $rNetwork['bytes_received_total'], 'received is rx_bytes, not tx_bytes');
	}

	public function testNetworkSpeedIsTheFirstPositiveSpeed(): void {
		$rNetwork = SystemInfo::aggregateNetwork(['eth0' => self::rates(1, 2), 'eth1' => self::rates(3, 4)], $this->rRoot);
		$this->assertSame(1000, $rNetwork['network_speed']);

		// A down or virtual interface reports -1 (or nothing); the next one counts.
		$this->iface('veth0', 5, 5, '-1');
		$rNetwork = SystemInfo::aggregateNetwork(['veth0' => self::rates(0, 0), 'eth1' => self::rates(0, 0)], $this->rRoot);
		$this->assertSame(10000, $rNetwork['network_speed']);
	}

	public function testReturnsTheHeartbeatKeysInOrder(): void {
		$this->assertSame(
			['bytes_sent', 'bytes_sent_total', 'bytes_received', 'bytes_received_total', 'network_speed'],
			array_keys(SystemInfo::aggregateNetwork([], $this->rRoot))
		);
		$this->assertSame([0, 0, 0, 0, 0], array_values(SystemInfo::aggregateNetwork([], $this->rRoot)));
	}

	public function testAMissingStatisticsFileCountsAsZero(): void {
		$this->iface('eth2', null, 7, null);
		$rNetwork = SystemInfo::aggregateNetwork(['eth2' => self::rates(1, 1), 'gone0' => self::rates(1, 1)], $this->rRoot);

		$this->assertSame(0, $rNetwork['bytes_sent_total']);
		$this->assertSame(7, $rNetwork['bytes_received_total']);
		$this->assertSame(0, $rNetwork['network_speed']);
		$this->assertSame(2, $rNetwork['bytes_sent']);
	}

	public function testGetStatsTakesItsBandwidthFromAggregateNetwork(): void {
		$rSource = (string) file_get_contents(MAIN_HOME . 'Core/Util/SystemInfo.php');

		$this->assertStringContainsString('self::aggregateNetwork($rJSON[\'network_info\'])', $rSource);
		$this->assertStringNotContainsString("\$rJSON['bytes_sent_total'] = (", $rSource, 'the total must not be assigned per interface');
	}
}
