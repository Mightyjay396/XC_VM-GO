<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Util\SystemInfo;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\HeartbeatService;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * Phase 3: an agent's telemetry becomes the server's watchdog_data (in the
 * legacy shape) and its servers_stats rows once the node's TELEMETRY flow is
 * on; the LB side reads its flows from the agent's flows.json.
 */
final class ClusterTelemetryTest extends TestCase {
	private TestDb $rDb;

	private int $rT0 = 1800000000000;

	/** A sample as clusteragent.Sampler produces it. */
	private const SAMPLE = [
		'v' => 1, 'at_ms' => 1800000000000, 'cpu' => 37.5, 'cpu_cores' => 4, 'cpu_name' => 'Test CPU', 'load' => [2.0, 1.0, 0.5],
		'mem_total_kb' => 8000000, 'mem_avail_kb' => 6000000, 'disk_total' => 100000000000, 'disk_free' => 40000000000,
		'kernel' => '6.1.0', 'uptime_s' => 90061, 'stream_producers' => 3, 'php_pids' => [104, 105], 'interfaces' => ['eth0', 'eth1'],
		'net' => [
			'eth1' => ['in_bytes' => 10, 'in_packets' => 1, 'in_errors' => 0, 'out_bytes' => 20, 'out_packets' => 2, 'out_errors' => 0, 'rx_total' => 100, 'tx_total' => 200, 'speed' => 0],
			'eth0' => ['in_bytes' => 1000, 'in_packets' => 10, 'in_errors' => 1, 'out_bytes' => 2000, 'out_packets' => 20, 'out_errors' => 0, 'rx_total' => 3000, 'tx_total' => 6000, 'speed' => 1000],
			'bond0' => ['in_bytes' => 5, 'out_bytes' => 5, 'rx_total' => 5, 'tx_total' => 5, 'speed' => 10000],
		],
		'local' => ['requests_per_second' => 42, 'fanout' => ['running' => true, 'socket' => true, 'connections' => 7, 'memory' => null]],
	];

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec((string) preg_replace(['/^--.*$/m', '/,\s*(UNIQUE )?KEY `\w+` \([^)]*\)/', '/ unsigned| COLLATE \w+/', '/\) ENGINE=[^;]*;/'], ['', '', '', ');'], (string) file_get_contents(dirname(__DIR__, 2) . '/src/migrations/database/up/029_create_cluster_nodes.sql')));
		$this->rDb->exec('CREATE TABLE `servers` (`id` INTEGER PRIMARY KEY, `status` int NOT NULL DEFAULT 0, `watchdog_data` text, `last_check_ago` int DEFAULT 0, `requests_per_second` int DEFAULT 0, `php_pids` text, `connections` int DEFAULT 0, `users` int DEFAULT 0, `network_interface` varchar(32) DEFAULT NULL)');
		$this->rDb->exec("INSERT INTO `servers` (`id`, `status`, `watchdog_data`, `network_interface`) VALUES (5, 1, '{\"cpu_average_array\":[10,20]}', 'auto')");
		$this->rDb->exec('CREATE TABLE `lines_live` (`activity_id` INTEGER PRIMARY KEY, `user_id` int, `server_id` int, `hls_end` int DEFAULT 0)');
		$this->rDb->exec('INSERT INTO `lines_live` (`user_id`, `server_id`, `hls_end`) VALUES (1, 5, 0), (1, 5, 0), (2, 5, 0), (3, 5, 1), (4, 6, 0)');
		$this->rDb->exec('CREATE TABLE `streams` (`id` INTEGER PRIMARY KEY, `type` int)');
		$this->rDb->exec('CREATE TABLE `streams_servers` (`stream_id` int, `server_id` int, `pid` int)');
		$this->rDb->exec('INSERT INTO `streams` (`id`, `type`) VALUES (1, 1), (2, 1), (3, 2)');
		$this->rDb->exec('INSERT INTO `streams_servers` VALUES (1, 5, 10), (2, 5, 0), (3, 5, 11)');
		$this->rDb->exec('CREATE TABLE `servers_stats` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `server_id` int, `connections` int, `total_users` int, `users` int, `streams` int, `cpu` float, `cpu_cores` int, `cpu_avg` float, `total_mem` int, `total_mem_free` int, `total_mem_used` int, `total_mem_used_percent` float, `total_disk_space` bigint, `uptime` varchar(255), `total_running_streams` int, `bytes_sent` bigint, `bytes_received` bigint, `bytes_sent_total` bigint, `bytes_received_total` bigint, `cpu_load_average` float, `gpu_info` text, `iostat_info` text, `time` int)');
		$this->rDb->exec('CREATE TABLE `cluster_audit` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `time` int, `server_id` int, `actor` varchar(64), `event` varchar(64), `detail` text, `ip` varchar(64))');
		DatabaseFactory::set($this->rDb);
		SettingsManager::set(['redis_handler' => 0, 'total_users' => 9]);
		ClusterClock::fix($this->rT0);
		NodeRegistry::startEnrolment(5, '3b0c1d2e-4f5a-4b6c-8d7e-9f0a1b2c3d4e', str_repeat("\1", 32), str_repeat("\2", 32), 1);
		NodeRegistry::update(5, ['state' => 'active']);
	}

	protected function tearDown(): void {
		ClusterClock::fix(null);
		DatabaseFactory::reset();
		SettingsManager::set([]);
		NodeFlows::usePath(null);
	}

	private function server(): array {
		$this->rDb->query('SELECT * FROM `servers` WHERE `id` = 5');
		return $this->rDb->get_row();
	}

	private function statsRows(): int {
		$this->rDb->query('SELECT COUNT(*) AS `n` FROM `servers_stats`');
		return (int) $this->rDb->get_row()['n'];
	}

	public function testWatchdogDataKeepsTheLegacyShape(): void {
		// SystemInfo::getStats()'s keys in the order it first sets them (read
		// from its source: running it would sample this host and the servers cache).
		$rMethod = new ReflectionMethod(SystemInfo::class, 'getStats');
		$rSource = implode('', array_slice(file((string) $rMethod->getFileName()), $rMethod->getStartLine() - 1, $rMethod->getEndLine() - $rMethod->getStartLine() + 1));
		preg_match_all('/\$rJSON\[\'([a-z_]+)\'\]/', $rSource, $rM);
		$rLegacy = array_values(array_unique($rM[1]));
		$this->assertContains('network_info', $rLegacy);
		$rLegacy[] = 'cpu_average_array'; // the watchdog adds these two
		$rLegacy[] = 'fanout';
		$this->assertSame($rLegacy, array_keys(HeartbeatService::toWatchdogData(self::SAMPLE, [])), 'same keys, same order');
	}

	public function testWatchdogDataFromTelemetry(): void {
		$rData = HeartbeatService::toWatchdogData(self::SAMPLE, ['cpu_average_array' => range(1, 30)]);
		$this->assertSame(37.5, $rData['cpu']);
		$this->assertSame(50.0, $rData['cpu_avg'], 'load 2.0 over 4 cores');
		$this->assertSame([8000000, 6000000, 2000000, 25.0], [$rData['total_mem'], $rData['total_mem_free'], $rData['total_mem_used'], $rData['total_mem_used_percent']]);
		$this->assertSame('1d 1h 1m 1s', $rData['uptime']);
		$this->assertSame(3, $rData['total_running_streams']);
		$this->assertSame(['eth0', 'eth1'], array_keys($rData['network_info']), 'bond and lo are left out under auto');
		$this->assertSame([2020, 1010, 6200, 3100, 1000], [$rData['bytes_sent'], $rData['bytes_received'], $rData['bytes_sent_total'], $rData['bytes_received_total'], $rData['network_speed']]);
		$this->assertCount(30, $rData['cpu_average_array']);
		$this->assertSame(37.5, end($rData['cpu_average_array']));
		$this->assertSame(7, $rData['fanout']['connections']);

		$rOne = HeartbeatService::toWatchdogData(self::SAMPLE, [], 'eth1');
		$this->assertSame(['eth1'], array_keys($rOne['network_info']));
		$this->assertSame([20, 0], [$rOne['bytes_sent'], $rOne['network_speed']]);
		$this->assertSame(['bond0'], array_keys(HeartbeatService::toWatchdogData(self::SAMPLE, [], 'bond0')['network_info']), 'a chosen bond is reported');
	}

	public function testNothingIsWrittenWhileTheFlowIsOff(): void {
		HeartbeatService::record(NodeRegistry::byServer(5), ['telemetry' => self::SAMPLE], $this->rT0);
		$this->assertSame(0, (int) $this->server()['last_check_ago']);
		$this->assertSame(0, $this->statsRows());
	}

	public function testTelemetryFlowWritesTheServerRow(): void {
		NodeRegistry::update(5, ['flows' => NodeRegistry::FLOW_TELEMETRY]);
		HeartbeatService::record(NodeRegistry::byServer(5), ['telemetry' => self::SAMPLE], $this->rT0);
		$rServer = $this->server();
		$this->assertSame(1800000000, (int) $rServer['last_check_ago']);
		$this->assertSame(42, (int) $rServer['requests_per_second']);
		$this->assertSame('[104,105]', $rServer['php_pids']);
		$this->assertSame([3, 2], [(int) $rServer['connections'], (int) $rServer['users']], 'live rows of this server, users grouped');
		$rWd = json_decode($rServer['watchdog_data'], true);
		$this->assertSame([10, 20, 37.5], $rWd['cpu_average_array'], 'the history continues');

		$this->rDb->query('SELECT * FROM `servers_stats`');
		$rStats = $this->rDb->get_row();
		$this->assertSame([5, 3, 9, 2, 1], [(int) $rStats['server_id'], (int) $rStats['connections'], (int) $rStats['total_users'], (int) $rStats['users'], (int) $rStats['streams']]);
		$this->assertEquals(22.5, (float) $rStats['cpu'], 'the average of the history, as cron:servers stored it');

		// Heartbeats come every 2 s; the row is written every 5 s.
		ClusterClock::fix($this->rT0 + 2000);
		HeartbeatService::record(NodeRegistry::byServer(5), ['telemetry' => ['cpu' => 99.0] + self::SAMPLE], $this->rT0 + 2000);
		$this->assertSame(1800000000, (int) $this->server()['last_check_ago']);
		ClusterClock::fix($this->rT0 + 5000);
		HeartbeatService::record(NodeRegistry::byServer(5), ['telemetry' => ['cpu' => 99.0] + self::SAMPLE], $this->rT0 + 5000);
		$this->assertSame(1800000005, (int) $this->server()['last_check_ago']);
		$this->assertEquals(99.0, json_decode($this->server()['watchdog_data'], true)['cpu']);
	}

	public function testRedisHandlerLeavesTheCountsToMainsWatchdog(): void {
		SettingsManager::set(['redis_handler' => 1]);
		$this->rDb->exec('UPDATE `servers` SET `connections` = 11, `users` = 4 WHERE `id` = 5');
		NodeRegistry::update(5, ['flows' => NodeRegistry::FLOW_TELEMETRY]);
		HeartbeatService::record(NodeRegistry::byServer(5), ['telemetry' => self::SAMPLE], $this->rT0);
		$this->assertSame([11, 4], [(int) $this->server()['connections'], (int) $this->server()['users']]);
		$this->rDb->query('SELECT `connections`, `users` FROM `servers_stats`');
		$this->assertSame([11, 4], array_map('intval', array_values($this->rDb->get_row())));
	}

	public function testNodeFlowsReadsTheAgentsFile(): void {
		$rPath = tempnam(sys_get_temp_dir(), 'flows');
		file_put_contents($rPath, '{"flows":1,"mode":1,"state":"active"}');
		NodeFlows::usePath($rPath);
		$this->assertTrue(NodeFlows::on(NodeFlows::TELEMETRY));
		$this->assertFalse(NodeFlows::on(NodeFlows::COMMANDS));

		file_put_contents($rPath, '{"flows":1,"mode":0,"state":"active"}');
		NodeFlows::usePath($rPath);
		$this->assertFalse(NodeFlows::on(NodeFlows::TELEMETRY), 'mode 0 is legacy whatever the bits');
		file_put_contents($rPath, '{"flows":1,"mode":1,"state":"revoked"}');
		NodeFlows::usePath($rPath);
		$this->assertFalse(NodeFlows::on(NodeFlows::TELEMETRY));
		unlink($rPath);
		NodeFlows::usePath($rPath);
		$this->assertFalse(NodeFlows::on(NodeFlows::TELEMETRY), 'no file: legacy');
	}

	public function testAdminTogglesTheFlow(): void {
		$rServers = [1 => ['is_main' => 1, 'server_type' => 0], 5 => ['is_main' => 0, 'server_type' => 0, 'server_name' => 'LB-5']];
		$rAct = fn(string $rAction) => \XcVm\Domain\Cluster\ClusterAdmin::act(new \XcVm\Tests\Support\FakeClusterCrypto(), ['cluster_action' => $rAction, 'server_id' => 5], $rServers, 1, [], 3);
		$this->assertSame('cluster_telemetry_on_done', $rAct('telemetry_on')['message']);
		$this->assertSame(NodeRegistry::FLOW_TELEMETRY, (int) NodeRegistry::byServer(5)['flows']);
		$this->assertSame('cluster_telemetry_off_done', $rAct('telemetry_off')['message']);
		$this->assertSame(0, (int) NodeRegistry::byServer(5)['flows']);
		$rAct('logs_on');
		$this->assertSame('cluster_streams_on_done', $rAct('streams_on')['message']);
		$this->assertSame(NodeRegistry::FLOW_LOGS | NodeRegistry::FLOW_STREAMS, (int) NodeRegistry::byServer(5)['flows']);
		$rAct('logs_off');
		$this->assertSame(NodeRegistry::FLOW_STREAMS, (int) NodeRegistry::byServer(5)['flows']);
		NodeRegistry::update(5, ['state' => 'revoked']);
		$this->assertSame('cluster_not_enrolled', $rAct('telemetry_on')['message']);
	}
}
