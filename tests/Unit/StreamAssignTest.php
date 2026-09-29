<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\ClusterExecCommand;
use XcVm\Core\Cluster\EventSpool;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Core\Cluster\StreamRuntime;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Domain\Cluster\StreamAssign;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\AgentUser;
use XcVm\Tests\Support\FakeClusterCrypto;
use XcVm\Tests\Support\InstallSchema;

/**
 * MAIN's own writes to the runtime columns of streams a node runs (Rescan
 * VOD, Recreate channels, the symlink tools, a channel's re-encode) reach the
 * nodes that keep those columns in their own store (`stream.assign`), and the
 * node writes them there without sending them back.
 */
final class StreamAssignTest extends TestCase {
	private TestDb $rDb;

	private string $rDir;

	private string $rRuntimeDir;

	protected function setUp(): void {
		if (!defined('SERVER_ID')) {
			define('SERVER_ID', 5);
		}
		$this->rDb = new TestDb();
		$this->rDb->exec(InstallSchema::table('streams_servers'));
		$this->rDb->exec(InstallSchema::migration('029_create_cluster_nodes'));
		$this->rDb->exec(InstallSchema::migration('030_create_cluster_commands'));
		$this->rDb->exec('ALTER TABLE `cluster_nodes` ADD COLUMN `root_ready` tinyint(1) NOT NULL DEFAULT 0');
		$this->rDb->exec('ALTER TABLE `cluster_nodes` ADD COLUMN `features` varchar(255) DEFAULT NULL');
		DatabaseFactory::set($this->rDb);

		$this->rDir = sys_get_temp_dir() . '/xcvm-assign-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir . 'cluster/spool', 0777, true);
		AgentUser::own($this->rDir);
		EventSpool::useDir($this->rDir . 'cluster/spool/');
		$this->rRuntimeDir = StreamRuntime::dir();
		StreamRuntime::useDir($this->rDir . 'cluster/runtime/');
		NodeRole::useMainBuild(false);
		file_put_contents($this->rDir . 'flows.json', json_encode(['mode' => 1, 'flows' => NodeFlows::STREAMS, 'state' => 'active']));
		NodeFlows::usePath($this->rDir . 'flows.json');
	}

	protected function tearDown(): void {
		StreamRuntime::useDir($this->rRuntimeDir);
		EventSpool::useDir(null);
		NodeRole::useMainBuild(null);
		NodeFlows::usePath(null);
		DatabaseFactory::reset();
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	private function node(int $rServerID, int $rFlows): void {
		$this->rDb->query(
			"INSERT INTO `cluster_nodes` (`server_id`, `node_uuid`, `state`, `mode`, `flows`, `gen`, `node_sign_pub`, `node_box_pub`, `epoch`, `created_at`, `updated_at`) VALUES (?, ?, 'active', 1, ?, 1, ?, ?, 1, 1800000000, 1800000000)",
			$rServerID,
			sprintf('00000000-0000-4000-a000-%012d', $rServerID),
			$rFlows,
			str_repeat(chr($rServerID), 32),
			sodium_crypto_scalarmult_base(random_bytes(32))
		);
	}

	/** @return list<array{0: int, 1: array<string, mixed>}> [server_id, args] of every stream.assign queued */
	private function sent(): array {
		$this->rDb->query("SELECT `server_id`, `payload` FROM `cluster_commands` WHERE `type` = 'stream.assign' ORDER BY `id`");
		return array_map(static fn(array $rRow): array => [(int) $rRow['server_id'], json_decode($rRow['payload'], true)['args']], $this->rDb->get_rows() ?: []);
	}

	public function testOnlyTheNodesThatKeepTheColumnsGetTheStreamsTheyRun(): void {
		$rOn = NodeRegistry::FLOW_COMMANDS | NodeRegistry::FLOW_STREAMS;
		$this->node(5, $rOn);
		$this->node(6, NodeRegistry::FLOW_COMMANDS); // reads MAIN's rows
		$this->node(7, $rOn); // runs none of them
		$this->rDb->exec('INSERT INTO `streams_servers` (`stream_id`, `server_id`, `parent_id`, `on_demand`) VALUES (10, 5, NULL, 0), (11, 5, NULL, 0), (10, 6, NULL, 0), (13, 7, NULL, 0)');

		$this->assertSame(1, StreamAssign::send([10, 11, 12, '10'], ['to_analyze' => 1], ['pid' => 1], new FakeClusterCrypto()));
		$this->assertSame([[5, ['stream_ids' => [10, 11], 'set' => ['to_analyze' => 1], 'fill' => ['pid' => 1]]]], $this->sent());

		// More than a command takes: split.
		$this->rDb->exec('DELETE FROM `cluster_commands`');
		$rIDs = range(100, 100 + StreamRuntime::ASSIGN_MAX);
		$this->rDb->exec('INSERT INTO `streams_servers` (`stream_id`, `server_id`, `parent_id`, `on_demand`) VALUES ' . implode(', ', array_map(static fn(int $rID): string => '(' . $rID . ', 5, NULL, 0)', $rIDs)));
		$this->assertSame(2, StreamAssign::send($rIDs, StreamAssign::RESET, [], new FakeClusterCrypto()));
		$this->assertSame([StreamRuntime::ASSIGN_MAX, 1], array_map(static fn(array $rSent): int => count($rSent[1]['stream_ids']), $this->sent()));
	}

	public function testTheNodeWritesItsStoreAndSendsNothingBack(): void {
		StreamRuntime::keep(['stream_id' => 10, 'server_id' => (int) SERVER_ID], ['pid' => 700, 'to_analyze' => 0], static fn(): bool => true);
		StreamRuntime::keep(['stream_id' => 11, 'server_id' => (int) SERVER_ID], ['pid' => null, 'to_analyze' => 0], static fn(): bool => true);
		$rSpooled = glob($this->rDir . 'cluster/spool/*/*') ?: [];

		ob_start();
		$rCode = ClusterExecCommand::run(['type' => 'stream.assign', 'args' => ['stream_ids' => [10, 11, 12], 'set' => ['to_analyze' => 1], 'fill' => ['pid' => 1]]]);
		$this->assertSame(['result' => true, 'kept' => 2], json_decode((string) ob_get_clean(), true));
		$this->assertSame(0, $rCode);
		$this->assertSame(['pid' => 700, 'to_analyze' => 1], array_intersect_key(StreamRuntime::get(10), ['pid' => 1, 'to_analyze' => 1]), 'a running pid is kept');
		$this->assertSame(['pid' => 1, 'to_analyze' => 1], array_intersect_key(StreamRuntime::get(11), ['pid' => 1, 'to_analyze' => 1]), 'an empty one is filled');
		$this->assertSame([], StreamRuntime::get(12), 'a stream the store does not keep is left alone');
		$this->assertSame($rSpooled, glob($this->rDir . 'cluster/spool/*/*') ?: [], 'nothing is sent back');

		foreach ([
			['stream_ids' => [10], 'set' => ['server_id' => 9]],
			['stream_ids' => [10], 'set' => ['pid' => [1]]],
			['stream_ids' => [], 'set' => ['to_analyze' => 1]],
			['stream_ids' => ['10'], 'set' => ['to_analyze' => 1]],
			['stream_ids' => range(1, StreamRuntime::ASSIGN_MAX + 1), 'set' => ['to_analyze' => 1]],
			['stream_ids' => [10]],
		] as $rBad) {
			$this->assertSame(2, ClusterExecCommand::run(['type' => 'stream.assign', 'args' => $rBad]), json_encode($rBad));
		}
	}
}
