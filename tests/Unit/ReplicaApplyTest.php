<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\CronJobs\RootSignalsCronJob;
use XcVm\Core\Cache\FileCache;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Core\Cluster\ReplicaApply;
use XcVm\Core\Cluster\ReplicaSections;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ReplicaBuilder;
use XcVm\Domain\Security\BlocklistService;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;

/**
 * cluster:apply (cluster plan, Phase 7): the blocklist the agent verified
 * becomes the node's caches in cron:cache's shapes. In shadow it only counts
 * how they differ; with CONFIG on it writes them, and the readers stop
 * rebuilding them from MAIN's database. The same goes for the whole sections
 * `servers` with `node` (the servers cache in ServerRepository::getAll's
 * shape) and `crontab` (the jobs the node's crontab runs); `cluster` is only
 * compared with the agent's own policy. A missing, foreign or malformed
 * section never touches a cache.
 */
final class ReplicaApplyTest extends TestCase {
	private string $rDir;

	private mixed $rSettingsBackup = null;

	private const DATA = [
		'ip' => ['203.0.113.1', '203.0.113.2'],
		'asn' => [64500],
		'ua' => [['id' => 3, 'user_agent' => 'Curl', 'exact_match' => 1]],
		'isp' => [['id' => 1, 'isp' => 'isp', 'blocked' => 1]],
		'rtmp' => [['id' => 1, 'ip' => '198.51.100.9', 'password' => 'pw', 'push' => 1, 'pull' => 0]],
	];

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-apply-' . bin2hex(random_bytes(4));
		mkdir($this->rDir . '/replica', 0777, true);
		mkdir($this->rDir . '/cache', 0777, true);
		ReplicaApply::useDir($this->rDir . '/replica/');
		(new \ReflectionProperty(FileCache::class, 'defaultInstance'))->setValue(null, new FileCache($this->rDir . '/cache/'));
		$this->flows(0);
		$this->rSettingsBackup = $GLOBALS['rSettings'] ?? null;
	}

	protected function tearDown(): void {
		DatabaseFactory::reset();
		SettingsManager::set([]);
		$GLOBALS['rSettings'] = $this->rSettingsBackup;
		ReplicaApply::useDir(null);
		NodeFlows::usePath(null);
		NodeRole::useServers(null);
		(new \ReflectionProperty(FileCache::class, 'defaultInstance'))->setValue(null, null);
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	private function flows(int $rFlows): void {
		file_put_contents($this->rDir . '/flows.json', json_encode(['mode' => 1, 'flows' => $rFlows, 'state' => 'active']));
		NodeFlows::usePath($this->rDir . '/flows.json');
	}

	private function replica(array $rData): void {
		file_put_contents($this->rDir . '/replica/blocklist.json', json_encode(['seq' => 9, 'etag' => str_repeat('a', 64), 'data' => $rData]));
	}

	public function testTheCachesKeepCronCachesShapes(): void {
		$this->assertSame([
			'blocked_ips' => ['203.0.113.1', '203.0.113.2'],
			'blocked_servers' => [64500],
			'blocked_ua' => [3 => ['id' => 3, 'exact_match' => 1, 'blocked_ua' => 'curl']],
			'blocked_isp' => [['id' => 1, 'isp' => 'isp', 'blocked' => 1]],
			'rtmp_ips' => ['198.51.100.9' => ['password' => 'pw', 'push' => true, 'pull' => false]],
		], ReplicaApply::caches(self::DATA));
		$this->assertNull(ReplicaApply::caches(['ip' => [['not' => 'a string']]]));
		$this->assertNull(ReplicaApply::caches(['asn' => ['64500']]));
	}

	public function testShadowOnlyCountsTheDifference(): void {
		// What cron:cache wrote from MAIN's database (strings, as the driver reads them).
		FileCache::setCache('blocked_ips', ['203.0.113.1', '203.0.113.9']);
		FileCache::setCache('blocked_servers', ['64500']);
		FileCache::setCache('blocked_ua', [3 => ['id' => '3', 'exact_match' => '1', 'blocked_ua' => 'curl']]);
		FileCache::setCache('blocked_isp', []);
		// RTMP publishers, which an LB reads from MAIN's database.
		$rDb = new TestDb();
		$rDb->exec('CREATE TABLE `rtmp_ips` (`id` INTEGER PRIMARY KEY, `ip` varchar(255), `password` varchar(128), `push` int, `pull` int)');
		$rDb->exec("INSERT INTO `rtmp_ips` VALUES (1, '198.51.100.9', 'old', 1, 0)");
		DatabaseFactory::set($rDb);
		$this->replica(self::DATA);

		$rReport = ReplicaApply::run(false, 1800000000);
		$this->assertSame('shadow', $rReport['mode']);
		$this->assertSame(9, $rReport['seq']);
		$this->assertSame([
			'blocked_ips' => ['missing' => 1, 'extra' => 1],
			'blocked_servers' => ['missing' => 0, 'extra' => 0],
			'blocked_ua' => ['missing' => 0, 'extra' => 0],
			'blocked_isp' => ['missing' => 0, 'extra' => 1],
			'rtmp_ips' => ['missing' => 1, 'extra' => 1], // the password changed
		], $rReport['diff']);
		$this->assertSame(['203.0.113.1', '203.0.113.9'], FileCache::getCache('blocked_ips'), 'nothing written in shadow');
		$this->assertSame($rReport, json_decode((string) file_get_contents($this->rDir . '/replica/apply.json'), true));
	}

	public function testWithConfigOnTheReplicaIsTheCache(): void {
		FileCache::setCache('blocked_ips', ['203.0.113.9']);
		$this->replica(self::DATA);
		$this->flows(NodeFlows::CONFIG);
		$this->assertSame('applied', ReplicaApply::run(true)['mode']);
		$this->assertSame(['203.0.113.1', '203.0.113.2'], FileCache::getCache('blocked_ips'));
		// The readers take the replica's caches however old, never the database.
		touch($this->rDir . '/cache/blocked_ips', time() - 3600);
		$this->assertSame(['203.0.113.1', '203.0.113.2'], BlocklistService::getBlockedIPs());
		$this->assertSame([64500], BlocklistService::getBlockedServers(true));
		$this->assertSame(['198.51.100.9' => ['password' => 'pw', 'push' => true, 'pull' => false]], BlocklistService::getAllowedRTMP());
	}

	public function testIptablesFollowsTheReplicaAndNeverAMissingOne(): void {
		$rDb = new TestDb();
		$rDb->exec('CREATE TABLE `blocked_ips` (`id` INTEGER PRIMARY KEY, `ip` varchar(39))');
		$rDb->exec("INSERT INTO `blocked_ips` VALUES (1, '203.0.113.7')");
		$this->assertSame(['203.0.113.7'], RootSignalsCronJob::blockedIPs($rDb), 'CONFIG off: MAIN\'s table');

		$this->flows(NodeFlows::CONFIG);
		$this->assertNull(RootSignalsCronJob::blockedIPs($rDb), 'no replica cache yet: leave iptables alone');
		FileCache::setCache('blocked_ips', ['203.0.113.1', '203.0.113.1', '203.0.113.2']);
		$this->assertSame(['203.0.113.1', '203.0.113.2'], RootSignalsCronJob::blockedIPs(null));
	}

	public function testNothingToApply(): void {
		$this->assertNull(ReplicaApply::run(false));
		file_put_contents($this->rDir . '/replica/blocklist.json', '{"data": {"ip": "nope"}}');
		$this->assertNull(ReplicaApply::run(false));
	}

	public function testSettingsStayInShadowAndNameWhatDiffers(): void {
		FileCache::setCache('settings', ['seg_time' => 6, 'server_name' => 'XC', 'api_ips' => ['10.0.0.1'], 'redis_password' => 'kept']);
		file_put_contents($this->rDir . '/replica/settings.json', json_encode(['etag' => str_repeat('b', 64), 'data' => ['seg_time' => '6', 'server_name' => 'Renamed', 'api_ips' => '10.0.0.1']]));
		$this->flows(NodeFlows::CONFIG);
		$rReport = ReplicaApply::run(true);
		$this->assertSame(['etag' => str_repeat('b', 64), 'mode' => 'shadow', 'keys' => 3, 'differ' => ['server_name']], $rReport['settings'], 'decoded as the panel reads them');
		$this->assertArrayNotHasKey('seq', $rReport, 'no blocklist to apply');
		$this->assertSame('XC', FileCache::getCache('settings')['server_name'], 'not written until the secrets section exists');
	}

	// ── The whole sections servers, node, crontab and cluster ─────────────

	/** A node's view of MAIN's database: every servers column, two servers, the crontab. */
	private function mainDb(): TestDb {
		if (!defined('SERVER_ID')) {
			define('SERVER_ID', 1); // as the suite's other tests define it
		}
		$rDb = new TestDb();
		$rDb->exec(InstallSchema::serversTable());
		$rDb->exec('CREATE TABLE `settings` (`id` int, `cloudflare` int, `mag_legacy_redirect` int)');
		$rDb->exec('INSERT INTO `settings` VALUES (1, 1, 0)');
		$rDb->exec('CREATE TABLE `cluster_nodes` (`server_id` int, `gen` int, `state` varchar(16), `node_sign_pub` blob)');
		$rDb->exec('CREATE TABLE `crontab` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `filename` varchar(255), `time` varchar(128), `enabled` int, `role` varchar(8))');
		$rDb->exec("INSERT INTO `crontab` (`filename`, `time`, `enabled`, `role`) VALUES ('streams', '* * * * *', 1, 'all'), ('tmdb', '0 * * * *', 1, 'main'), ('cache', '* * * * *', 1, 'all')");
		foreach ([SERVER_ID => ['limit_requests' => 30, 'total_services' => 6, 'governor' => '["performance"]'], SERVER_ID + 100 => ['is_main' => 1, 'limit_requests' => 99, 'domain_name' => 'main.example.com', 'enable_https' => 1]] as $rID => $rFields) {
			$rRow = $rFields + [
				'id' => $rID, 'server_type' => 0, 'server_name' => 'S' . $rID, 'server_ip' => '10.0.0.' . ($rID % 250), 'private_ip' => '', 'is_main' => 0, 'enabled' => 1,
				'parent_id' => '[]', 'geoip_countries' => '["DE"]', 'isp_names' => '[]', 'http_broadcast_port' => 8080, 'https_broadcast_port' => 8443, 'rtmp_port' => 8880,
				'http_ports_add' => '', 'https_ports_add' => '', 'status' => 1, 'last_check_ago' => time(), 'watchdog_data' => '{"cpu":3}', 'php_pids' => '[1]',
				'whitelist_ips' => '["10.9.9.9"]', 'network_interface' => 'eth0', 'time_offset' => 1, 'enable_https' => 0, 'domain_name' => '',
			];
			$rDb->query('INSERT INTO `servers` (`' . implode('`, `', array_keys($rRow)) . '`) VALUES (' . implode(', ', array_fill(0, count($rRow), '?')) . ')', ...array_values($rRow));
		}
		DatabaseFactory::set($rDb);
		$GLOBALS['rSettings'] = null;
		SettingsManager::set(['live_streaming_pass' => 'local-pass']);
		return $rDb;
	}

	/** Write a whole section as the agent does: replica/<name>.json = {etag, data}. */
	private function whole(string $rName, array $rData): void {
		file_put_contents($this->rDir . '/replica/' . $rName . '.json', json_encode(['etag' => ReplicaBuilder::etag($rData), 'data' => $rData]));
	}

	/** The replica MAIN would send this node, from the same database. */
	private function sections(): void {
		$this->whole('servers', ReplicaBuilder::serversData());
		$this->whole('node', ReplicaBuilder::nodeData(SERVER_ID));
		$this->whole('crontab', ReplicaBuilder::crontabData(1));
	}

	public function testWithConfigOnTheServersCacheKeepsGetAllsShape(): void {
		$rDb = $this->mainDb();
		$rOther = SERVER_ID + 100;
		$rFromDb = ServerRepository::getAll(true);
		$this->sections();
		FileCache::delCache('servers');
		$this->flows(NodeFlows::CONFIG);

		$rReport = ReplicaApply::run(true, null, SERVER_ID);
		$this->assertSame(['mode' => 'applied', 'rows' => 2], array_intersect_key($rReport['servers'], ['mode' => 1, 'rows' => 1]));
		$rCache = FileCache::getCache('servers');
		$this->assertSame(array_keys($rFromDb), array_keys($rCache));
		foreach ($rFromDb as $rID => $rRow) {
			ksort($rRow);
			$rMine = $rCache[$rID];
			ksort($rMine);
			$this->assertSame(array_keys($rRow), array_keys($rMine), 'the same keys as getAll builds');
			$rSkip = array_merge(ReplicaSections::SERVER_LOCAL, ['watchdog', 'cluster_health'], $rID === SERVER_ID ? [] : array_diff(array_keys(ReplicaSections::NODE_FIELDS), array_keys(ReplicaSections::SERVER_FIELDS)));
			$this->assertSame(array_diff_key($rRow, array_flip($rSkip)), array_diff_key($rMine, array_flip($rSkip)), 'server ' . $rID);
		}
		$this->assertSame('http://10.0.0.' . (SERVER_ID % 250) . ':8080/api?password=local-pass', $rCache[SERVER_ID]['api_url'], 'built here, from this node\'s own settings');
		$this->assertSame([30, 6, '["performance"]', 1], [$rCache[SERVER_ID]['limit_requests'], $rCache[SERVER_ID]['total_services'], $rCache[SERVER_ID]['governor'], $rCache[SERVER_ID]['time_offset']], 'the node section');
		$this->assertSame([null, null, null, null, true], [$rCache[$rOther]['limit_requests'], $rCache[$rOther]['status'], $rCache[$rOther]['watchdog_data'], $rCache[$rOther]['watchdog'], $rCache[$rOther]['server_online']], 'no other node\'s own settings, no liveness');

		// Every reader takes it, however old, never MAIN's database.
		$rDb->query('UPDATE `servers` SET `http_broadcast_port` = 1 WHERE `id` = ?', $rOther);
		touch($this->rDir . '/cache/servers', time() - 3600);
		$this->assertSame(8080, ServerRepository::getAll()[$rOther]['http_broadcast_port']);
		$this->assertSame(8080, ServerRepository::getAll(true)[$rOther]['http_broadcast_port']);

		// Without the replica's sections the node reads MAIN's database as before.
		unlink($this->rDir . '/replica/node.json');
		$this->assertSame(1, ServerRepository::getAll(true)[$rOther]['http_broadcast_port']);
	}

	public function testReadingTheFlowsEndsWhenTheReplicaOwnsTheServers(): void {
		// The agent's file is ignored on MAIN, which NodeRole reads from the
		// servers, which on this node the replica owns: asked of NodeFlows again.
		NodeRole::useServers(null);
		$this->mainDb();
		$this->sections();
		$this->flows(NodeFlows::CONFIG);
		ReplicaApply::run(true, null, SERVER_ID);
		NodeFlows::usePath($this->rDir . '/flows.json', true);
		$this->assertTrue(NodeFlows::on(NodeFlows::CONFIG));
		$this->assertSame(8080, ServerRepository::getAll(true)[SERVER_ID + 100]['http_broadcast_port']);

		$rCache = FileCache::getCache('servers');
		$rCache[SERVER_ID]['is_main'] = 1;
		FileCache::setCache('servers', $rCache);
		NodeFlows::usePath($this->rDir . '/flows.json', true);
		$this->assertFalse(NodeFlows::on(NodeFlows::CONFIG), 'MAIN never has flows, whatever a stray file says');
	}

	public function testServersInShadowOnlyNameWhatDiffers(): void {
		$this->mainDb();
		$rOther = SERVER_ID + 100;
		ServerRepository::getAll(true); // cron:cache's cache, from MAIN's database
		$rBefore = FileCache::getCache('servers');
		$rServers = ReplicaBuilder::serversData();
		$rServers['servers'][1]['rtmp_port'] = 1935;
		$rServers['servers'][] = ['id' => 999] + ReplicaSections::typed([], ReplicaSections::SERVER_FIELDS);
		$this->whole('servers', $rServers);
		$this->whole('node', ReplicaBuilder::nodeData(SERVER_ID));
		$this->flows(0);

		$rReport = ReplicaApply::run(false, null, SERVER_ID)['servers'];
		$this->assertSame(['shadow', 3, [], [999], [$rOther . '.rtmp_port']], [$rReport['mode'], $rReport['rows'], $rReport['missing'], $rReport['extra'], $rReport['differ']]);
		$this->assertSame($rBefore, FileCache::getCache('servers'), 'nothing written in shadow');
	}

	public function testAMissingForeignOrMalformedSectionNeverTouchesTheServersCache(): void {
		$this->mainDb();
		$this->flows(NodeFlows::CONFIG);
		FileCache::setCache('servers', ['kept' => true]);
		$rServers = ReplicaBuilder::serversData();
		$rNode = ReplicaBuilder::nodeData(SERVER_ID);

		$this->whole('servers', $rServers);
		$this->assertSame('incomplete', ReplicaApply::run(true, null, SERVER_ID)['servers']['mode'], 'no node section yet');
		foreach ([
			'another node\'s section' => [$rServers, ['id' => SERVER_ID + 1] + $rNode],
			'no row for this node' => [['servers' => [$rServers['servers'][1]], 'nodes' => []], $rNode],
			'not a list' => [['servers' => 'x', 'nodes' => []], $rNode],
			'a bad node entry' => [['servers' => $rServers['servers'], 'nodes' => [['sid' => 5, 'gen' => 1, 'state' => 'gone', 'ed_pub' => '']]], $rNode],
		] as $rWhy => [$rS, $rN]) {
			$this->whole('servers', $rS);
			$this->whole('node', $rN);
			$this->assertSame('refused', ReplicaApply::run(true, null, SERVER_ID)['servers']['mode'], $rWhy);
		}
		file_put_contents($this->rDir . '/replica/servers.json', '{"etag": "x", "data": ');
		$this->assertSame('refused', ReplicaApply::run(true, null, SERVER_ID)['servers']['mode'], 'unreadable');
		$this->assertSame(['kept' => true], FileCache::getCache('servers'));
	}

	public function testTheCrontabSectionIsTheJobsTheNodesCrontabRuns(): void {
		$rDb = $this->mainDb();
		$this->assertSame(['streams', 'tmdb', 'cache'], array_column(ReplicaApply::cronJobs($rDb), 'filename'), 'CONFIG off: MAIN\'s table');
		$this->sections();

		$rReport = ReplicaApply::run(false, null, SERVER_ID)['crontab'];
		$this->assertSame(['shadow', 2, ['tmdb'], []], [$rReport['mode'], $rReport['jobs'], $rReport['missing'], $rReport['extra']], 'MAIN\'s own rows stay on MAIN');
		$this->assertFalse(FileCache::getCache(ReplicaApply::CRON_CACHE));

		$this->flows(NodeFlows::CONFIG);
		$this->assertNull(ReplicaApply::cronJobs($rDb), 'the section is there but not applied yet: leave the crontab as it is');
		$this->assertSame('applied', ReplicaApply::run(true, null, SERVER_ID)['crontab']['mode']);
		$this->assertSame([['filename' => 'streams', 'time' => '* * * * *'], ['filename' => 'cache', 'time' => '* * * * *']], ReplicaApply::cronJobs(null));

		// A job a shell would read more into is refused, and the jobs stay.
		$this->whole('crontab', ['jobs' => [['filename' => 'streams; rm -rf /', 'time' => '* * * * *']]]);
		$this->assertSame('refused', ReplicaApply::run(true, null, SERVER_ID)['crontab']['mode']);
		$this->whole('crontab', ['jobs' => [['filename' => 'streams', 'time' => '* * * * * /bin/sh']]]);
		$this->assertSame('refused', ReplicaApply::run(true, null, SERVER_ID)['crontab']['mode']);
		$this->assertCount(2, ReplicaApply::cronJobs(null));

		unlink($this->rDir . '/replica/crontab.json');
		$this->assertCount(3, ReplicaApply::cronJobs($rDb), 'no crontab section: MAIN\'s table, as before');
	}

	public function testTheClusterSectionIsComparedWithTheAgentsPolicy(): void {
		file_put_contents($this->rDir . '/agent.json', json_encode(['panel_sign_pub' => base64_encode(str_repeat('k', 32)), 'main_urls' => ['http://10.0.0.1:80/cluster/v1/'], 'policy_ver' => 3]));
		$rData = ['main_urls' => ['http://10.0.0.1:80/cluster/v1/'], 'urls_ver' => 4, 'policy_ver' => 4, 'transport' => 'auto', 'panel_sign_pub' => base64_encode(str_repeat('k', 32)), 'panel_box_pub' => base64_encode(str_repeat('b', 32)), 'min_proto' => 1, 'off_air' => []];
		$this->whole('cluster', $rData);
		$this->flows(NodeFlows::CONFIG);
		$rReport = ReplicaApply::run(true, null, 5)['cluster'];
		$this->assertSame(['shadow', 4, ['policy_ver']], [$rReport['mode'], $rReport['policy_ver'], $rReport['differ']], 'the agent adopts the policy; PHP reads none of it');

		$this->whole('cluster', ['panel_sign_pub' => base64_encode(str_repeat('z', 32))] + $rData);
		$this->assertSame(['panel_sign_pub', 'policy_ver'], ReplicaApply::run(true, null, 5)['cluster']['differ']);
		$this->whole('cluster', ['main_urls' => 'nope'] + $rData);
		$this->assertSame('refused', ReplicaApply::run(true, null, 5)['cluster']['mode']);
	}
}
