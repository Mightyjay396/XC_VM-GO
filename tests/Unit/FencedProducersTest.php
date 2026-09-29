<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\NodeLease;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Stream\StreamProcess;

/**
 * A node fenced past its drain starts no producer, whoever asks
 * (StreamProcess::startMonitor; plan, section 9, FENCED). cron:streams
 * releases the running ones (ModeTwoPathsTest).
 */
final class FencedProducersTest extends TestCase {
	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-fenced-' . bin2hex(random_bytes(4));
		mkdir($this->rDir);
		file_put_contents($this->rDir . '/flows.json', json_encode(['mode' => 1, 'flows' => NodeFlows::CONFIG, 'state' => 'active']));
		NodeFlows::usePath($this->rDir . '/flows.json');
		SettingsManager::set(['lb_lease_fence' => 1, 'lb_fence_drain_min' => 10]);
		NodeLease::useExtension(false);
	}

	protected function tearDown(): void {
		NodeFlows::usePath(null);
		NodeLease::usePath(null);
		NodeLease::useExtension(null);
		SettingsManager::set([]);
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	public function testAFencedNodeStartsNoProducer(): void {
		$rNowMs = (int) round(microtime(true) * 1000);
		file_put_contents($this->rDir . '/lease_state.json', (string) json_encode(['exp' => intdiv($rNowMs, 1000) - 3600, 'iat' => intdiv($rNowMs, 1000) - 7200, 'gen' => 4, 'server_id' => 7, 'anchor_ms' => $rNowMs, 'wrote_at_ms' => $rNowMs]));
		NodeLease::usePath($this->rDir . '/lease_state.json');
		$this->assertSame(NodeLease::FENCED, NodeLease::state());
		$this->assertSame(StreamProcess::MONITOR_FENCED, StreamProcess::startMonitor(7));
	}
}
