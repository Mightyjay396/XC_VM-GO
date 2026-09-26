<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\CronJobs\RootSignalsCronJob;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\ClusterEndpoint;
use XcVm\Domain\Cluster\ClusterNginxConfig;
use XcVm\Domain\Cluster\ClusterPolicy;
use XcVm\Domain\Server\ServerService;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * MAIN endpoint changes (plan §3, "Endpoint changes"): while a node is in
 * mode ≥ 1, a change of MAIN's HTTP or HTTPS port, server_ip or private_ip
 * is announced through the policy version. The old port keeps serving the
 * cluster API alone for seven days, and the old URL stays in the nodes'
 * policy, after the new ones, for as long.
 */
final class ClusterEndpointTest extends TestCase {
	private TestDb $rDb;

	private int $rNow = 1800000000;

	/** MAIN's `servers` row. */
	private array $rMain = [
		'id' => 1, 'is_main' => 1, 'server_ip' => '10.0.0.1', 'private_ip' => '192.168.0.1', 'domain_name' => 'panel.example.com',
		'enable_https' => 1, 'http_broadcast_port' => 25461, 'https_broadcast_port' => 25463,
	];

	private array $rSettingsBefore = [];

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec("CREATE TABLE `settings` (`id` INTEGER PRIMARY KEY, `cluster_api_enabled` int DEFAULT 0, `cluster_api_port` int DEFAULT 0, `cluster_transport` varchar(16) DEFAULT 'auto', `cluster_main_host` varchar(255) DEFAULT '', `cluster_policy_ver` int DEFAULT 1, `cluster_legacy_ports` varchar(255) DEFAULT '', `cluster_legacy_urls` text)");
		$this->rDb->exec('INSERT INTO `settings` (`id`) VALUES (1)');
		$this->rDb->exec('CREATE TABLE `cluster_audit` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `time` int, `server_id` int, `actor` varchar(64), `event` varchar(64), `detail` text, `ip` varchar(64))');
		$this->rDb->exec("CREATE TABLE `cluster_nodes` (`server_id` INTEGER PRIMARY KEY, `state` varchar(16) NOT NULL DEFAULT 'enrolling', `mode` int NOT NULL DEFAULT 1)");
		DatabaseFactory::set($this->rDb);
		ClusterClock::fix($this->rNow * 1000);
		$this->rSettingsBefore = SettingsManager::getAll();
	}

	protected function tearDown(): void {
		SettingsManager::set($this->rSettingsBefore);
		ClusterClock::fix(null);
		DatabaseFactory::reset();
	}

	private function settings(): array {
		$this->rDb->query('SELECT * FROM `settings`');
		return $this->rDb->get_row();
	}

	/** The stored settings with the cluster API on, and $rOver on top. */
	private function live(array $rOver = []): array {
		return $rOver + ['cluster_api_enabled' => 1] + $this->settings();
	}

	private function store(string $rColumn, int|string $rValue): void {
		$this->rDb->query('UPDATE `settings` SET `' . $rColumn . '` = ?', $rValue);
	}

	private function node(int $rServerID, string $rState, int $rMode = 1): void {
		$this->rDb->query('INSERT INTO `cluster_nodes` (`server_id`, `state`, `mode`) VALUES (?, ?, ?)', $rServerID, $rState, $rMode);
	}

	private function ver(): int {
		return (int) $this->settings()['cluster_policy_ver'];
	}

	/** @return list<array{event: string, actor: string, detail: array<string, mixed>}> */
	private function audit(): array {
		$this->rDb->query('SELECT `event`, `actor`, `detail` FROM `cluster_audit` ORDER BY `id`');
		return array_map(static fn(array $rRow): array => ['event' => (string) $rRow['event'], 'actor' => (string) $rRow['actor'], 'detail' => (array) json_decode((string) $rRow['detail'], true)], $this->rDb->get_rows());
	}

	public function testAPortChangeIsAnnouncedAndTheOldPortKept(): void {
		$this->node(2, 'active');
		$rNew = ['http_broadcast_port' => 8080] + $this->rMain;
		$this->assertTrue(ClusterEndpoint::recordMainChange($this->rMain, $rNew, $this->live()));
		$rSettings = $this->live();
		$this->assertSame(2, (int) $rSettings['cluster_policy_ver'], 'agents refetch the policy');
		$this->assertSame([25461 => $this->rNow + ClusterEndpoint::GRACE], ClusterEndpoint::legacyPorts($rSettings));
		$this->assertSame([], ClusterEndpoint::legacyUrls($rSettings), 'the kept port covers the old URLs');

		$this->assertSame([
			'http://192.168.0.1:8080/cluster/v1/', 'http://10.0.0.1:8080/cluster/v1/',
			'http://192.168.0.1:25461/cluster/v1/', 'http://10.0.0.1:25461/cluster/v1/',
		], ClusterPolicy::current($rSettings, $rNew)['main_urls'], 'new first, the old port last');
		$this->assertSame(2, ClusterPolicy::current($rSettings, $rNew)['policy_ver']);

		// Moving back to the old port drops it from the kept list.
		ClusterEndpoint::recordMainChange($rNew, $this->rMain, $this->live());
		$this->assertSame([8080], array_keys(ClusterEndpoint::legacyPorts($this->settings())));
	}

	public function testNothingIsRecordedWhenTheApiHasItsOwnPort(): void {
		$this->node(2, 'active');
		$this->store('cluster_api_port', 31200);
		$this->assertFalse(ClusterEndpoint::recordMainChange($this->rMain, ['http_broadcast_port' => 8080] + $this->rMain, $this->live()));
		$this->store('cluster_api_port', 0);
		$this->assertFalse(ClusterEndpoint::recordMainChange($this->rMain, $this->rMain, $this->live()));
		$this->assertSame(1, $this->ver());
	}

	public function testExpiredPortsArePruned(): void {
		$this->node(2, 'active');
		ClusterEndpoint::recordMainChange($this->rMain, ['http_broadcast_port' => 8080] + $this->rMain, $this->live());
		$this->assertFalse(ClusterEndpoint::prune($this->settings()), 'nothing expired yet');
		ClusterClock::fix(($this->rNow + ClusterEndpoint::GRACE + 1) * 1000);
		$this->assertSame([], ClusterEndpoint::legacyPorts($this->settings()));
		$this->assertTrue(ClusterEndpoint::prune($this->settings()));
		$this->assertSame('', $this->settings()['cluster_legacy_ports']);
		$this->assertSame(3, $this->ver());
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
		$this->assertSame(4, $this->ver());
	}

	/**
	 * MAIN's HTTPS port changes while the policy lists HTTPS: announced, and
	 * the old HTTPS URL is kept. nginx serves the old port for the cluster
	 * API alone, over TLS with the public server's certificate.
	 */
	public function testAnHttpsPortChangeIsAnnouncedAndTheOldPortKept(): void {
		$this->node(2, 'active');
		$rUntil = $this->rNow + ClusterEndpoint::GRACE;
		$rNew = ['https_broadcast_port' => 8443] + $this->rMain;
		$this->assertTrue(ClusterEndpoint::recordMainChange($this->rMain, $rNew, $this->live(['cluster_transport' => 'https_preferred'])));

		$rSettings = $this->live(['cluster_transport' => 'https_preferred']);
		$this->assertSame(2, (int) $rSettings['cluster_policy_ver'], 'agents refetch the policy');
		$this->assertSame(['https://panel.example.com:25463/cluster/v1/' => $rUntil], ClusterEndpoint::legacyUrls($rSettings));
		$this->assertSame([25463 => $rUntil], ClusterEndpoint::legacyHttpsPorts($rSettings));
		$this->assertSame([], ClusterEndpoint::legacyPorts($rSettings), 'no plain-HTTP port moved');
		$this->assertSame([
			'https://panel.example.com:8443/cluster/v1/',
			'http://192.168.0.1:25461/cluster/v1/', 'http://10.0.0.1:25461/cluster/v1/',
			'https://panel.example.com:25463/cluster/v1/',
		], ClusterPolicy::current($rSettings, $rNew)['main_urls'], 'new first, the old HTTPS URL last');
		$this->assertSame(
			['https://panel.example.com:8443/cluster/v1/', 'https://panel.example.com:25463/cluster/v1/'],
			ClusterPolicy::current(['cluster_transport' => 'https_required'] + $rSettings, $rNew)['main_urls'],
			'https_required lists the old HTTPS URL, and still no plain HTTP'
		);

		$rOld = (string) ClusterNginxConfig::render($rSettings, [25461, 8443], $this->rNow)[ClusterNginxConfig::OLD_PORT];
		$this->assertStringContainsString("listen 25463 ssl;\n", $rOld);
		$this->assertStringContainsString("include ssl.conf;\n", $rOld, "the public server's certificate");
		$this->assertStringContainsString("include cluster_locations.conf;\n", $rOld, 'the cluster API alone');
		$this->assertMatchesRegularExpression('#location / \{\s*return 404;\s*\}#', $rOld);
		$this->assertNull(ClusterNginxConfig::render($rSettings, [25461, 25463], $this->rNow)[ClusterNginxConfig::OLD_PORT], 'the public server serves it again');

		// Back to the old port: in use again, it leaves the kept list, and the one just left joins it.
		$this->assertTrue(ClusterEndpoint::recordMainChange($rNew, $this->rMain, $this->live(['cluster_transport' => 'https_preferred'])));
		$this->assertSame(['https://panel.example.com:8443/cluster/v1/'], array_keys(ClusterEndpoint::legacyUrls($this->settings())));
		$this->assertSame(3, $this->ver());
		$this->assertSame(['cluster.endpoint_change', 'cluster.endpoint_change'], array_column($this->audit(), 'event'));
		$this->assertSame(['https://panel.example.com:25463/cluster/v1/'], $this->audit()[0]['detail']['kept_urls']);
	}

	/** An HTTPS port no node is sent to (the policy lists no HTTPS URL) is nothing to announce. */
	public function testAnHttpsPortTheNodesDoNotUseIsNotAnnounced(): void {
		$this->node(2, 'active');
		$rNew = ['https_broadcast_port' => 8443] + $this->rMain;
		foreach (['auto', 'http'] as $rTransport) {
			$this->assertFalse(ClusterEndpoint::recordMainChange($this->rMain, $rNew, $this->live(['cluster_transport' => $rTransport])), $rTransport);
		}
		$rNoName = ['domain_name' => ''] + $this->rMain;
		$this->assertFalse(ClusterEndpoint::recordMainChange($rNoName, ['https_broadcast_port' => 8443] + $rNoName, $this->live(['cluster_transport' => 'https_preferred'])), 'no TLS name: no HTTPS URL');
		$rOff = ['enable_https' => 0] + $this->rMain;
		$this->assertFalse(ClusterEndpoint::recordMainChange($rOff, ['https_broadcast_port' => 8443] + $rOff, $this->live(['cluster_transport' => 'https_preferred'])), 'HTTPS off on MAIN');
		$this->assertSame(1, $this->ver());
		$this->assertSame([], $this->audit());
	}

	/**
	 * The admin edits MAIN's server row (ServerService::process()): a new
	 * server_ip or private_ip is announced, and the old URL is kept.
	 */
	public function testAnAddressChangeOnMainsServerPageIsAnnounced(): void {
		$this->node(2, 'active');
		SettingsManager::set($this->live());
		$rUntil = $this->rNow + ClusterEndpoint::GRACE;

		$this->assertTrue(ServerService::announceMainEndpoints($this->rMain, ['server_ip' => '10.0.0.2', 'private_ip' => '192.168.0.1', 'server_name' => 'Main']));
		$this->assertSame(2, $this->ver(), 'agents refetch the policy');
		$rNew = ['server_ip' => '10.0.0.2'] + $this->rMain;
		$this->assertSame(['http://10.0.0.1:25461/cluster/v1/' => $rUntil], ClusterEndpoint::legacyUrls($this->settings()));
		$this->assertSame([], ClusterEndpoint::legacyPorts($this->settings()), 'no port moved');
		$this->assertSame(
			['http://192.168.0.1:25461/cluster/v1/', 'http://10.0.0.2:25461/cluster/v1/', 'http://10.0.0.1:25461/cluster/v1/'],
			ClusterPolicy::current($this->live(), $rNew)['main_urls'],
			'new first, the old URL last'
		);
		$this->assertNull(ClusterNginxConfig::render($this->live(), [25461, 25463], $this->rNow)[ClusterNginxConfig::OLD_PORT], 'nginx listens on every address already');

		// The private IP goes a minute later: its URL is kept too, the latest change first.
		ClusterClock::fix(($this->rNow + 60) * 1000);
		$this->assertTrue(ServerService::announceMainEndpoints($rNew, ['private_ip' => '']));
		$rNew = ['private_ip' => ''] + $rNew;
		$this->assertSame(['http://192.168.0.1:25461/cluster/v1/' => $rUntil + 60, 'http://10.0.0.1:25461/cluster/v1/' => $rUntil], ClusterEndpoint::legacyUrls($this->settings()));
		$this->assertSame(
			['http://10.0.0.2:25461/cluster/v1/', 'http://192.168.0.1:25461/cluster/v1/', 'http://10.0.0.1:25461/cluster/v1/'],
			ClusterPolicy::current($this->live(), $rNew)['main_urls']
		);

		// Back to the first address: in use again, it leaves the kept list.
		$this->assertTrue(ServerService::announceMainEndpoints($rNew, ['server_ip' => '10.0.0.1']));
		$rNew = ['server_ip' => '10.0.0.1'] + $rNew;
		$this->assertSame(['http://10.0.0.2:25461/cluster/v1/', 'http://192.168.0.1:25461/cluster/v1/'], array_keys(ClusterEndpoint::legacyUrls($this->settings())));
		$this->assertSame(4, $this->ver());

		// A save that changes nothing the nodes use, or a server that is not MAIN: nothing.
		$this->assertFalse(ServerService::announceMainEndpoints($rNew, ['server_name' => 'Main 2', 'total_services' => 8, 'server_ip' => '10.0.0.1', 'http_broadcast_port' => '25461']));
		$this->assertFalse(ServerService::announceMainEndpoints(['is_main' => 0] + $rNew, ['server_ip' => '10.0.0.7']));
		$this->assertSame(4, $this->ver());
		$this->assertSame(['admin'], array_values(array_unique(array_column($this->audit(), 'actor'))));
	}

	/** The address and the port change in one save: one announcement keeps both. */
	public function testAnAddressAndPortChangeAtOnce(): void {
		$this->node(2, 'active');
		$rNew = ['server_ip' => '10.0.0.2', 'http_broadcast_port' => 8080] + $this->rMain;
		$this->assertTrue(ClusterEndpoint::recordMainChange($this->rMain, $rNew, $this->live()));
		$this->assertSame(2, $this->ver());
		$this->assertSame([25461], array_keys(ClusterEndpoint::legacyPorts($this->settings())));
		$this->assertSame(['http://10.0.0.1:25461/cluster/v1/'], array_keys(ClusterEndpoint::legacyUrls($this->settings())), 'the old address on the old port');
		$this->assertSame([
			'http://192.168.0.1:8080/cluster/v1/', 'http://10.0.0.2:8080/cluster/v1/',
			'http://192.168.0.1:25461/cluster/v1/', 'http://10.0.0.2:25461/cluster/v1/',
			'http://10.0.0.1:25461/cluster/v1/',
		], ClusterPolicy::current($this->live(), $rNew)['main_urls']);
	}

	/** cron:root_signals rewrites MAIN's server_ip from its interface: the same announcement. */
	public function testTheAutomaticServerIpRewriteIsAnnounced(): void {
		$this->rDb->exec("CREATE TABLE `servers` (`id` INTEGER PRIMARY KEY, `server_ip` varchar(255) DEFAULT '')");
		$this->rDb->query('INSERT INTO `servers` (`id`, `server_ip`) VALUES (1, ?)', '10.0.0.1');
		$this->node(2, 'active');
		SettingsManager::set($this->live());

		RootSignalsCronJob::rewriteServerIP($this->rDb, 1, $this->rMain, '10.0.0.9');
		$this->rDb->query('SELECT `server_ip` FROM `servers` WHERE `id` = 1');
		$this->assertSame('10.0.0.9', $this->rDb->get_row()['server_ip']);
		$this->assertSame(2, $this->ver(), 'agents refetch the policy');
		$this->assertSame(['http://10.0.0.1:25461/cluster/v1/'], array_keys(ClusterEndpoint::legacyUrls($this->settings())));
		$this->assertSame(
			['http://192.168.0.1:25461/cluster/v1/', 'http://10.0.0.9:25461/cluster/v1/', 'http://10.0.0.1:25461/cluster/v1/'],
			ClusterPolicy::current($this->live(), ['server_ip' => '10.0.0.9'] + $this->rMain)['main_urls']
		);
		$this->assertSame([['cluster.endpoint_change', 'system']], array_map(static fn(array $rRow): array => [$rRow['event'], $rRow['actor']], $this->audit()));

		// With no node in mode ≥ 1 the address is stored all the same, and nothing is announced.
		$this->rDb->query('DELETE FROM `cluster_nodes`');
		RootSignalsCronJob::rewriteServerIP($this->rDb, 1, ['server_ip' => '10.0.0.9'] + $this->rMain, '10.0.0.10');
		$this->rDb->query('SELECT `server_ip` FROM `servers` WHERE `id` = 1');
		$this->assertSame('10.0.0.10', $this->rDb->get_row()['server_ip']);
		$this->assertSame(2, $this->ver());
	}

	/** No node in mode ≥ 1 (or the API off): no node uses MAIN's URLs, so nothing is announced or kept. */
	public function testNothingIsAnnouncedWithoutANodeInModeOneOrMore(): void {
		$rNew = ['server_ip' => '10.0.0.2', 'private_ip' => '', 'http_broadcast_port' => 8080, 'https_broadcast_port' => 8443] + $this->rMain;
		$rSettings = $this->live(['cluster_transport' => 'https_preferred']);
		$this->assertFalse(ClusterEndpoint::recordMainChange($this->rMain, $rNew, $rSettings), 'no node');
		$this->node(2, 'revoked');
		$this->assertFalse(ClusterEndpoint::recordMainChange($this->rMain, $rNew, $rSettings), 'a revoked node');
		$this->node(3, 'active', 0);
		$this->assertFalse(ClusterEndpoint::recordMainChange($this->rMain, $rNew, $rSettings), 'a node in mode 0');
		$this->node(4, 'active');
		$this->assertFalse(ClusterEndpoint::recordMainChange($this->rMain, $rNew, ['cluster_api_enabled' => 0] + $rSettings), 'the API is off');
		$this->assertSame([1, '', ''], [$this->ver(), $this->settings()['cluster_legacy_ports'], (string) $this->settings()['cluster_legacy_urls']]);
		$this->assertSame([], $this->audit());

		// A node still enrolling dials the URLs its cluster.json carries.
		$this->rDb->query('DELETE FROM `cluster_nodes`');
		$this->node(5, 'enrolling');
		$this->assertTrue(ClusterEndpoint::recordMainChange($this->rMain, $rNew, $rSettings));
		$this->assertSame(2, $this->ver());
	}

	public function testExpiredUrlsArePruned(): void {
		$this->node(2, 'active');
		ClusterEndpoint::recordMainChange($this->rMain, ['server_ip' => '10.0.0.2', 'https_broadcast_port' => 8443] + $this->rMain, $this->live(['cluster_transport' => 'https_preferred']));
		$this->assertCount(2, ClusterEndpoint::legacyUrls($this->settings()));
		$this->assertFalse(ClusterEndpoint::prune($this->settings()), 'nothing expired yet');

		ClusterClock::fix(($this->rNow + ClusterEndpoint::GRACE + 1) * 1000);
		$this->assertSame([], ClusterEndpoint::legacyUrls($this->settings()));
		$this->assertSame([], ClusterEndpoint::legacyHttpsPorts($this->settings()));
		$this->assertTrue(ClusterEndpoint::prune($this->settings()));
		$this->assertSame('', (string) $this->settings()['cluster_legacy_urls']);
		$this->assertSame(3, $this->ver(), 'announced');
		$this->assertNull(ClusterNginxConfig::render($this->live(), [25461, 8443])[ClusterNginxConfig::OLD_PORT], 'nginx releases the old HTTPS port');
		$this->assertFalse(ClusterEndpoint::prune($this->settings()));
	}

	/** The kept list stays short: the latest changes win. */
	public function testAtMostMaxUrlsAreKept(): void {
		$this->node(2, 'active');
		$rRow = $this->rMain;
		for ($i = 2; $i <= ClusterEndpoint::MAX_URLS + 3; $i++) {
			ClusterClock::fix(($this->rNow + $i) * 1000);
			$rNew = ['server_ip' => '10.0.0.' . $i] + $rRow;
			$this->assertTrue(ClusterEndpoint::recordMainChange($rRow, $rNew, $this->live()));
			$rRow = $rNew;
		}
		$rKept = array_keys(ClusterEndpoint::legacyUrls($this->settings()));
		$this->assertCount(ClusterEndpoint::MAX_URLS, $rKept);
		$this->assertSame('http://10.0.0.' . (ClusterEndpoint::MAX_URLS + 2) . ':25461/cluster/v1/', $rKept[0], 'the latest first');
		$this->assertNotContains('http://10.0.0.1:25461/cluster/v1/', $rKept, 'the oldest went');
	}

	/**
	 * A kept URL is listed only while MAIN serves its port with its scheme:
	 * an http:// URL on a plain-HTTP port MAIN serves the API on, an https://
	 * URL on any other (the public server's HTTPS ports, or an old one nginx
	 * serves over TLS). A malformed entry is ignored.
	 */
	public function testAKeptUrlIsListedOnlyWithTheSchemeItsPortServes(): void {
		$rUntil = $this->rNow + 60;
		$this->store('cluster_legacy_ports', (string) json_encode([8080 => $rUntil]));
		$this->store('cluster_legacy_urls', (string) json_encode([
			'http://10.0.0.8:25461/cluster/v1/' => $rUntil,
			'http://10.0.0.7:8080/cluster/v1/' => $rUntil,
			'http://10.0.0.6:25463/cluster/v1/' => $rUntil,
			'http://10.0.0.5:9000/cluster/v1/' => $rUntil,
			'https://panel.example.com:25461/cluster/v1/' => $rUntil,
			'https://old.example.com:8443/cluster/v1/' => $rUntil,
			'http://10.0.0.4:25461/cluster/v1/' => $this->rNow - 1,
			'ftp://10.0.0.3:21/cluster/v1/' => $rUntil,
			'http://10.0.0.2:99999/cluster/v1/' => $rUntil,
			'http://10.0.0.2:25461/elsewhere/' => $rUntil,
		], JSON_UNESCAPED_SLASHES));
		$rSettings = $this->live(['cluster_transport' => 'https_preferred']);
		$this->assertSame([
			'http://10.0.0.8:25461/cluster/v1/', 'http://10.0.0.7:8080/cluster/v1/', 'http://10.0.0.6:25463/cluster/v1/', 'http://10.0.0.5:9000/cluster/v1/',
			'https://panel.example.com:25461/cluster/v1/', 'https://old.example.com:8443/cluster/v1/',
		], array_keys(ClusterEndpoint::legacyUrls($rSettings)), 'expired and malformed entries are dropped');
		$this->assertSame([8443 => $rUntil, 25461 => $rUntil], ClusterEndpoint::legacyHttpsPorts($rSettings));
		$this->assertSame([
			'https://panel.example.com:25463/cluster/v1/',
			'http://192.168.0.1:25461/cluster/v1/', 'http://10.0.0.1:25461/cluster/v1/',
			'http://192.168.0.1:8080/cluster/v1/', 'http://10.0.0.1:8080/cluster/v1/',
			'http://10.0.0.8:25461/cluster/v1/', 'http://10.0.0.7:8080/cluster/v1/',
			'https://old.example.com:8443/cluster/v1/',
		], ClusterPolicy::current($rSettings, $this->rMain)['main_urls']);

		// nginx: an old HTTPS port gets a TLS server unless MAIN serves the port already, or keeps it for plain HTTP.
		$rOld = (string) ClusterNginxConfig::render($rSettings, [25461, 25463], $this->rNow)[ClusterNginxConfig::OLD_PORT];
		$this->assertStringContainsString("listen 8080;\n", $rOld);
		$this->assertStringContainsString("listen 8443 ssl;\n", $rOld);
		$this->assertStringNotContainsString('listen 25461', $rOld);
		$this->assertSame(1, substr_count($rOld, 'include ssl.conf;'));
		$this->store('cluster_legacy_urls', (string) json_encode(['https://old.example.com:8080/cluster/v1/' => $rUntil], JSON_UNESCAPED_SLASHES));
		$rOld = (string) ClusterNginxConfig::render($this->live(), [25461, 25463], $this->rNow)[ClusterNginxConfig::OLD_PORT];
		$this->assertSame(1, substr_count($rOld, 'server {'), 'one server per port: the plain-HTTP one kept first');
		$this->assertStringNotContainsString('ssl', $rOld);
	}

	/** The call sites: the admin's save of MAIN's row, and cron:root_signals' rewrite. */
	public function testTheCallSites(): void {
		$rSrc = dirname(__DIR__, 2) . '/src/';
		$rServer = (string) file_get_contents($rSrc . 'Domain/Server/ServerService.php');
		$rUpdate = strpos($rServer, "\$rQuery = 'UPDATE `servers` SET '");
		$rAnnounce = strpos($rServer, 'self::announceMainEndpoints($rServer, $rArray);');
		$rApply = strpos($rServer, 'self::changePort($rInsertID, 0,');
		$this->assertNotFalse($rUpdate);
		$this->assertNotFalse($rAnnounce, 'process() announces');
		$this->assertNotFalse($rApply);
		$this->assertLessThan($rAnnounce, $rUpdate, 'after the row is stored');
		$this->assertLessThan($rApply, $rAnnounce, 'before the ports are applied, so nginx gets the kept port at once');
		$this->assertStringNotContainsString('ClusterEndpoint::recordChange(', $rServer);

		$rRoot = (string) file_get_contents($rSrc . 'Cli/CronJobs/RootSignalsCronJob.php');
		$this->assertSame(1, substr_count($rRoot, 'self::rewriteServerIP($db, SERVER_ID, $rServers[SERVER_ID], $rServerIP);'));
		$this->assertSame(1, substr_count($rRoot, 'UPDATE `servers` SET `server_ip` = ?'), 'the rewrite stores it in one place');
	}

	/** Migration 044 adds the kept URLs; database.sql has the column for fresh installs. */
	public function testTheSchema(): void {
		$rSrc = dirname(__DIR__, 2) . '/src/';
		$rUp = (string) file_get_contents($rSrc . 'migrations/database/up/044_add_cluster_legacy_urls.sql');
		$this->assertStringContainsString('ALTER TABLE `settings` ADD COLUMN IF NOT EXISTS `cluster_legacy_urls` mediumtext COLLATE utf8_unicode_ci;', $rUp);
		$this->assertStringContainsString('ALTER TABLE `settings` DROP COLUMN IF EXISTS `cluster_legacy_urls`;', (string) file_get_contents($rSrc . 'migrations/database/down/044_add_cluster_legacy_urls.sql'));
		$this->assertStringContainsString("  `cluster_legacy_ports` varchar(255) DEFAULT '',\n  `cluster_legacy_urls` mediumtext COLLATE utf8_unicode_ci,\n", (string) file_get_contents($rSrc . 'bin/install/database.sql'));
	}
}
