<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\ReplicaEtagCache;
use XcVm\Core\Cluster\ReplicaSections;
use XcVm\Core\Events\EventDispatcher;
use XcVm\Core\Events\Server\ServerSavedEvent;
use XcVm\Core\Events\Settings\CrontabChangedEvent;
use XcVm\Core\Events\Settings\SettingsChangedEvent;
use XcVm\Domain\Cluster\ClusterApi;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\ClusterPolicy;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Domain\Cluster\ReplicaBuilder;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\FakeClusterCrypto;
use XcVm\Tests\Support\InstallSchema;

/**
 * The replica's R1 whole sections (cluster plan, section 9, Phase 7):
 * `servers`, `node`, `crontab` and `cluster` carry what a node needs and
 * nothing else (no liveness, telemetry, api_url or secret); their ETags are
 * the content's hash, reused for 10 s and dropped at once by a settings,
 * server or crontab save; a revoked or re-enrolled node is announced to the
 * others through `config.changed`.
 */
final class ReplicaSectionsTest extends TestCase {
	private TestDb $rDb;

	private string $rDir;

	private FakeClusterCrypto $rCrypto;

	private int $rT0 = 1800000000000;

	private const SECRETS = ['api_pass' => 'sekret-api', 'live_streaming_pass' => 'sekret-live', 'redis_password' => 'sekret-redis', 'license' => 'sekret-licence'];

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-sections-' . bin2hex(random_bytes(4));
		mkdir($this->rDir, 0777, true);
		$this->rDb = new TestDb();
		$this->rDb->exec(InstallSchema::serversTable());
		$this->rDb->exec($this->migration('029_create_cluster_nodes'));
		$this->rDb->exec($this->migration('030_create_cluster_commands'));
		$this->rDb->exec('ALTER TABLE `cluster_nodes` ADD COLUMN `root_ready` tinyint(1) NOT NULL DEFAULT 0');
		$this->rDb->exec('CREATE TABLE `crontab` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `filename` varchar(255), `time` varchar(128), `enabled` int, `role` varchar(8))');
		$this->rDb->exec('CREATE TABLE `settings` (`id` int, `cloudflare` tinyint, `mag_legacy_redirect` tinyint, `api_pass` text, `live_streaming_pass` text, `redis_password` text, `license` text, `seg_time` int)');
		$this->rDb->query('INSERT INTO `settings` VALUES (1, 1, 0, ?, ?, ?, ?, 6)', ...array_values(self::SECRETS));
		DatabaseFactory::set($this->rDb);
		$this->rCrypto = new FakeClusterCrypto();
		ClusterClock::fix($this->rT0);
		ReplicaEtagCache::useDir($this->rDir . '/etags/');
	}

	protected function tearDown(): void {
		ClusterClock::fix(null);
		ReplicaEtagCache::useDir(false); // the suite's default (tests/bootstrap.php)
		EventDispatcher::resetInstance();
		DatabaseFactory::reset();
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	private function migration(string $rName): string {
		$rSql = (string) file_get_contents(dirname(__DIR__, 2) . '/src/migrations/database/up/' . $rName . '.sql');
		$rSql = (string) preg_replace('/^--.*$/m', '', $rSql);
		$rSql = (string) preg_replace('/`id` bigint\(20\) unsigned NOT NULL AUTO_INCREMENT/', '`id` INTEGER PRIMARY KEY AUTOINCREMENT', $rSql);
		$rSql = (string) preg_replace('/,\s*PRIMARY KEY \(`id`\)/', '', $rSql);
		$rSql = (string) preg_replace('/,\s*(UNIQUE )?KEY `\w+` \([^)]*\)/', '', $rSql);
		$rSql = (string) preg_replace('/ unsigned| COLLATE \w+/', '', $rSql);
		return (string) preg_replace('/\) ENGINE=[^;]*;/', ');', $rSql);
	}

	private function server(int $rID, array $rFields = []): void {
		$rRow = $rFields + [
			'id' => $rID, 'server_type' => 0, 'server_name' => 'LB ' . $rID, 'server_ip' => '10.0.0.' . $rID, 'private_ip' => '', 'is_main' => (int) ($rID === 1),
			'enabled' => 1, 'parent_id' => '[]', 'http_broadcast_port' => 8080, 'https_broadcast_port' => 8443, 'rtmp_port' => 8880,
			'status' => 1, 'last_check_ago' => 1799999990, 'watchdog_data' => '{"cpu":12}', 'php_pids' => '[101,102]', 'connections' => 7, 'users' => 3,
			'server_hardware' => '{"total_ram":1}', 'limit_requests' => 50, 'limit_burst' => 100, 'total_services' => 4, 'network_interface' => 'eth0',
			'governor' => '["performance"]', 'sysctl' => 'net.core.somaxconn = 1', 'time_offset' => 2, 'whitelist_ips' => '["10.9.9.' . $rID . '"]',
			'uuid' => 'legacy-uuid-' . $rID, 'certbot_ssl' => '{"expiration":1}',
		];
		$this->rDb->query('INSERT INTO `servers` (`' . implode('`, `', array_keys($rRow)) . '`) VALUES (' . implode(', ', array_fill(0, count($rRow), '?')) . ')', ...array_values($rRow));
	}

	private function node(int $rServerID, string $rState = 'active', int $rFlows = NodeRegistry::FLOW_COMMANDS, int $rMode = 1): string {
		$rUuid = sprintf('00000000-0000-4000-a000-%012d', $rServerID);
		$this->rDb->query(
			'INSERT INTO `cluster_nodes` (`server_id`, `node_uuid`, `state`, `mode`, `flows`, `gen`, `node_sign_pub`, `node_box_pub`, `epoch`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
			$rServerID,
			$rUuid,
			$rState,
			$rMode,
			$rFlows,
			1,
			str_repeat(chr($rServerID), 32),
			sodium_crypto_scalarmult_base(random_bytes(32)),
			1,
			1800000000,
			1800000000
		);
		return $rUuid;
	}

	public function testEveryServersColumnIsClassified(): void {
		$rCarried = array_unique(array_merge(array_keys(ReplicaSections::SERVER_FIELDS), array_keys(ReplicaSections::NODE_FIELDS)));
		$this->assertSame([], array_values(array_intersect($rCarried, ReplicaSections::SERVER_LOCAL)), 'a column is either carried or local');
		$rColumns = InstallSchema::serverColumns();
		$this->assertGreaterThan(50, count($rColumns));
		$this->assertEqualsCanonicalizing($rColumns, array_merge($rCarried, ReplicaSections::SERVER_LOCAL), 'a new servers column: add it to ReplicaSections::SERVER_FIELDS, NODE_FIELDS or SERVER_LOCAL');
		foreach (['status', 'last_check_ago', 'watchdog_data', 'php_pids', 'connections', 'users', 'requests_per_second'] as $rLiveness) {
			$this->assertContains($rLiveness, ReplicaSections::SERVER_LOCAL);
		}
	}

	public function testTheServersSectionCarriesRoutingAndTheSignedNodeListOnly(): void {
		$this->server(1);
		$this->server(5, ['parent_id' => '[1]', 'domain_name' => 'lb5.example.com']);
		$this->server(6, ['server_type' => 1]);
		$this->node(5);
		$this->node(6, 'revoked');

		$rData = ReplicaBuilder::serversData();
		$this->assertSame([1, 5, 6], array_column($rData['servers'], 'id'));
		foreach ($rData['servers'] as $rRow) {
			$this->assertSame(array_keys(ReplicaSections::SERVER_FIELDS), array_keys($rRow));
		}
		$rFive = $rData['servers'][1];
		$this->assertSame(['[1]', 'lb5.example.com', 8080, '["10.9.9.5"]'], [$rFive['parent_id'], $rFive['domain_name'], $rFive['http_broadcast_port'], $rFive['whitelist_ips']]);
		$this->assertSame([
			['sid' => 5, 'gen' => 1, 'state' => 'active', 'ed_pub' => base64_encode(str_repeat(chr(5), 32))],
			['sid' => 6, 'gen' => 1, 'state' => 'revoked', 'ed_pub' => base64_encode(str_repeat(chr(6), 32))],
		], $rData['nodes']);

		$rJson = (string) json_encode($rData);
		foreach (['watchdog_data', 'php_pids', 'last_check_ago', '"status"', 'api_url', '"connections"', '"users"', 'limit_requests', 'governor', 'sysctl', 'server_hardware', 'uuid', 'time_offset'] as $rForbidden) {
			$this->assertStringNotContainsString($rForbidden, $rJson);
		}
	}

	public function testTheNodeSectionIsTheNodesOwnConfiguration(): void {
		$this->server(1);
		$this->server(5, ['limit_requests' => 25, 'use_disk' => 1, 'http_ports_add' => '8081,8082']);
		$rData = ReplicaBuilder::nodeData(5);
		$this->assertSame(array_merge(array_keys(ReplicaSections::NODE_FIELDS), array_keys(ReplicaSections::NODE_SETTINGS)), array_keys($rData));
		$this->assertSame([5, 25, 100, 4, 1, '8081,8082', 'eth0', 2, 1, 0], [$rData['id'], $rData['limit_requests'], $rData['limit_burst'], $rData['total_services'], $rData['use_disk'], $rData['http_ports_add'], $rData['network_interface'], $rData['time_offset'], $rData['cloudflare'], $rData['mag_legacy_redirect']]);
		$this->assertStringNotContainsString('watchdog', (string) json_encode($rData));
		$this->assertSame([], ReplicaBuilder::nodeData(99), 'no row: nothing to send');
	}

	public function testTheCrontabSectionHoldsTheRowsWhoseRoleFitsTheMode(): void {
		$this->rDb->exec("INSERT INTO `crontab` (`filename`, `time`, `enabled`, `role`) VALUES ('streams', '* * * * *', 1, 'all'), ('tmdb', '0 * * * *', 1, 'main'), ('users', '* * * * *', 1, 'legacy'), ('watch', '*/5 * * * *', 0, 'all'), ('cache_engine', '*/5 * * * *', 1, 'all')");
		$this->assertSame(['jobs' => [['filename' => 'streams', 'time' => '* * * * *'], ['filename' => 'users', 'time' => '* * * * *'], ['filename' => 'cache_engine', 'time' => '*/5 * * * *']]], ReplicaBuilder::crontabData(1), 'hybrid: all and legacy, never main or a disabled row');
		$this->assertSame(['streams', 'cache_engine'], array_column(ReplicaBuilder::crontabData(2)['jobs'], 'filename'), 'api mode: no legacy rows');
	}

	public function testTheClusterSectionCarriesThePolicyAndThePanelKeys(): void {
		$rSettings = ['cluster_transport' => 'auto', 'cluster_policy_ver' => 4, 'not_on_air_video_path' => '/home/xc_vm/content/video/custom_offline.ts', 'banned_video_path' => ''] + self::SECRETS;
		$rMain = ['id' => 1, 'server_ip' => '10.0.0.1', 'private_ip' => '192.168.0.1', 'http_broadcast_port' => 25461, 'enable_https' => 0];
		$rData = ReplicaBuilder::clusterData($this->rCrypto, $rSettings, $rMain);
		$rPolicy = ClusterPolicy::current($rSettings, $rMain);
		$this->assertSame($rPolicy['main_urls'], $rData['main_urls']);
		$this->assertSame([4, 4, 'auto', ClusterApi::PROTO_MIN], [$rData['policy_ver'], $rData['urls_ver'], $rData['transport'], $rData['min_proto']]);
		$this->assertSame(base64_encode($this->rCrypto->info()['panel_sign_pub']), $rData['panel_sign_pub']);
		$this->assertSame(base64_encode($this->rCrypto->info()['panel_box_pub']), $rData['panel_box_pub']);
		$this->assertSame(['connected' => null, 'not_on_air' => 'custom_offline.ts', 'banned' => null, 'expired' => null, 'expiring' => null], $rData['off_air']);
	}

	public function testNoSectionCarriesASecret(): void {
		$this->server(1);
		$this->server(5);
		$rNode = ['server_id' => 5, 'mode' => 1];
		$rSettings = ['cluster_policy_ver' => 1] + self::SECRETS;
		$rMain = ['id' => 1, 'server_ip' => '10.0.0.1', 'http_broadcast_port' => 80];
		foreach (ReplicaSections::WHOLE as $rSection) {
			$rJson = (string) json_encode(ReplicaBuilder::section($this->rCrypto, $rNode, $rSection, $rSettings, $rMain)['data']);
			foreach (self::SECRETS as $rSecret) {
				$this->assertStringNotContainsString($rSecret, $rJson, $rSection);
			}
		}
	}

	public function testTheEtagIsTheContentsHashReusedForTenSeconds(): void {
		$this->server(1);
		$this->server(5);
		$rNode = ['server_id' => 5, 'mode' => 1];
		$rFirst = ReplicaBuilder::section($this->rCrypto, $rNode, ReplicaSections::SERVERS, [], []);
		$this->assertSame($rFirst['etag'], ReplicaBuilder::etag($rFirst['data']));
		$this->assertSame($rFirst, ReplicaBuilder::section($this->rCrypto, $rNode, ReplicaSections::SERVERS, [], []), 'stable');

		// Typed the same whichever driver read the row.
		$this->assertSame(ReplicaSections::typed(['id' => 5, 'server_ip' => '10.0.0.5'], ReplicaSections::SERVER_FIELDS), ReplicaSections::typed(['id' => '5', 'server_ip' => '10.0.0.5'], ReplicaSections::SERVER_FIELDS));

		// Liveness never moves it.
		$this->rDb->query('UPDATE `servers` SET `status` = 0, `last_check_ago` = 1, `watchdog_data` = ?, `connections` = 99', '{"cpu":99}');
		ReplicaEtagCache::bump();
		$this->assertSame($rFirst['etag'], ReplicaBuilder::section($this->rCrypto, $rNode, ReplicaSections::SERVERS, [], [])['etag']);

		// A routing change is seen once the 10 s are over, or at once after a bump.
		$this->rDb->query('UPDATE `servers` SET `http_broadcast_port` = 9090 WHERE `id` = 5');
		ClusterClock::fix($this->rT0 + 9999);
		$this->assertSame($rFirst['etag'], ReplicaBuilder::section($this->rCrypto, $rNode, ReplicaSections::SERVERS, [], [])['etag'], 'cached for 10 s');
		ClusterClock::fix($this->rT0 + 10000);
		$rNew = ReplicaBuilder::section($this->rCrypto, $rNode, ReplicaSections::SERVERS, [], []);
		$this->assertNotSame($rFirst['etag'], $rNew['etag']);
		$this->assertSame(9090, $rNew['data']['servers'][1]['http_broadcast_port']);

		$this->rDb->query('UPDATE `servers` SET `http_broadcast_port` = 9191 WHERE `id` = 5');
		ReplicaEtagCache::bump();
		$this->assertSame(9191, ReplicaBuilder::section($this->rCrypto, $rNode, ReplicaSections::SERVERS, [], [])['data']['servers'][1]['http_broadcast_port']);

		// Per node, and per mode for the crontab.
		$this->server(6, ['limit_requests' => 7]);
		$this->assertSame(7, ReplicaBuilder::section($this->rCrypto, ['server_id' => 6, 'mode' => 1], ReplicaSections::NODE, [], [])['data']['limit_requests']);
		$this->assertSame(50, ReplicaBuilder::section($this->rCrypto, $rNode, ReplicaSections::NODE, [], [])['data']['limit_requests']);
		$this->rDb->exec("INSERT INTO `crontab` (`filename`, `time`, `enabled`, `role`) VALUES ('users', '* * * * *', 1, 'legacy')");
		$this->assertCount(1, ReplicaBuilder::section($this->rCrypto, $rNode, ReplicaSections::CRONTAB, [], [])['data']['jobs']);
		$this->assertCount(0, ReplicaBuilder::section($this->rCrypto, ['server_id' => 5, 'mode' => 2], ReplicaSections::CRONTAB, [], [])['data']['jobs']);
	}

	public function testSettingsServerAndCrontabSavesBumpTheEtags(): void {
		$this->server(5);
		$rNode = ['server_id' => 5, 'mode' => 1];
		ReplicaEtagCache::subscribe();
		foreach ([
			new SettingsChangedEvent(['seg_time' => 6], ['seg_time' => 8], 1, 1800000000.0),
			new ServerSavedEvent([5]),
			new CrontabChangedEvent(),
		] as $rEvent) {
			$rPort = random_int(1024, 65535);
			ReplicaBuilder::section($this->rCrypto, $rNode, ReplicaSections::NODE, [], []);
			$this->rDb->query('UPDATE `servers` SET `rtmp_port` = ? WHERE `id` = 5', $rPort);
			EventDispatcher::dispatch($rEvent);
			$this->assertSame($rPort, ReplicaBuilder::section($this->rCrypto, $rNode, ReplicaSections::NODE, [], [])['data']['rtmp_port'], get_class($rEvent));
		}
	}

	public function testWithoutACacheDirNothingIsCached(): void {
		ReplicaEtagCache::useDir(false);
		$this->server(5);
		$rNode = ['server_id' => 5, 'mode' => 1];
		ReplicaBuilder::section($this->rCrypto, $rNode, ReplicaSections::NODE, [], []);
		$this->rDb->query('UPDATE `servers` SET `rtmp_port` = 1935 WHERE `id` = 5');
		$this->assertSame(1935, ReplicaBuilder::section($this->rCrypto, $rNode, ReplicaSections::NODE, [], [])['data']['rtmp_port']);
	}

	public function testRevokeAndReenrolmentAnnounceTheNodeListAtOnce(): void {
		$this->server(1);
		foreach ([5, 6, 7, 8] as $rID) {
			$this->server($rID);
		}
		$this->node(5);
		$this->node(6);
		$this->node(7, 'active', 0); // no COMMANDS flow: it sees the change at its next poll
		$this->node(8, 'quarantined');
		$rBefore = ReplicaBuilder::section($this->rCrypto, ['server_id' => 5, 'mode' => 1], ReplicaSections::SERVERS, [], []);

		$this->assertTrue(NodeRegistry::revoke(6, $this->rCrypto));
		$this->rDb->query("SELECT `server_id`, `type`, `class`, `dedupe_key`, `payload` FROM `cluster_commands` ORDER BY `id`");
		$rRows = $this->rDb->get_rows();
		$this->assertSame([[5, 'config.changed', 'R', 'config.changed']], array_map(static fn(array $rRow): array => [(int) $rRow['server_id'], $rRow['type'], $rRow['class'], $rRow['dedupe_key']], $rRows), 'only the other nodes taking commands');
		$this->assertSame(['sections' => ['servers']], json_decode($rRows[0]['payload'], true)['args']);
		$rAfter = ReplicaBuilder::section($this->rCrypto, ['server_id' => 5, 'mode' => 1], ReplicaSections::SERVERS, [], []);
		$this->assertNotSame($rBefore['etag'], $rAfter['etag'], 'within the 10 s: the push is not answered from the cache');
		$this->assertSame('revoked', $rAfter['data']['nodes'][1]['state']);

		// Re-enrolling node 7 again: one config.changed for node 5, which supersedes the one not yet acked.
		NodeRegistry::startEnrolment(7, sprintf('00000000-0000-4000-b000-%012d', 7), str_repeat('x', 32), str_repeat('y', 32), 1, $this->rCrypto);
		$this->rDb->query("SELECT COUNT(*) AS `n` FROM `cluster_commands` WHERE `server_id` = 5 AND `type` = 'config.changed'");
		$this->assertSame(1, (int) $this->rDb->get_row()['n']);
		$this->assertSame(base64_encode(str_repeat('x', 32)), ReplicaBuilder::section($this->rCrypto, ['server_id' => 5, 'mode' => 1], ReplicaSections::SERVERS, [], [])['data']['nodes'][2]['ed_pub']);
	}

	public function testAFailedPushNeverFailsTheRevocation(): void {
		$this->node(5);
		$this->node(6);
		$this->rDb->exec('DROP TABLE `cluster_commands`');
		$this->assertTrue(NodeRegistry::revoke(6, $this->rCrypto));
		$this->assertSame('revoked', NodeRegistry::byServer(6)['state']);
	}
}
