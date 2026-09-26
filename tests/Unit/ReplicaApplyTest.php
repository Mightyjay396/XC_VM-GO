<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\CronJobs\RootSignalsCronJob;
use XcVm\Core\Cache\FileCache;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\ReplicaApply;
use XcVm\Domain\Security\BlocklistService;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * cluster:apply (cluster plan, Phase 7): the blocklist the agent verified
 * becomes the node's caches in cron:cache's shapes. In shadow it only counts
 * how they differ; with CONFIG on it writes them, and the readers stop
 * rebuilding them from MAIN's database.
 */
final class ReplicaApplyTest extends TestCase {
	private string $rDir;

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
	}

	protected function tearDown(): void {
		DatabaseFactory::reset();
		ReplicaApply::useDir(null);
		NodeFlows::usePath(null);
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
}
