<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\ClusterExecCommand;
use XcVm\Core\Cluster\Crypto\Enc;
use XcVm\Tests\Support\FakeClusterCrypto;

/**
 * cluster:exec runs a command only when it carries the panel's `cmd`
 * signature under the key the agent pinned, names this node and is not stale.
 */
final class ClusterExecCommandTest extends TestCase {
	private FakeClusterCrypto $rCrypto;

	private array $rState;

	protected function setUp(): void {
		$this->rCrypto = new FakeClusterCrypto();
		$this->rState = ['node_uuid' => '0f8fad5b-d9cb-469f-a165-70867728950e', 'panel_sign_pub' => base64_encode($this->rCrypto->info()['panel_sign_pub'])];
	}

	private function command(array $rOverride = [], string $rTag = 'cmd'): array {
		$rDoc = (string) json_encode($rOverride + ['v' => 1, 'type' => 'node.rpc', 'exp' => 1800000600, 'iat' => 1800000000, 'cmd_id' => str_repeat('a', 32), 'seq' => 1, 'node_uuid' => $this->rState['node_uuid'], 'gen' => 1, 'dedupe_key' => null, 'args' => ['action' => 'get_pids']]);
		return ['doc' => $rDoc, 'sig' => Enc::b64url($this->rCrypto->sign($rTag, $rDoc))];
	}

	public function testAGenuineCommandVerifies(): void {
		$rCmd = ClusterExecCommand::verify($this->command(), $this->rState, 1800000000);
		$this->assertIsArray($rCmd);
		$this->assertSame('get_pids', $rCmd['args']['action']);
	}

	public function testForgedOrMisaddressedCommandsAreRefused(): void {
		$rGood = $this->command();
		$this->assertSame('bad signature', ClusterExecCommand::verify(['doc' => str_replace('get_pids', 'kill_pid', $rGood['doc'])] + $rGood, $this->rState, 1800000000));
		$this->assertSame('bad signature', ClusterExecCommand::verify($this->command([], 'den'), $this->rState, 1800000000), 'another tag');
		$this->assertSame('bad signature', ClusterExecCommand::verify($rGood, ['panel_sign_pub' => base64_encode(str_repeat("\1", 32))] + $this->rState, 1800000000));
		$this->assertIsString(ClusterExecCommand::verify($this->command(['node_uuid' => '11111111-1111-4111-a111-111111111111']), $this->rState, 1800000000));
		$this->assertIsString(ClusterExecCommand::verify($rGood, $this->rState, 1800000600 + ClusterExecCommand::SKEW + 1), 'stale');
	}

	public function testUnknownTypesAndActionsDoNothing(): void {
		$this->assertSame(2, ClusterExecCommand::run(['type' => 'node.root', 'args' => ['action' => 'reboot']]));
		$this->assertSame(2, ClusterExecCommand::run(['type' => 'node.rpc', 'args' => ['action' => 'view_log']]), 'not in NodeRpc::ACTIONS');
		$this->assertSame(2, ClusterExecCommand::run(['type' => 'conn.kill_worker', 'args' => ['pid' => 0]]));
	}
}
