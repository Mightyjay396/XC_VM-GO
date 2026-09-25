<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\NodeActions;
use XcVm\Core\Cluster\NodeRpc;
use XcVm\Core\Cluster\SignalDispatcher;
use XcVm\Core\Cluster\SignalSink;

/**
 * MAIN → node calls go through two catalogued seams: NodeRpc (system API
 * request/response) and NodeActions (root actions queued for the node's root
 * cron). The legacy transports are unchanged, and an action outside the
 * catalogue is refused.
 */
final class NodeRpcActionsTest extends TestCase {

	protected function tearDown(): void {
		NodeRpc::useTransport(null);
		SignalDispatcher::useSink(null);
	}

	private function captureSignals(): object {
		$rSink = new class implements SignalSink {
			/** @var list<array<string, mixed>> */
			public array $rRows = [];
			public function insert(array $rRows): bool {
				array_push($this->rRows, ...$rRows);
				return true;
			}
			public function pending(int $rServerID, string $rCustomData): bool {
				return false;
			}
		};
		SignalDispatcher::useSink($rSink);
		return $rSink;
	}

	public function testRpcGoesThroughTheTransport(): void {
		$rCalls = [];
		NodeRpc::useTransport(function (string $rMode, array $rIDs, array $rData, int $rTimeout) use (&$rCalls) {
			$rCalls[] = [$rMode, $rIDs, $rData, $rTimeout];
			return $rMode === 'request' ? '{"ok":1}' : ['result' => true];
		});
		$this->assertSame('{"ok":1}', NodeRpc::request(3, ['action' => 'get_pids'], 9));
		$this->assertSame(['result' => true], NodeRpc::broadcast(['4', 5], ['action' => 'force_stream', 'stream_id' => 1]));
		$this->assertSame([['request', [3], ['action' => 'get_pids'], 9], ['broadcast', [4, 5], ['action' => 'force_stream', 'stream_id' => 1], 0]], $rCalls);
	}

	public function testUnknownRpcActionIsRefused(): void {
		NodeRpc::useTransport(fn() => $this->fail('must not be sent'));
		$this->expectException(InvalidArgumentException::class);
		NodeRpc::request(1, ['action' => 'rm_rf']);
	}

	public function testRootActionsQueueTheSamePayloads(): void {
		$rSink = $this->captureSignals();
		NodeActions::flushBlocklist(2);
		NodeActions::setRamdisk(2, false);
		NodeActions::setPorts(2, 1, [80, 81], true);
		NodeActions::rollback(2, '2.3.1');
		NodeActions::certbot(2, ['a.example']);
		NodeActions::send(2, '{"action":"install_module","name":"x"}');
		$this->assertSame([
			'{"action":"flush"}', // RootSignalsCronJob matches this exact string
			'{"action":"disable_ramdisk"}',
			'{"action":"set_port","type":1,"ports":[80,81],"reload":true}',
			'{"action":"rollback","version":"2.3.1"}',
			'{"action":"certbot_generate","domain":["a.example"]}',
			'{"action":"install_module","name":"x"}',
		], array_column($rSink->rRows, 'custom_data'));
		$this->assertSame([2], array_values(array_unique(array_column($rSink->rRows, 'server_id'))));
	}

	public function testUnknownRootActionIsRefused(): void {
		$this->captureSignals();
		$this->expectException(InvalidArgumentException::class);
		NodeActions::send(1, ['action' => 'format_disk']);
	}

	public function testEveryCataloguedRootActionHasAHandler(): void {
		$rHandler = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Cli/CronJobs/RootSignalsCronJob.php');
		foreach (NodeActions::ROOT_ACTIONS as $rAction) {
			if ($rAction === 'flush') {
				$this->assertStringContainsString('{\\"action\\":\\"flush\\"}', $rHandler);
				continue;
			}
			if ($rAction === XcVm\Core\Config\OpensslExtra::SIGNAL_ACTION) {
				$this->assertStringContainsString('case OpensslExtra::SIGNAL_ACTION:', $rHandler);
				continue;
			}
			$this->assertStringContainsString("case '" . $rAction . "':", $rHandler, $rAction);
		}
	}

	public function testCallSitesUseCataloguedRpcActionsOnly(): void {
		$rRoot = dirname(__DIR__, 2) . '/src/';
		$rUsed = [];
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rRoot, FilesystemIterator::SKIP_DOTS)) as $rFile) {
			$rPath = $rFile->getPathname();
			if ($rFile->getExtension() !== 'php' || str_contains($rPath, '/vendor/')) {
				continue;
			}
			$rSource = (string) file_get_contents($rPath);
			$this->assertDoesNotMatchRegularExpression('/ApiClient::(systemRequest|asyncRequest)\(/', str_ends_with($rPath, 'Cluster/NodeRpc.php') || str_ends_with($rPath, 'Http/ApiClient.php') ? '' : $rSource, $rPath);
			$this->assertStringNotContainsString('SignalDispatcher::rootAction(', str_contains($rPath, '/Core/Cluster/') ? '' : $rSource, $rPath);
			preg_match_all("/NodeRpc::(?:request|broadcast)\([^;]*?'action' => '([A-Za-z_]+)'/", $rSource, $rM);
			array_push($rUsed, ...$rM[1]);
		}
		$this->assertNotEmpty($rUsed);
		$this->assertSame([], array_values(array_diff(array_unique($rUsed), NodeRpc::ACTIONS)));
	}
}
