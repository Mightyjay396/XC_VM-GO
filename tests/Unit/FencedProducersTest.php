<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\NodeLease;
use XcVm\Core\Config\SettingsManager;
use XcVm\Cli\Commands\ArchiveCommand;
use XcVm\Cli\Commands\QueueCommand;
use XcVm\Cli\Commands\RecordCommand;
use XcVm\Cli\Commands\ThumbnailCommand;
use XcVm\Domain\Stream\StreamProcess;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * A node fenced past its drain starts no producer, whoever asks
 * (StreamProcess::startMonitor; plan, section 9, FENCED), nor a thumbnail, a
 * TV archive, a recording, a movie encode or a channel build. cron:streams
 * releases the running producers and their workers (ModeTwoPathsTest).
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

	private function fence(): void {
		$rNowMs = (int) round(microtime(true) * 1000);
		file_put_contents($this->rDir . '/lease_state.json', (string) json_encode(['exp' => intdiv($rNowMs, 1000) - 3600, 'iat' => intdiv($rNowMs, 1000) - 7200, 'gen' => 4, 'server_id' => 7, 'anchor_ms' => $rNowMs, 'wrote_at_ms' => $rNowMs]));
		NodeLease::usePath($this->rDir . '/lease_state.json');
		$this->assertSame(NodeLease::FENCED, NodeLease::state());
	}

	public function testAFencedNodeStartsNoProducer(): void {
		$this->fence();
		$this->assertSame(StreamProcess::MONITOR_FENCED, StreamProcess::startMonitor(7));
	}

	public function testAFencedNodeStartsNoWorkerRecordingEncodeOrBuild(): void {
		$this->fence();
		$this->assertFalse(StreamProcess::startThumbnail(7));
		foreach ([new ThumbnailCommand(), new ArchiveCommand(), new RecordCommand()] as $rCommand) {
			ob_start();
			$rCode = $rCommand->execute(['7']);
			$rOut = (string) ob_get_clean();
			$this->assertSame(0, $rCode, get_class($rCommand));
			$this->assertStringContainsString('Fenced: nothing starts', $rOut, get_class($rCommand));
		}
		// The queue's passes claim nothing: no database here, and none is asked.
		DatabaseFactory::reset();
		$rQueue = new QueueCommand();
		foreach (['movies', 'channels'] as $rPass) {
			$rPids = $rDelete = [];
			$rMethod = new \ReflectionMethod($rQueue, $rPass);
			$rMethod->invokeArgs($rQueue, [&$rPids, &$rDelete]);
			$this->assertSame([[], []], [$rPids, $rDelete], $rPass);
		}
	}
}
