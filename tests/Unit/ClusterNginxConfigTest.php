<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\ClusterEndpoint;
use XcVm\Domain\Cluster\ClusterNginxConfig;
use XcVm\Domain\Cluster\ClusterPool;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * MAIN's rendered nginx config for the cluster API (plan §3, "Transport" and
 * "Endpoints and HTTPS"): the /cluster/v1/ location that the public server
 * includes, a plain-HTTP server of its own on `cluster_api_port`, and the old
 * ports kept for seven days after an endpoint change. apply() writes them
 * atomically, keeps them only once `nginx -t` passes, and reloads nginx.
 *
 * nginx itself is faked in most tests; testRealNginx runs the rendered files
 * through a real `nginx -t`, opt-in: XCVM_TEST_NGINX=/path/to/nginx.
 */
final class ClusterNginxConfigTest extends TestCase {
	private TestDb $rDb;

	private string $rBase;

	private int $rNow = 1800000000;

	/** @var list<list<string>> the nginx commands run, in order */
	private array $rRuns = [];

	/** What the faked `nginx -t` answers: [exit code, output]. */
	private array $rTest = [0, ''];

	protected function setUp(): void {
		$this->rBase = sys_get_temp_dir() . '/xcvm-nginx-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rBase . 'bin/nginx/conf/ports', 0777, true);
		file_put_contents($this->rBase . 'bin/nginx/conf/ports/http.conf', 'listen 25461;');
		file_put_contents($this->rBase . 'bin/nginx/conf/ports/https.conf', 'listen 25463 ssl;');
		copy(dirname(__DIR__, 2) . '/src/bin/nginx/conf/nginx.conf', $this->rBase . 'bin/nginx/conf/nginx.conf');
		$this->rDb = new TestDb();
		$this->rDb->exec('CREATE TABLE `cluster_audit` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `time` int, `server_id` int, `actor` varchar(64), `event` varchar(64), `detail` text, `ip` varchar(64))');
		DatabaseFactory::set($this->rDb);
		ClusterClock::fix($this->rNow * 1000);
		// apply() runs only as the user nginx runs as: here, whoever runs the tests.
		ClusterNginxConfig::useBase($this->rBase, self::user());
		ClusterNginxConfig::useRunner(function (array $rArgv): array {
			$this->rRuns[] = $rArgv;
			return in_array('-t', $rArgv, true) ? $this->rTest : [0, ''];
		});
	}

	protected function tearDown(): void {
		ClusterNginxConfig::useRunner(null);
		ClusterNginxConfig::useBase(null);
		ClusterClock::fix(null);
		DatabaseFactory::reset();
		exec('rm -rf ' . escapeshellarg($this->rBase));
	}

	private static function user(): string {
		$rUser = posix_getpwuid(posix_geteuid());
		return is_array($rUser) ? (string) $rUser['name'] : '';
	}

	private function conf(string $rFile): ?string {
		$rPath = $this->rBase . 'bin/nginx/conf/' . $rFile;
		return is_file($rPath) ? (string) file_get_contents($rPath) : null;
	}

	/** @return list<string> the nginx actions run since the last take: "test" or "reload" */
	private function takeRuns(): array {
		$rOut = [];
		foreach ($this->rRuns as $rArgv) {
			$this->assertSame($this->rBase . 'bin/nginx/sbin/nginx', $rArgv[0], "MAIN's own nginx");
			$this->assertContains($this->rBase . 'bin/nginx/conf/nginx.conf', $rArgv, 'the config under test');
			$rOut[] = in_array('-t', $rArgv, true) ? 'test' : (in_array('reload', $rArgv, true) ? 'reload' : implode(' ', $rArgv));
		}
		$this->rRuns = [];
		return $rOut;
	}

	private function audit(): array {
		$this->rDb->query('SELECT `event`, `detail` FROM `cluster_audit` ORDER BY `id`');
		return $this->rDb->get_rows();
	}

	public function testTheLocationCarriesThePlansTransportLimits(): void {
		$rConf = ClusterNginxConfig::locations();
		$this->assertMatchesRegularExpression('#^location \^~ /cluster/v1/ \{#m', $rConf);
		$this->assertStringContainsString('limit_req zone=cluster burst=400 nodelay;', $rConf, 'burst 400');
		$this->assertStringContainsString('limit_req_status 429;', $rConf, "nginx's own 429, which agents treat as a transport error");
		$this->assertStringContainsString('client_max_body_size 8m;', $rConf);
		$this->assertStringContainsString('gzip off;', $rConf);
		$this->assertStringContainsString('fastcgi_pass cluster_ctl;', $rConf);
		$this->assertStringContainsString('fastcgi_param SCRIPT_FILENAME /home/xc_vm/Public/cluster/index.php;', $rConf);
		$this->assertStringNotContainsString('fastcgi_pass php;', $rConf, 'never the panel pool directly');

		// The ingest lanes come from the one list the pools use.
		$this->assertSame(1, preg_match('#location ~ \^/cluster/v1/\(([a-z_|]+)\)\$ \{\s*fastcgi_pass cluster_ingest;\s*\}#', $rConf, $rMatch));
		$this->assertSame(ClusterPool::INGEST_OPS, explode('|', $rMatch[1]));
		$this->assertSame(substr_count($rConf, '{'), substr_count($rConf, '}'));
	}

	/** The shipped file is what the renderer writes, so an update and the next render agree. */
	public function testTheShippedLocationsFileIsTheRendering(): void {
		$this->assertSame(ClusterNginxConfig::locations(), (string) file_get_contents(dirname(__DIR__, 2) . '/src/bin/nginx/conf/' . ClusterNginxConfig::LOCATIONS));
	}

	public function testNginxConfIncludesTheRenderedFiles(): void {
		$rRoot = dirname(__DIR__, 2);
		$rConf = (string) file_get_contents($rRoot . '/src/bin/nginx/conf/nginx.conf');

		$this->assertMatchesRegularExpression('#^\s*limit_req_zone \$realip_remote_addr zone=cluster:\d+m rate=100r/s;$#m', $rConf, '100 r/s per TCP peer, whatever X-Forwarded-For says');
		// The public server: the one that includes MAIN's broadcast ports.
		$this->assertSame(1, preg_match('#\n    server \{\n        include ports/\*\.conf;(.*?)\n    \}\n#s', $rConf, $rServer));
		$this->assertStringContainsString("\n        include cluster_locations.conf;\n", $rServer[1], 'the public server{} serves the API on the broadcast ports');
		$this->assertStringNotContainsString('location ^~ /cluster/v1/', $rConf, 'the location is rendered, not fixed');
		$this->assertStringNotContainsString('cluster_legacy', $rConf, "ClusterEndpoint's old file is retired");
		// The API's own port and the old ports: servers at http{} level, after the public one.
		$this->assertMatchesRegularExpression('#\n    \}\n(?:\n    \#[^\n]*)*\n    include cluster\.d/\*\.conf;\n\}\s*$#', $rConf);

		$this->assertStringNotContainsString('cluster', (string) file_get_contents($rRoot . '/lb_configs/nginx.conf'), 'the LB nginx has no cluster route');
	}

	/** cluster_api_port = 0: the API is served on the broadcast ports only, as before. */
	public function testPortZeroServesTheApiOnTheBroadcastPortsOnly(): void {
		$rFiles = ClusterNginxConfig::render(['cluster_api_port' => 0], [25461, 25463]);
		$this->assertSame(ClusterNginxConfig::locations(), $rFiles[ClusterNginxConfig::LOCATIONS]);
		$this->assertNull($rFiles[ClusterNginxConfig::LISTEN], 'no listener of its own');
		$this->assertNull($rFiles[ClusterNginxConfig::OLD_PORT], 'no old port kept');
		$this->assertSame([ClusterNginxConfig::LOCATIONS, ClusterNginxConfig::LISTEN, ClusterNginxConfig::OLD_PORT], array_keys($rFiles));
	}

	public function testADedicatedPortGetsAPlainHttpServerForTheApiAlone(): void {
		$rListen = ClusterNginxConfig::render(['cluster_api_port' => 31200], [25461, 25463])[ClusterNginxConfig::LISTEN];
		$this->assertIsString($rListen);
		$this->assertMatchesRegularExpression('#^server \{\n    listen 31200;\n#m', $rListen, 'plain HTTP');
		$this->assertStringNotContainsString('ssl', $rListen);
		$this->assertStringContainsString("    include cluster_locations.conf;\n", $rListen, 'the same location, so the same pools and limits');
		$this->assertMatchesRegularExpression('#location / \{\s*return 404;\s*\}#', $rListen, 'nothing else on that port');
		$this->assertSame(1, substr_count($rListen, 'server {'));

		// A port MAIN already serves (refused by ClusterSettings) never gets a second server.
		$this->assertNull(ClusterNginxConfig::render(['cluster_api_port' => 25461], [25461, 25463])[ClusterNginxConfig::LISTEN]);
		$this->assertNull(ClusterNginxConfig::render(['cluster_api_port' => 70000], [25461])[ClusterNginxConfig::LISTEN]);
	}

	public function testOldPortsServeTheApiAloneUntilTheyExpire(): void {
		$rSettings = [
			'cluster_api_port' => 31200,
			'cluster_legacy_ports' => (string) json_encode([8080 => $this->rNow + 60, 25461 => $this->rNow + 60, 31200 => $this->rNow + 60, 31300 => $this->rNow + 60, 9000 => $this->rNow - 1]),
		];
		$rOld = ClusterNginxConfig::render($rSettings, [25461, 25463])[ClusterNginxConfig::OLD_PORT];
		$this->assertIsString($rOld);
		$this->assertStringContainsString('listen 8080;', $rOld);
		$this->assertStringContainsString('listen 31300;', $rOld);
		$this->assertStringNotContainsString('listen 25461;', $rOld, 'the public server still has it');
		$this->assertStringNotContainsString('listen 31200;', $rOld, 'the dedicated server has it');
		$this->assertStringNotContainsString('listen 9000;', $rOld, 'expired');
		$this->assertSame(2, substr_count($rOld, 'server {'));
		$this->assertSame(2, substr_count($rOld, 'include cluster_locations.conf;'), 'the old ports reach the cluster pools, not the panel pool');
		$this->assertSame(2, preg_match_all('#location / \{\s*return 404;\s*\}#', $rOld));
		$this->assertStringContainsString('# until ' . gmdate('Y-m-d H:i', $this->rNow + 60) . ' UTC', $rOld);

		$this->assertNull(ClusterNginxConfig::render(['cluster_legacy_ports' => (string) json_encode([25461 => $this->rNow + 60])], [25461])[ClusterNginxConfig::OLD_PORT]);
	}

	public function testTheServedPortsAreReadFromThePortsFiles(): void {
		file_put_contents($this->rBase . 'bin/nginx/conf/ports/http.conf', 'listen 80 reuseport; listen 8080;');
		file_put_contents($this->rBase . 'bin/nginx/conf/ports/https.conf', 'listen 443 ssl; listen [::]:8443 ssl;');
		$this->assertSame([80, 443, 8080, 8443], ClusterNginxConfig::servedPorts($this->rBase . 'bin/nginx/conf/'));
		unlink($this->rBase . 'bin/nginx/conf/ports/https.conf');
		$this->assertSame([80, 8080], ClusterNginxConfig::servedPorts($this->rBase . 'bin/nginx/conf/'));
	}

	public function testApplyWritesTestsAndReloadsOnlyWhatChanged(): void {
		$rResult = ClusterNginxConfig::apply(['cluster_api_port' => 31200, 'cluster_legacy_ports' => (string) json_encode([8080 => $this->rNow + 60])]);
		$this->assertTrue($rResult['ok']);
		$this->assertTrue($rResult['changed']);
		$this->assertTrue($rResult['reloaded']);
		$this->assertSame(['test', 'reload'], $this->takeRuns(), 'nginx -t before the reload');
		$this->assertSame(ClusterNginxConfig::locations(), $this->conf(ClusterNginxConfig::LOCATIONS));
		$this->assertStringContainsString('listen 31200;', (string) $this->conf(ClusterNginxConfig::LISTEN));
		$this->assertStringContainsString('listen 8080;', (string) $this->conf(ClusterNginxConfig::OLD_PORT));
		$this->assertSame([], glob($this->rBase . 'bin/nginx/conf/{,cluster.d/}*.tmp', GLOB_BRACE), 'no temporary file left');
		$this->assertSame('cluster.nginx', $this->audit()[0]['event']);

		// Nothing to change: no test, no reload.
		$rResult = ClusterNginxConfig::apply(['cluster_api_port' => 31200, 'cluster_legacy_ports' => (string) json_encode([8080 => $this->rNow + 60])]);
		$this->assertSame(['ok' => true, 'changed' => false, 'reloaded' => false, 'error' => ''], $rResult);
		$this->assertSame([], $this->takeRuns());

		// Back to the broadcast port, the old port expired: both files go.
		ClusterClock::fix(($this->rNow + 61) * 1000);
		$rResult = ClusterNginxConfig::apply(['cluster_api_port' => 0, 'cluster_legacy_ports' => (string) json_encode([8080 => $this->rNow + 60])], false);
		$this->assertTrue($rResult['ok'] && $rResult['changed']);
		$this->assertFalse($rResult['reloaded'], 'the caller reloads');
		$this->assertSame(['test'], $this->takeRuns());
		$this->assertNull($this->conf(ClusterNginxConfig::LISTEN));
		$this->assertNull($this->conf(ClusterNginxConfig::OLD_PORT));
		$this->assertSame(ClusterNginxConfig::locations(), $this->conf(ClusterNginxConfig::LOCATIONS));
	}

	public function testANginxRefusalRestoresThePreviousFiles(): void {
		ClusterNginxConfig::apply(['cluster_api_port' => 31200]);
		$this->takeRuns();
		file_put_contents($this->rBase . 'bin/nginx/conf/' . ClusterNginxConfig::LOCATIONS, "# an older release's location\n");
		file_put_contents($this->rBase . 'bin/nginx/conf/' . ClusterNginxConfig::RETIRED, "server { listen 8080; }\n");
		$rBefore = ['listen' => $this->conf(ClusterNginxConfig::LISTEN), 'locations' => $this->conf(ClusterNginxConfig::LOCATIONS), 'retired' => $this->conf(ClusterNginxConfig::RETIRED)];

		$this->rTest = [1, "nginx: [emerg] duplicate listen options for 0.0.0.0:31300 in cluster.d/listen.conf:4\nnginx: configuration file test failed\n"];
		$rResult = ClusterNginxConfig::apply(['cluster_api_port' => 31300, 'cluster_legacy_ports' => (string) json_encode([31200 => $this->rNow + 60])]);
		$this->assertFalse($rResult['ok']);
		$this->assertFalse($rResult['changed'], 'nothing changed in the end');
		$this->assertFalse($rResult['reloaded']);
		$this->assertStringContainsString('duplicate listen options for 0.0.0.0:31300', $rResult['error'], "nginx's own words");
		$this->assertSame(['test'], $this->takeRuns(), 'never reloaded');
		$this->assertSame($rBefore['listen'], $this->conf(ClusterNginxConfig::LISTEN), 'the old listener is back');
		$this->assertSame($rBefore['locations'], $this->conf(ClusterNginxConfig::LOCATIONS));
		$this->assertSame($rBefore['retired'], $this->conf(ClusterNginxConfig::RETIRED));
		$this->assertNull($this->conf(ClusterNginxConfig::OLD_PORT), 'a file that did not exist is gone again');
		$rAudit = $this->audit();
		$this->assertSame('cluster.nginx', end($rAudit)['event']);
		$this->assertStringContainsString('duplicate listen options', (string) end($rAudit)['detail']);
	}

	/** ClusterEndpoint's cluster_legacy.conf goes once nginx.conf reads cluster.d/ instead. */
	public function testTheRetiredFileGoesOnlyWithTheNginxConfThatNoLongerReadsIt(): void {
		$rRetired = $this->rBase . 'bin/nginx/conf/' . ClusterNginxConfig::RETIRED;
		file_put_contents($rRetired, "server { listen 8080; }\n");
		// An nginx.conf from before (an update whose nginx.conf was rolled back).
		file_put_contents($this->rBase . 'bin/nginx/conf/nginx.conf', "http {\n    include cluster_legacy*.conf;\n}\n");
		ClusterNginxConfig::apply(['cluster_api_port' => 0]);
		$this->assertFileExists($rRetired, 'still read by that nginx.conf');

		copy(dirname(__DIR__, 2) . '/src/bin/nginx/conf/nginx.conf', $this->rBase . 'bin/nginx/conf/nginx.conf');
		$this->assertTrue(ClusterNginxConfig::apply(['cluster_api_port' => 0])['ok']);
		$this->assertFileDoesNotExist($rRetired);
	}

	public function testApplyRunsOnlyAsTheNginxUser(): void {
		ClusterNginxConfig::useBase($this->rBase, 'not-' . self::user());
		$rResult = ClusterNginxConfig::apply(['cluster_api_port' => 31200]);
		$this->assertFalse($rResult['ok']);
		$this->assertNull($this->conf(ClusterNginxConfig::LISTEN));
		$this->assertNull($this->conf(ClusterNginxConfig::LOCATIONS));
		$this->assertSame([], $this->rRuns);
	}

	/**
	 * Who renders, and as whom: status at boot and after an update, the root
	 * set_port handler, cron:cluster when an old port expires, and a settings
	 * save that changes cluster_api_port; always as xc_vm.
	 */
	public function testTheCallSitesRenderAsXcVm(): void {
		$rSrc = dirname(__DIR__, 2) . '/src/';

		$rStatus = (string) file_get_contents($rSrc . 'Cli/Commands/StatusCommand.php');
		$this->assertMatchesRegularExpression('#if \(\$rServers\[SERVER_ID\]\[.is_main.\]\) \{[^}]*\$this->ensureClusterNginx\(\);#', $rStatus, 'MAIN only');
		$this->assertStringContainsString("'sudo -u xc_vm ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php cluster:nginx'", $rStatus, 'status runs as root, the render as xc_vm');
		$this->assertStringNotContainsString('ClusterNginxConfig::apply(', $rStatus);

		$rRoot = (string) file_get_contents($rSrc . 'Cli/CronJobs/RootSignalsCronJob.php');
		$this->assertStringNotContainsString('cluster_legacy.conf', $rRoot, 'root writes nothing under bin/nginx/conf for the cluster');
		$this->assertStringNotContainsString('ClusterNginxConfig::apply(', $rRoot);
		$this->assertSame(2, substr_count($rRoot, "'sudo -u xc_vm ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php cluster:nginx --no-reload'"), 'after the HTTP and the HTTPS ports; the handler reloads');

		$rCron = (string) file_get_contents($rSrc . 'Cli/CronJobs/ClusterCronJob.php');
		$this->assertMatchesRegularExpression('#ClusterEndpoint::prune\(SettingsManager::getAll\(\)\)\) \{\s*ClusterNginxConfig::apply\(\);#', $rCron, 'an expired port is released by the render, as xc_vm');

		$rSettings = (string) file_get_contents($rSrc . 'Domain/Server/SettingsService.php');
		$rStage = strpos($rSettings, 'self::stageClusterApiPort($rArray)');
		$rUpdate = strpos($rSettings, "\$rQuery = 'UPDATE `settings` SET '");
		$rRecord = strpos($rSettings, 'ClusterEndpoint::recordApiPortChange(...$rApiPort);');
		$this->assertNotFalse($rStage);
		$this->assertNotFalse($rRecord);
		$this->assertTrue($rStage < $rUpdate && $rUpdate < $rRecord, 'nginx takes the port before it is stored; the nodes hear of it after');

		$rMake = (string) file_get_contents(dirname($rSrc) . '/Makefile');
		$rGate = (string) file_get_contents(dirname($rSrc) . '/tools/ci/verify-lb-archive.sh');
		foreach (['bin/nginx/conf/cluster_locations.conf', 'Cli/Commands/ClusterNginxCommand.php'] as $rFile) {
			$this->assertMatchesRegularExpression('#^\t' . preg_quote($rFile, '#') . '(?: \\\\)?$#m', $rMake, $rFile . ' is stripped from the LB build');
			$this->assertStringContainsString('"' . $rFile . '"', $rGate, $rFile . ' is on the LEAK list');
		}
	}

	/**
	 * The rendered files against a real nginx: the public server with the
	 * location, a dedicated port and an old port pass `nginx -t`; a broken
	 * config elsewhere makes apply() put the previous files back.
	 */
	public function testRealNginx(): void {
		$rNginx = (string) getenv('XCVM_TEST_NGINX');
		if ($rNginx === '' || !is_executable($rNginx)) {
			$this->markTestSkipped('no nginx (set XCVM_TEST_NGINX)');
		}
		ClusterNginxConfig::useRunner(null);
		exec('cp -r ' . escapeshellarg(dirname(__DIR__, 2) . '/src/bin/nginx/conf/.') . ' ' . escapeshellarg($this->rBase . 'bin/nginx/conf/'));
		file_put_contents($this->rBase . 'bin/nginx/conf/ports/http.conf', 'listen 25461;');
		file_put_contents($this->rBase . 'bin/nginx/conf/ports/https.conf', 'listen 25463 ssl;');
		unlink($this->rBase . 'bin/nginx/conf/' . ClusterNginxConfig::LOCATIONS);
		// The user directive needs root and the xc_vm user; neither is needed to test the syntax.
		$rMain = $this->rBase . 'bin/nginx/conf/nginx.conf';
		file_put_contents($rMain, (string) preg_replace('/^user xc_vm;$/m', '', (string) file_get_contents($rMain)));
		mkdir($this->rBase . 'bin/nginx/sbin', 0777, true);
		mkdir($this->rBase . 'bin/nginx/logs', 0777, true);
		$rBin = $this->rBase . 'bin/nginx/sbin/nginx';
		file_put_contents($rBin, "#!/bin/sh\nexec " . escapeshellarg($rNginx) . ' -p ' . escapeshellarg($this->rBase . 'bin/nginx/') . " \"\$@\"\n");
		chmod($rBin, 0755);

		$rResult = ClusterNginxConfig::apply(['cluster_api_port' => 31200, 'cluster_legacy_ports' => (string) json_encode([8080 => $this->rNow + 60])], false);
		$this->assertSame(['ok' => true, 'changed' => true, 'reloaded' => false, 'error' => ''], $rResult);
		$this->assertFileExists($this->rBase . 'bin/nginx/conf/' . ClusterNginxConfig::LISTEN);

		file_put_contents($this->rBase . 'bin/nginx/conf/custom.conf', "this is not nginx;\n");
		$rResult = ClusterNginxConfig::apply(['cluster_api_port' => 31300], false);
		$this->assertFalse($rResult['ok']);
		$this->assertStringContainsString('custom.conf', $rResult['error']);
		$this->assertStringContainsString('listen 31200;', (string) $this->conf(ClusterNginxConfig::LISTEN), 'the previous listener is back');
		$this->assertStringContainsString('listen 8080;', (string) $this->conf(ClusterNginxConfig::OLD_PORT));
	}
}
