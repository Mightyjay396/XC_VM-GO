<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\ClusterEndpoint;
use XcVm\Domain\Cluster\ClusterPolicy;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * MAIN endpoint changes (Phase 3): a new HTTP port is announced through the
 * policy version, and the old one keeps serving the cluster API alone for
 * seven days, listed last in the nodes' policy.
 */
final class ClusterEndpointTest extends TestCase {
	private TestDb $rDb;

	private int $rNow = 1800000000;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec("CREATE TABLE `settings` (`id` INTEGER PRIMARY KEY, `cluster_policy_ver` int DEFAULT 1, `cluster_legacy_ports` varchar(255) DEFAULT '')");
		$this->rDb->exec('INSERT INTO `settings` (`id`) VALUES (1)');
		$this->rDb->exec('CREATE TABLE `cluster_audit` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `time` int, `server_id` int, `actor` varchar(64), `event` varchar(64), `detail` text, `ip` varchar(64))');
		DatabaseFactory::set($this->rDb);
		ClusterClock::fix($this->rNow * 1000);
	}

	protected function tearDown(): void {
		ClusterClock::fix(null);
		DatabaseFactory::reset();
	}

	private function settings(): array {
		$this->rDb->query('SELECT * FROM `settings`');
		return $this->rDb->get_row();
	}

	public function testAPortChangeIsAnnouncedAndTheOldPortKept(): void {
		$this->assertTrue(ClusterEndpoint::recordChange(25461, 8080, $this->settings()));
		$rSettings = $this->settings();
		$this->assertSame(2, (int) $rSettings['cluster_policy_ver'], 'agents refetch the policy');
		$this->assertSame([25461 => $this->rNow + ClusterEndpoint::GRACE], ClusterEndpoint::legacyPorts($rSettings));

		$rMain = ['server_ip' => '10.0.0.1', 'private_ip' => '192.168.0.1', 'http_broadcast_port' => 8080];
		$this->assertSame([
			'http://192.168.0.1:8080/cluster/v1/', 'http://10.0.0.1:8080/cluster/v1/',
			'http://192.168.0.1:25461/cluster/v1/', 'http://10.0.0.1:25461/cluster/v1/',
		], ClusterPolicy::current($rSettings, $rMain)['main_urls'], 'new first, the old port last');
		$this->assertSame(2, ClusterPolicy::current($rSettings, $rMain)['policy_ver']);

		// Moving back to the old port drops it from the kept list.
		ClusterEndpoint::recordChange(8080, 25461, $this->settings());
		$this->assertSame([8080], array_keys(ClusterEndpoint::legacyPorts($this->settings())));
	}

	public function testNothingIsRecordedWhenTheApiHasItsOwnPort(): void {
		$this->assertFalse(ClusterEndpoint::recordChange(25461, 8080, ['cluster_api_port' => 31200] + $this->settings()));
		$this->assertFalse(ClusterEndpoint::recordChange(25461, 25461, $this->settings()));
		$this->assertSame(1, (int) $this->settings()['cluster_policy_ver']);
	}

	public function testExpiredPortsArePruned(): void {
		ClusterEndpoint::recordChange(25461, 8080, $this->settings());
		$this->assertFalse(ClusterEndpoint::prune($this->settings()), 'nothing expired yet');
		ClusterClock::fix(($this->rNow + ClusterEndpoint::GRACE + 1) * 1000);
		$this->assertSame([], ClusterEndpoint::legacyPorts($this->settings()));
		$this->assertTrue(ClusterEndpoint::prune($this->settings()));
		$this->assertSame('', $this->settings()['cluster_legacy_ports']);
		$this->assertSame(3, (int) $this->settings()['cluster_policy_ver']);
	}

	/**
	 * `cluster_api_port` changes the same way: the policy moves the nodes to
	 * the new URL and the old one is kept for seven days (ClusterNginxConfig
	 * serves it). While the API is on the broadcast port, that is its URL.
	 */
	public function testAnApiPortChangeIsAnnouncedAndTheOldUrlKept(): void {
		$rMain = ['server_ip' => '10.0.0.1', 'http_broadcast_port' => 25461];
		$rUntil = $this->rNow + ClusterEndpoint::GRACE;

		$this->assertTrue(ClusterEndpoint::recordApiPortChange(0, 31200, ['cluster_api_enabled' => 1] + $this->settings(), $rMain));
		$rSettings = ['cluster_api_enabled' => 1, 'cluster_api_port' => 31200] + $this->settings();
		$this->assertSame(2, (int) $rSettings['cluster_policy_ver'], 'agents refetch the policy');
		$this->assertSame([25461 => $rUntil], ClusterEndpoint::legacyPorts($rSettings));
		$this->assertSame(['http://10.0.0.1:31200/cluster/v1/', 'http://10.0.0.1:25461/cluster/v1/'], ClusterPolicy::current($rSettings, $rMain)['main_urls'], 'new first, the old URL last');

		$this->assertTrue(ClusterEndpoint::recordApiPortChange(31200, 31300, $rSettings, $rMain));
		$rSettings = ['cluster_api_enabled' => 1, 'cluster_api_port' => 31300] + $this->settings();
		$this->assertSame([25461 => $rUntil, 31200 => $rUntil], ClusterEndpoint::legacyPorts($rSettings));

		// Back on the broadcast port: it leaves the kept list, the API's own port joins it.
		$this->assertTrue(ClusterEndpoint::recordApiPortChange(31300, 0, $rSettings, $rMain));
		$rSettings = ['cluster_api_enabled' => 1, 'cluster_api_port' => 0] + $this->settings();
		$this->assertSame([31200 => $rUntil, 31300 => $rUntil], ClusterEndpoint::legacyPorts($rSettings));
		$this->assertSame(4, (int) $rSettings['cluster_policy_ver']);

		// The same URL, or no node that could use it: nothing to announce.
		$this->assertNull(ClusterEndpoint::afterApiPortChange(0, 25461, $rSettings, $rMain), 'the broadcast port either way');
		$this->assertFalse(ClusterEndpoint::recordApiPortChange(0, 0, $rSettings, $rMain));
		$this->assertFalse(ClusterEndpoint::recordApiPortChange(0, 31200, ['cluster_api_enabled' => 0] + $rSettings, $rMain), 'the API is off');
		$this->assertSame(4, (int) $this->settings()['cluster_policy_ver']);
	}
}
