<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\LbInstallFlow;
use XcVm\Core\Cluster\Crypto\PanelSig;
use XcVm\Core\Cluster\Crypto\Seal;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\EnrolmentService;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\FakeClusterCrypto;

/**
 * LbInstallFlow::provisionCluster(): SSH enrolment of a new LB. A fake LB
 * plays the node's side of the SSH session the way xc_agent does (keygen,
 * probe, install); opt-in, the real xc_agent binary runs the same commands
 * (XCVM_AGENT_BIN=/path/to/xc_agent).
 */
final class LbProvisionClusterTest extends TestCase {
	private const SID = 9;

	private TestDb $rDb;

	private FakeClusterCrypto $rCrypto;

	/** @var list<string> */
	private array $rCommands = [];

	/** @var array<string, string> remote path => content */
	private array $rSent = [];

	private ?string $rEphSk = null;

	private bool $rProbeOk = true;

	public static function setUpBeforeClass(): void {
		foreach (['SERVER_ID' => 1, 'TMP_PATH' => sys_get_temp_dir() . '/xcvm-test-tmp/', 'CONFIG_PATH' => sys_get_temp_dir() . '/xcvm-test-config/'] as $rName => $rValue) {
			if (!defined($rName)) {
				define($rName, $rValue);
			}
		}
		@mkdir(TMP_PATH, 0700, true);
	}

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach (['029_create_cluster_nodes', '032_create_cluster_audit'] as $rName) {
			$rSql = (string) file_get_contents(dirname(__DIR__, 2) . '/src/migrations/database/up/' . $rName . '.sql');
			if (!getenv('XCVM_TEST_DB_DSN')) {
				$rSql = (string) preg_replace('/^--.*$/m', '', $rSql);
				$rSql = (string) preg_replace('/`id` bigint\(20\) unsigned NOT NULL AUTO_INCREMENT/', '`id` INTEGER PRIMARY KEY AUTOINCREMENT', $rSql);
				$rSql = (string) preg_replace('/,\s*PRIMARY KEY \(`id`\)/', '', $rSql);
				$rSql = (string) preg_replace('/,\s*(UNIQUE )?KEY `\w+` \([^)]*\)/', '', $rSql);
				$rSql = (string) preg_replace('/ unsigned| COLLATE \w+/', '', $rSql);
				$rSql = (string) preg_replace('/\) ENGINE=[^;]*;/', ');', $rSql);
			}
			$this->rDb->exec($rSql);
		}
		$this->rDb->exec('ALTER TABLE `cluster_node_epochs` ADD COLUMN `agent_eph_pub` binary(32) DEFAULT NULL');
		$this->rDb->exec('CREATE TABLE `servers` (`id` INTEGER PRIMARY KEY, `status` int NOT NULL DEFAULT 0)');
		$this->rDb->exec('INSERT INTO `servers` (`id`, `status`) VALUES (9, 0)');
		DatabaseFactory::set($this->rDb);
		SettingsManager::set(['cluster_api_enabled' => 1, 'lb_token_rotation_min' => 60, 'lb_new_node_mode' => 'legacy']);
		ClusterClock::fix(null);
		$this->rCrypto = new FakeClusterCrypto();
	}

	protected function tearDown(): void {
		DatabaseFactory::reset();
		SettingsManager::set([]);
	}

	private function servers(): array {
		return [SERVER_ID => ['server_ip' => '10.0.0.1', 'http_broadcast_port' => 25461, 'enable_https' => 0], self::SID => []];
	}

	/** The node's side of the SSH session, as xc_agent answers it. */
	private function fakeSsh(): array {
		$rRun = function ($rConn, string $rCmd): array {
			$this->rCommands[] = $rCmd;
			if ($rCmd === 'uname -m') {
				return ['output' => "x86_64\n", 'error' => ''];
			}
			if (preg_match('/ keygen .* -uuid ([0-9a-f-]{36})$/', $rCmd, $rM)) {
				$rPair = sodium_crypto_sign_keypair();
				$rSign = sodium_crypto_sign_publickey($rPair);
				$rBox = sodium_crypto_scalarmult_base(random_bytes(32));
				$this->rEphSk = random_bytes(32);
				return ['output' => json_encode([
					'node_uuid' => $rM[1], 'sign_pub' => bin2hex($rSign), 'box_pub' => bin2hex($rBox),
					'eph_pub' => bin2hex(sodium_crypto_scalarmult_base($this->rEphSk)), 'sas' => EnrolmentService::sas($rM[1], $rSign, $rBox),
				]) . "\n", 'error' => ''
				];
			}
			if (str_contains($rCmd, ' probe ')) {
				return ['output' => $this->rProbeOk ? "OK http://10.0.0.1:25461/cluster/v1/\n" : "xc_agent probe: connection refused\n", 'error' => ''];
			}
			if (str_contains($rCmd, ' install ')) {
				return ['output' => "OK\n", 'error' => ''];
			}
			return ['output' => '', 'error' => ''];
		};
		$rSend = function ($rConn, string $rLocal, string $rRemote): bool {
			$this->rSent[$rRemote] = (string) file_get_contents($rLocal);
			return true;
		};
		return [$rRun, $rSend];
	}

	private function serverStatus(): int {
		$this->rDb->query('SELECT `status` FROM `servers` WHERE `id` = 9');
		return (int) $this->rDb->get_row()['status'];
	}

	private function agentBinary(): callable {
		$rFile = tempnam(sys_get_temp_dir(), 'agent');
		file_put_contents($rFile, 'fake agent');
		return static fn(string $rArch) => $rArch === 'amd64' ? $rFile : null;
	}

	public function testEnrolsTheNodeOverSsh(): void {
		[$rRun, $rSend] = $this->fakeSsh();
		ob_start();
		$rOk = LbInstallFlow::provisionCluster(null, $rRun, $rSend, $this->servers(), self::SID, $this->rDb, $this->rCrypto, $this->agentBinary());
		$rLog = (string) ob_get_clean();
		$this->assertTrue($rOk, $rLog);
		$this->assertArrayHasKey(LbInstallFlow::AGENT_BIN, $this->rSent, 'the agent is pushed from MAIN');

		$rNode = NodeRegistry::byServer(self::SID);
		$this->assertSame('enrolling', $rNode['state']);
		$this->assertStringContainsString($rNode['node_uuid'], $rLog);

		$rInstall = json_decode(end($this->rSent), true);
		$this->assertSame(self::SID, $rInstall['server_id']);
		$this->assertSame(['http://10.0.0.1:25461/cluster/v1/'], $rInstall['main_urls']);
		$this->assertSame($this->rCrypto->info()['panel_sign_pub'], base64_decode($rInstall['panel_sign_pub']));
		// The token opens only with the node's per-epoch key and carries the panel's signature.
		$rBody = Seal::open((string) $this->rEphSk, 'token', $rNode['node_uuid'], (string) base64_decode($rInstall['token_sealed']));
		$this->assertNotNull($rBody);
		$rLen = unpack('N', substr($rBody, 0, 4))[1];
		$this->assertTrue(PanelSig::verify($this->rCrypto->info()['panel_sign_pub'], 'tok', substr($rBody, 4, $rLen), substr($rBody, 4 + $rLen)));

		// The probe ran before the token existed, and the agent was started last.
		$rProbeAt = array_key_first(array_filter($this->rCommands, static fn($c) => str_contains($c, ' probe ')));
		$rInstallAt = array_key_first(array_filter($this->rCommands, static fn($c) => str_contains($c, ' install ')));
		$this->assertLessThan($rInstallAt, $rProbeAt);
		$this->assertStringContainsString('bin/xc_agent/run.sh', end($this->rCommands));
		$this->assertStringContainsString(bin2hex($this->rCrypto->info()['panel_sign_pub']), $this->rCommands[$rProbeAt]);
	}

	public function testUnreachableMainStopsBeforeAnyToken(): void {
		$this->rProbeOk = false;
		[$rRun, $rSend] = $this->fakeSsh();
		ob_start();
		$rOk = LbInstallFlow::provisionCluster(null, $rRun, $rSend, $this->servers(), self::SID, $this->rDb, $this->rCrypto, $this->agentBinary());
		$rLog = (string) ob_get_clean();
		$this->assertFalse($rOk);
		$this->assertStringContainsString("cannot reach MAIN's cluster API", $rLog);
		$this->assertNull(NodeRegistry::byServer(self::SID), 'no node row, so no token');
		$this->assertSame(4, $this->serverStatus());
	}

	public function testLicenceRefusalStopsTheInstall(): void {
		$this->rCrypto->rRefuseIssue = 'LICENCE';
		[$rRun, $rSend] = $this->fakeSsh();
		ob_start();
		$rOk = LbInstallFlow::provisionCluster(null, $rRun, $rSend, $this->servers(), self::SID, $this->rDb, $this->rCrypto, $this->agentBinary());
		$rLog = (string) ob_get_clean();
		$this->assertFalse($rOk);
		$this->assertStringContainsString('CLUSTER_LICENCE_REQUIRED', $rLog);
		$this->assertSame(4, $this->serverStatus());
	}

	public function testEnrolOnALiveNodeNeverMarksItFailed(): void {
		// server:enrol passes $rMarkFailed = false: a failed enrolment leaves a
		// serving LB as it was.
		$this->rDb->query('UPDATE `servers` SET `status` = 1 WHERE `id` = 9');
		$this->rProbeOk = false;
		[$rRun, $rSend] = $this->fakeSsh();
		ob_start();
		$rOk = LbInstallFlow::provisionCluster(null, $rRun, $rSend, $this->servers(), self::SID, $this->rDb, $this->rCrypto, $this->agentBinary(), false);
		ob_end_clean();
		$this->assertFalse($rOk);
		$this->assertSame(1, $this->serverStatus());
	}

	public function testReEnrolmentStopsTheRunningAgentFirstAndRaisesTheGeneration(): void {
		[$rRun, $rSend] = $this->fakeSsh();
		ob_start();
		$this->assertTrue(LbInstallFlow::provisionCluster(null, $rRun, $rSend, $this->servers(), self::SID, $this->rDb, $this->rCrypto, $this->agentBinary(), false));
		$rFirst = NodeRegistry::byServer(self::SID);
		$this->rCommands = [];
		$this->assertTrue(LbInstallFlow::provisionCluster(null, $rRun, $rSend, $this->servers(), self::SID, $this->rDb, $this->rCrypto, $this->agentBinary(), false));
		ob_end_clean();
		$rSecond = NodeRegistry::byServer(self::SID);
		$this->assertNotSame($rFirst['node_uuid'], $rSecond['node_uuid'], 'a new identity');
		$this->assertSame((int) $rFirst['gen'] + 1, (int) $rSecond['gen']);
		$rStopAt = array_key_first(array_filter($this->rCommands, static fn($c) => str_contains($c, 'pkill -u xc_vm -x xc_agent')));
		$rKeygenAt = array_key_first(array_filter($this->rCommands, static fn($c) => str_contains($c, ' keygen ')));
		$this->assertNotNull($rStopAt);
		$this->assertLessThan($rKeygenAt, $rStopAt, 'the old agent is stopped before its state is replaced');
		$this->assertStringContainsString('run.sh', $this->rCommands[$rStopAt], 'the supervisor too, first');
	}

	public function testLegacyWhenDisabledOrWithoutAnAgent(): void {
		[$rRun, $rSend] = $this->fakeSsh();
		SettingsManager::set(['cluster_api_enabled' => 0]);
		$this->assertTrue(LbInstallFlow::provisionCluster(null, $rRun, $rSend, $this->servers(), self::SID, $this->rDb, $this->rCrypto, $this->agentBinary()));
		$this->assertSame([], $this->rCommands, 'API off: the node is not touched');

		SettingsManager::set(['cluster_api_enabled' => 1]);
		ob_start();
		$rOk = LbInstallFlow::provisionCluster(null, $rRun, $rSend, $this->servers(), self::SID, $this->rDb, $this->rCrypto, static fn() => null);
		$rLog = (string) ob_get_clean();
		$this->assertTrue($rOk, 'no agent binary: the install goes on, legacy');
		$this->assertStringContainsString('stays legacy', $rLog);
		$this->assertNull(NodeRegistry::byServer(self::SID));
		$this->assertSame(0, $this->serverStatus());
	}

	public function testKeysThatDoNotMatchTheirSasAreRefused(): void {
		[$rRun, $rSend] = $this->fakeSsh();
		$rTampered = function ($rConn, string $rCmd) use ($rRun): array {
			$rOut = $rRun($rConn, $rCmd);
			if (str_contains($rCmd, ' keygen ')) {
				$rJ = json_decode($rOut['output'], true);
				$rJ['sas'] = 'AAAA-AAAA-AAAA-AAAA-AAAA-AAAA';
				$rOut['output'] = json_encode($rJ);
			}
			return $rOut;
		};
		ob_start();
		$rOk = LbInstallFlow::provisionCluster(null, $rTampered, $rSend, $this->servers(), self::SID, $this->rDb, $this->rCrypto, $this->agentBinary());
		ob_end_clean();
		$this->assertFalse($rOk);
		$this->assertNull(NodeRegistry::byServer(self::SID));
	}

	/**
	 * The same flow with the real xc_agent on a "node" that is a temp dir on
	 * this machine: the command strings, the JSON both ways and the token must
	 * work with the Go binary. MAIN's health is served by `php -S`.
	 *
	 *   XCVM_AGENT_BIN=/path/to/xc_agent php tests/phpunit.phar -c tests/phpunit.xml.dist --filter LbProvisionClusterTest
	 */
	public function testWithTheRealAgent(): void {
		$rBin = (string) getenv('XCVM_AGENT_BIN');
		if ($rBin === '' || !is_executable($rBin)) {
			$this->markTestSkipped('XCVM_AGENT_BIN not set');
		}
		$rNode = sys_get_temp_dir() . '/xcvm-node-' . bin2hex(random_bytes(4));
		mkdir($rNode, 0700, true);
		$rMap = [MAIN_HOME => $rNode . '/home/', CONFIG_PATH => $rNode . '/config/'];
		$rLocal = static fn(string $rS) => strtr($rS, $rMap);

		// MAIN's health endpoint, signed by the same fake panel key.
		$rSock = stream_socket_server('tcp://127.0.0.1:0');
		$rPort = (int) substr(strrchr(stream_socket_get_name($rSock, false), ':'), 1);
		fclose($rSock);
		$rRouter = $rNode . '/router.php';
		file_put_contents($rRouter, '<?php require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ';'
			. ' $r = \XcVm\Domain\Cluster\ClusterApi::handle(new \XcVm\Tests\Support\FakeClusterCrypto(), ["method" => "GET", "path" => parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH), "headers" => []], [], []);'
			. ' http_response_code($r["status"]); foreach ($r["headers"] as $k => $v) { header("$k: $v"); } echo $r["body"]; return true;');
		$rServer = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $rPort, $rRouter], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $rPipes);
		for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $rPort); $i++) {
			usleep(100000);
		}
		try {
			$rRun = function ($rConn, string $rCmd) use ($rLocal, $rBin): array {
				if ($rCmd === 'uname -m') {
					return ['output' => php_uname('m'), 'error' => ''];
				}
				// The node is this machine: drop sudo, map the node's paths into the temp dir.
				$rCmd = (string) preg_replace('/sudo( -u xc_vm)? /', '', $rLocal($rCmd));
				if (str_contains($rCmd, 'chown') || str_contains($rCmd, 'run.sh')) {
					$rCmd = (string) preg_replace('/(chown|chmod) [^&;]*(&&|;|$)/', 'true $2', $rCmd);
					if (str_contains($rCmd, 'run.sh')) {
						return ['output' => '', 'error' => ''];
					}
				}
				exec($rCmd . ' 2>&1', $rOut);
				return ['output' => implode("\n", $rOut) . "\n", 'error' => ''];
			};
			$rSend = static function ($rConn, string $rFrom, string $rTo) use ($rLocal, $rBin): bool {
				$rTo = $rLocal($rTo);
				@mkdir(dirname($rTo), 0700, true);
				return copy($rTo === $rLocal(LbInstallFlow::AGENT_BIN) ? $rBin : $rFrom, $rTo) && chmod($rTo, 0700);
			};
			$rServers = [SERVER_ID => ['server_ip' => '127.0.0.1', 'http_broadcast_port' => $rPort, 'enable_https' => 0], self::SID => []];
			ob_start();
			$rOk = LbInstallFlow::provisionCluster(null, $rRun, $rSend, $rServers, self::SID, $this->rDb, $this->rCrypto, static fn() => $rBin);
			$rLog = (string) ob_get_clean();
			$this->assertTrue($rOk, $rLog);
			exec(escapeshellarg($rBin) . ' health -state ' . escapeshellarg($rLocal(LbInstallFlow::AGENT_STATE)) . ' 2>&1', $rHealth, $rCode);
			$this->assertSame(0, $rCode, implode("\n", $rHealth));
			$this->assertSame('enrolling', NodeRegistry::byServer(self::SID)['state']);
		} finally {
			proc_terminate($rServer);
			exec('rm -rf ' . escapeshellarg($rNode));
		}
	}
}
