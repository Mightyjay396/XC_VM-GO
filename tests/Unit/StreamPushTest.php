<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\BlocklistChanges;
use XcVm\Core\Cluster\QueueSink;
use XcVm\Core\Cluster\StreamVersions;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Domain\Cluster\ReplicaBuilder;
use XcVm\Domain\Cluster\StreamPush;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\FakeClusterCrypto;
use XcVm\Tests\Support\InstallSchema;

/**
 * A change to a node's R2 `streams` section wakes that node (plan, section 9,
 * R2 "on change"): every server a change stamped in the request gets one
 * `config.changed {sections: [streams]}` when it ends, if it reads the
 * section and its agent takes the command.
 */
final class StreamPushTest extends TestCase {
	private TestDb $rDb;

	private FakeClusterCrypto $rCrypto;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach (['streams', 'streams_servers', 'recordings'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec(InstallSchema::migration('029_create_cluster_nodes'));
		$this->rDb->exec(InstallSchema::migration('030_create_cluster_commands'));
		$this->rDb->exec(InstallSchema::migration('034_create_cluster_changes'));
		$this->rDb->exec('ALTER TABLE `cluster_nodes` ADD COLUMN `root_ready` tinyint(1) NOT NULL DEFAULT 0');
		$this->rDb->exec('ALTER TABLE `cluster_nodes` ADD COLUMN `features` varchar(255) DEFAULT NULL');
		DatabaseFactory::set($this->rDb);
		$this->rCrypto = new FakeClusterCrypto();
		StreamPush::flush($this->rCrypto); // what another test left pending
	}

	protected function tearDown(): void {
		StreamPush::flush($this->rCrypto);
		DatabaseFactory::reset();
	}

	private function node(int $rServerID, string $rState = 'active', int $rFlows = NodeRegistry::FLOW_COMMANDS | NodeRegistry::FLOW_STREAMS, ?string $rFeatures = ReplicaBuilder::FEATURE_CONFIG_CHANGED): void {
		$this->rDb->query(
			'INSERT INTO `cluster_nodes` (`server_id`, `node_uuid`, `state`, `mode`, `flows`, `gen`, `node_sign_pub`, `node_box_pub`, `epoch`, `created_at`, `updated_at`, `features`) VALUES (?, ?, ?, 1, ?, 1, ?, ?, 1, 1800000000, 1800000000, ?)',
			$rServerID,
			sprintf('00000000-0000-4000-a000-%012d', $rServerID),
			$rState,
			$rFlows,
			str_repeat(chr($rServerID), 32),
			sodium_crypto_scalarmult_base(random_bytes(32)),
			$rFeatures
		);
	}

	/** @return list<array{0: int, 1: list<string>}> [server_id, sections] of every config.changed queued */
	private function told(): array {
		$this->rDb->query("SELECT `server_id`, `payload` FROM `cluster_commands` WHERE `type` = 'config.changed' ORDER BY `server_id`");
		return array_map(static fn(array $rRow): array => [(int) $rRow['server_id'], json_decode($rRow['payload'], true)['args']['sections']], $this->rDb->get_rows() ?: []);
	}

	public function testOnlyTheNodesThatReadTheSectionAndTakeTheCommandAreToldOnce(): void {
		$this->node(5);
		$this->node(6, 'active', NodeRegistry::FLOW_COMMANDS); // STREAMS off: reads MAIN's rows
		$this->node(7, 'active', NodeRegistry::FLOW_STREAMS); // COMMANDS off
		$this->node(8, 'quarantined');
		$this->node(9, 'active', NodeRegistry::FLOW_COMMANDS | NodeRegistry::FLOW_STREAMS, 'hls_reaper'); // an agent without config.changed
		$this->node(10);

		StreamPush::changed([5, 6, 7, 8, 9]);
		StreamPush::changed([5, 99]); // the same node again, and a server that is no node
		$this->assertSame(1, StreamPush::flush($this->rCrypto));
		$this->assertSame([[5, ['streams']]], $this->told());
		$this->assertSame(0, StreamPush::flush($this->rCrypto), 'nothing pending twice');

		// Every stream at once: every node that reads the section.
		$this->rDb->exec('DELETE FROM `cluster_commands`');
		StreamPush::changedAll();
		$this->assertSame(2, StreamPush::flush($this->rCrypto));
		$this->assertSame([5, 10], array_column($this->told(), 0));
	}

	/**
	 * A block or unblock wakes every node that reads the R1 blocklist (CONFIG
	 * on), once per request; a node whose streams changed too hears of both in
	 * one command, since a second config.changed would supersede the first.
	 */
	public function testABlocklistChangeWakesTheConfigNodesInOneCommandWithTheStreams(): void {
		$this->node(5, 'active', NodeRegistry::FLOW_COMMANDS | NodeRegistry::FLOW_CONFIG);
		$this->node(6, 'active', NodeRegistry::FLOW_COMMANDS | NodeRegistry::FLOW_CONFIG | NodeRegistry::FLOW_STREAMS);
		$this->node(7); // STREAMS without CONFIG: it reads MAIN's blocklist
		$this->node(8, 'active', NodeRegistry::FLOW_COMMANDS | NodeRegistry::FLOW_CONFIG, 'hls_reaper'); // no config.changed

		BlocklistChanges::set('ip', ['198.51.100.7'], $this->rDb);
		BlocklistChanges::del('ua', [3], $this->rDb);
		StreamPush::changed([6]);
		$this->assertSame(2, StreamPush::flush($this->rCrypto));
		$this->assertSame([[5, ['blocklist']], [6, ['streams', 'blocklist']]], $this->told());
		$this->assertSame(0, StreamPush::flush($this->rCrypto), 'nothing pending twice');
	}

	public function testABumpWakesTheServersItStamped(): void {
		$this->node(2);
		$this->node(3);
		$this->node(4);
		$this->rDb->exec("INSERT INTO `streams` (`id`, `type`, `stream_source`, `tv_archive_server_id`, `vframes_server_id`) VALUES (10, 1, '[]', 3, 0)");
		$this->rDb->exec('INSERT INTO `streams_servers` (`stream_id`, `server_id`, `parent_id`, `on_demand`) VALUES (10, 2, NULL, 0)');

		$this->assertGreaterThan(0, StreamVersions::bump([10]));
		StreamPush::flush($this->rCrypto);
		$this->assertSame([2, 3], array_column($this->told(), 0), 'the server holding it and the one archiving it, not server 4');
	}

	/**
	 * Encoding work MAIN queued onto a node pokes it (`queue.poke`) when its
	 * CONTENT flow is on, once per request; the node's daemon then stops
	 * waiting for its next pass.
	 */
	public function testQueuedWorkPokesTheNodeAndItsDaemonStopsWaiting(): void {
		$this->node(5, 'active', NodeRegistry::FLOW_COMMANDS | NodeRegistry::FLOW_CONTENT);
		$this->node(6, 'active', NodeRegistry::FLOW_COMMANDS); // asks nobody for its queue
		StreamPush::queued([5, 6]);
		StreamPush::queued([5]);
		$this->assertSame(1, StreamPush::flush($this->rCrypto));
		$this->rDb->query("SELECT `server_id`, `dedupe_key` FROM `cluster_commands` WHERE `type` = 'queue.poke'");
		$this->assertSame([['server_id' => 5, 'dedupe_key' => 'queue.poke']], array_map(static fn(array $rRow): array => ['server_id' => (int) $rRow['server_id'], 'dedupe_key' => $rRow['dedupe_key']], $this->rDb->get_rows()));

		$rDir = sys_get_temp_dir() . '/xcvm-poke-' . bin2hex(random_bytes(4)) . '/';
		mkdir($rDir);
		try {
			$rStart = microtime(true);
			QueueSink::waitPoke(1, $rDir);
			$this->assertGreaterThanOrEqual(0.9, microtime(true) - $rStart, 'no poke: the whole wait');
			touch($rDir . QueueSink::POKE);
			$rStart = microtime(true);
			QueueSink::waitPoke(5, $rDir);
			$this->assertLessThan(1.0, microtime(true) - $rStart, 'poked: at once');
			$this->assertFileDoesNotExist($rDir . QueueSink::POKE, 'and the poke is spent');
		} finally {
			exec('rm -rf ' . escapeshellarg($rDir));
		}
	}
}
