<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\ClusterReenrolCommand;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\EnrolmentService;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\FakeClusterCrypto;
use XcVm\Tests\Support\FakeSshFleet;

require_once dirname(__DIR__) . '/Support/FakeSshFleet.php';

/**
 * cluster:reenrol — re-enrols the fleet over SSH, node by node, through
 * server:enrol's path (ServerEnrolCommand::enrol → LbInstallFlow::provisionCluster).
 * The SSH layer is faked: each fake node answers the way xc_agent does, and
 * presents its own host key. What is checked: every selected node gets a new
 * identity and generation; a node that fails is reported and the run goes on;
 * the host key is never trusted on first use; a licence refusal stops the run;
 * a dry run contacts no node; the credential file is taken from bin/install/
 * only, owner-only, and deleted once read.
 */
final class ClusterReenrolCommandTest extends TestCase {
	private TestDb $rDb;

	private FakeClusterCrypto $rCrypto;

	private FakeSshFleet $rSsh;

	private string $rDir;

	private string $rAgent;

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
		$this->rDb->exec('CREATE TABLE `servers` (`id` INTEGER PRIMARY KEY, `status` int NOT NULL DEFAULT 1, `ssh_hostkey_sha1` char(40) DEFAULT NULL)');
		DatabaseFactory::set($this->rDb);
		SettingsManager::set(['cluster_api_enabled' => 1, 'lb_token_rotation_min' => 60, 'lb_new_node_mode' => 'legacy']);
		ClusterClock::fix(null);
		$this->rCrypto = new FakeClusterCrypto();
		$this->rSsh = new FakeSshFleet();
		$this->rDir = sys_get_temp_dir() . '/xcvm_reenrol_' . uniqid('', true) . '/';
		mkdir($this->rDir, 0750, true);
		$this->rAgent = (string) tempnam(sys_get_temp_dir(), 'agent');
		file_put_contents($this->rAgent, 'fake agent');
	}

	protected function tearDown(): void {
		DatabaseFactory::reset();
		SettingsManager::set([]);
		foreach (glob($this->rDir . '*') ?: [] as $rFile) {
			@unlink($rFile);
		}
		@rmdir($this->rDir);
		@unlink($this->rAgent);
	}

	/** MAIN #1 and the load balancers #7, #8, #9, #10 and #12, each with the host key stored at its install. */
	private function servers(): array {
		$rServers = [SERVER_ID => ['id' => SERVER_ID, 'is_main' => 1, 'server_type' => 0, 'server_name' => 'Main', 'server_ip' => '10.0.0.1', 'http_broadcast_port' => 25461, 'enable_https' => 0]];
		foreach ([7 => 'lb-a', 8 => 'lb-b', 9 => 'lb-c', 10 => 'lb-d', 12 => 'lb-e'] as $rID => $rName) {
			$rServers[$rID] = ['id' => $rID, 'is_main' => 0, 'server_type' => 0, 'server_name' => $rName, 'server_ip' => '10.0.0.' . $rID, 'ssh_hostkey_sha1' => sha1('host-' . $rID)];
			$this->rDb->query('INSERT INTO `servers` (`id`, `ssh_hostkey_sha1`) VALUES (?, ?)', $rID, sha1('host-' . $rID));
			$this->rSsh->rNodes['10.0.0.' . $rID] = ['hostkey' => sha1('host-' . $rID), 'password' => 'pw'];
		}
		return $rServers;
	}

	/** A node enrolled before the run, in $rState. */
	private function enrolled(int $rServerID, string $rState = 'active'): array {
		$rUuid = sprintf('%08x-0000-4000-8000-%012x', $rServerID, $rServerID);
		NodeRegistry::startEnrolment($rServerID, $rUuid, str_repeat("\x01", 32), str_repeat("\x02", 32), 1, $this->rCrypto);
		NodeRegistry::update($rServerID, ['state' => $rState]);
		return (array) NodeRegistry::byServer($rServerID);
	}

	/** @return array{0: int, 1: string} [exit code, output] */
	private function reenrol(array $rServers, ?array $rIDs, ?array $rCreds, bool $rDryRun = false, array $rStates = ['enrolling', 'active']): array {
		ob_start();
		try {
			$rCode = ClusterReenrolCommand::run($rServers, $rIDs, $rStates, $rCreds, $rDryRun, $this->rCrypto, $this->rSsh, fn(string $rArch) => $rArch === 'amd64' ? $this->rAgent : null, $this->rDir);
		} finally {
			$rOut = (string) ob_get_clean();
		}
		return [$rCode, $rOut];
	}

	private static function creds(array $rNodes = []): array {
		return ['default' => ['username' => 'root', 'password' => 'pw'], 'nodes' => $rNodes];
	}

	private function audit(string $rEvent): array {
		$this->rDb->query('SELECT `detail` FROM `cluster_audit` WHERE `event` = ? ORDER BY `id`', $rEvent);
		return array_map(static fn($rRow) => json_decode((string) $rRow['detail'], true), $this->rDb->get_rows());
	}

	public function testReenrolsEveryActiveNodeAndReportsEach(): void {
		$rServers = $this->servers();
		$rBefore = [7 => $this->enrolled(7), 8 => $this->enrolled(8), 12 => $this->enrolled(12, 'enrolling')];
		[$rCode, $rOut] = $this->reenrol($rServers, null, self::creds());
		$this->assertSame(0, $rCode, $rOut);
		foreach ($rBefore as $rID => $rOld) {
			$rNew = NodeRegistry::byServer($rID);
			$this->assertNotSame($rOld['node_uuid'], $rNew['node_uuid'], "#{$rID}: a new identity");
			$this->assertSame((int) $rOld['gen'] + 1, (int) $rNew['gen'], "#{$rID}: the old generation's tokens stop working");
			$this->assertSame('enrolling', $rNew['state']);
			$this->assertStringContainsString("#{$rID} {$rServers[$rID]['server_name']}: re-enrolled (node {$rNew['node_uuid']}, gen 2)", $rOut);
			$this->assertContains('connect 10.0.0.' . $rID . ':22', $this->rSsh->rLog);
			$this->assertContains('login 10.0.0.' . $rID . ' root', $this->rSsh->rLog);
		}
		$this->assertStringContainsString('3 re-enrolled, 0 failed, 0 not attempted, 0 skipped', $rOut);
		$this->assertSame([['ok' => [7, 8, 12], 'failed' => []]], $this->audit('cluster.reenrol'));
		$this->assertSame(3, substr_count(implode("\n", $this->rSsh->rLog), 'close '), 'each session is closed');
	}

	public function testAFailingNodeIsReportedAndTheRunGoesOn(): void {
		$rServers = $this->servers();
		$rOld = [7 => $this->enrolled(7), 8 => $this->enrolled(8), 9 => $this->enrolled(9), 10 => $this->enrolled(10)];
		$this->rSsh->rNodes['10.0.0.7']['hostkey'] = sha1('rebuilt');      // host key changed
		$this->rSsh->rNodes['10.0.0.9']['ready'] = false;                  // old release
		$this->rSsh->rNodes['10.0.0.10']['probe'] = false;                 // cannot reach MAIN
		[$rCode, $rOut] = $this->reenrol($rServers, null, self::creds());
		$this->assertSame(1, $rCode, $rOut);

		$this->assertStringContainsString('#7 lb-a: failed: SSH host key changed since this node was installed', $rOut);
		$this->assertSame([], $this->rSsh->commands('10.0.0.7'), 'a node whose host key does not match gets no command, and keeps its agent');
		$this->assertSame($rOld[7]['node_uuid'], NodeRegistry::byServer(7)['node_uuid']);
		$this->assertSame(sha1('host-7'), $this->storedHostKey(7), 'the stored key is kept');

		$this->assertStringContainsString('#9 lb-c: failed: The node does not run this panel release yet', $rOut);
		$this->assertSame($rOld[9]['node_uuid'], NodeRegistry::byServer(9)['node_uuid']);

		$this->assertMatchesRegularExpression("/#10 lb-d: failed: The node cannot reach MAIN's cluster API \\(http:\\/\\/10\\.0\\.0\\.1:25461\\/cluster\\/v1\\/\\): xc_agent probe: connection refused/", $rOut);
		$this->assertSame($rOld[10]['node_uuid'], NodeRegistry::byServer(10)['node_uuid'], 'no token was minted');

		$this->assertNotSame($rOld[8]['node_uuid'], NodeRegistry::byServer(8)['node_uuid'], 'the run went on past the failures');
		$this->assertStringContainsString('#8 lb-b: re-enrolled', $rOut);
		$this->assertStringContainsString('1 re-enrolled, 3 failed, 0 not attempted, 0 skipped', $rOut);
		$this->assertSame([['ok' => [8], 'failed' => [7, 9, 10]]], $this->audit('cluster.reenrol'));
	}

	public function testTheHostKeyIsNeverTrustedOnFirstUse(): void {
		$rServers = $this->servers();
		$this->enrolled(7);
		$this->enrolled(8);
		// Neither node has a stored key; only #8 gets one in the credential file.
		foreach ([7, 8] as $rID) {
			$rServers[$rID]['ssh_hostkey_sha1'] = null;
			$this->rDb->query('UPDATE `servers` SET `ssh_hostkey_sha1` = NULL WHERE `id` = ?', $rID);
		}
		$rKeygen = '256 SHA1:' . rtrim(base64_encode(sha1('host-8', true)), '=') . ' root@lb-b (ED25519)';
		$rCreds = ClusterReenrolCommand::parseCredentials((string) json_encode(['u' => 'root', 'p' => 'pw', 'nodes' => ['8' => ['hostkey' => $rKeygen]]]));
		$this->assertIsArray($rCreds);
		[$rCode, $rOut] = $this->reenrol($rServers, null, $rCreds);
		$this->assertSame(1, $rCode, $rOut);
		$this->assertStringContainsString('#7 lb-a: not attempted: no SSH host key to check', $rOut);
		$this->assertNotContains('connect 10.0.0.7:22', $this->rSsh->rLog, 'no connection at all to a node whose key is unknown');
		$this->assertStringContainsString('#8 lb-b: re-enrolled', $rOut);
		$this->assertSame(sha1('host-8'), $this->storedHostKey(8), 'the checked key is stored');

		// The credential file's key wins over the stored one, as --expect-hostkey does.
		$rCreds = self::creds([12 => ['hostkey' => sha1('other')]]);
		$this->enrolled(12);
		[$rCode, $rOut] = $this->reenrol($rServers, [12], $rCreds);
		$this->assertSame(1, $rCode);
		$this->assertStringContainsString('#12 lb-e: failed: SSH host key mismatch: expected ' . sha1('other') . ', got ' . sha1('host-12'), $rOut);
	}

	public function testStateSelectionAndNamedNodes(): void {
		$rServers = $this->servers();
		$this->enrolled(7);
		$rRevoked = $this->enrolled(8, 'revoked');
		$rQuarantined = $this->enrolled(9, 'quarantined');
		$this->enrolled(13); // its server was deleted
		[$rCode, $rOut] = $this->reenrol($rServers, null, self::creds());
		$this->assertSame(0, $rCode, 'skipped nodes are not failures');
		$this->assertStringContainsString('#8 lb-b: skipped: revoked', $rOut);
		$this->assertStringContainsString('#9 lb-c: skipped: quarantined', $rOut);
		$this->assertStringContainsString('#13: skipped: no load balancer with this id', $rOut);
		$this->assertSame($rRevoked['node_uuid'], NodeRegistry::byServer(8)['node_uuid'], 'a revoked node stays revoked');
		$this->assertSame('revoked', NodeRegistry::byServer(8)['state']);
		$this->assertSame($rQuarantined['node_uuid'], NodeRegistry::byServer(9)['node_uuid']);
		$this->assertStringContainsString('1 re-enrolled, 0 failed, 0 not attempted, 3 skipped', $rOut);

		[$rCode, $rOut] = $this->reenrol($rServers, null, self::creds(), false, ['quarantined']);
		$this->assertSame(0, $rCode, $rOut);
		$this->assertNotSame($rQuarantined['node_uuid'], NodeRegistry::byServer(9)['node_uuid'], '--state=quarantined takes it');
		$this->assertStringContainsString('#7 lb-a: skipped: enrolling', $rOut, '#7 was re-enrolled above and is enrolling now');

		// A node named by id is taken whatever its state; a server that is not enrolled is not.
		[$rCode, $rOut] = $this->reenrol($rServers, [8, 10, 1, 99], self::creds());
		$this->assertSame(1, $rCode);
		$this->assertNotSame($rRevoked['node_uuid'], NodeRegistry::byServer(8)['node_uuid']);
		$this->assertSame('enrolling', NodeRegistry::byServer(8)['state']);
		$this->assertStringContainsString('#10 lb-d: not attempted: not enrolled (server:enrol enrols it)', $rOut);
		$this->assertStringContainsString('#1 Main: not attempted: not a load balancer', $rOut);
		$this->assertStringContainsString('#99: not attempted: not a load balancer', $rOut);
		$this->assertNotContains('connect 10.0.0.10:22', $this->rSsh->rLog);
	}

	public function testDryRunContactsNoNode(): void {
		$rServers = $this->servers();
		$rOld = [7 => $this->enrolled(7), 8 => $this->enrolled(8), 9 => $this->enrolled(9)];
		$rServers[9]['ssh_hostkey_sha1'] = null;
		file_put_contents($this->rDir . '7.json', json_encode(['root_username' => 'root', 'ssh_port' => 2200]));
		$rCreds = self::creds([8 => ['username' => 'admin', 'port' => 2222, 'hostkey' => sha1('host-8')]]);
		$rCreds['default']['port'] = 2022;
		[$rCode, $rOut] = $this->reenrol($rServers, null, $rCreds, true);
		$this->assertSame(1, $rCode, 'a node that could not be attempted fails the dry run too');
		$this->assertSame([], $this->rSsh->rLog, 'no node is contacted');
		foreach ($rOld as $rID => $rNode) {
			$this->assertSame($rNode['node_uuid'], NodeRegistry::byServer($rID)['node_uuid']);
		}
		$this->assertStringContainsString('#7 lb-a: would re-enrol as root@10.0.0.7:2200, host key ' . sha1('host-7') . ' (stored at its install)', $rOut, 'the port its install used');
		$this->assertStringContainsString('#8 lb-b: would re-enrol as admin@10.0.0.8:2222, host key ' . sha1('host-8') . ' (credential file)', $rOut);
		$this->assertStringContainsString('#9 lb-c: not attempted: no SSH host key to check', $rOut);
		$this->assertStringContainsString('2 would be re-enrolled, 1 not attempted, 0 skipped', $rOut);
		$this->assertSame([], $this->audit('cluster.reenrol'));

		// The file's top-level port is every other node's default; 22 without one.
		[, $rOut] = $this->reenrol($rServers, [9], self::creds([9 => ['hostkey' => sha1('host-9')]]), true);
		$this->assertStringContainsString('root@10.0.0.9:22,', $rOut);
		[, $rOut] = $this->reenrol($rServers, [9], ['default' => ['username' => 'root', 'password' => 'pw', 'port' => 2022], 'nodes' => [9 => ['hostkey' => sha1('host-9')]]], true);
		$this->assertStringContainsString('root@10.0.0.9:2022,', $rOut);

		// Without a credential file a dry run still shows the host keys.
		[$rCode, $rOut] = $this->reenrol($rServers, [7, 8], null, true);
		$this->assertSame(0, $rCode, $rOut);
		$this->assertStringContainsString('#8 lb-b: would re-enrol (no credential file given)', $rOut);
	}

	public function testNodesWithoutCredentialsAreNotAttempted(): void {
		$rServers = $this->servers();
		$this->enrolled(7);
		$this->enrolled(8);
		$rCreds = ['default' => [], 'nodes' => [8 => ['username' => 'root', 'password' => 'pw']]];
		[$rCode, $rOut] = $this->reenrol($rServers, null, $rCreds);
		$this->assertSame(1, $rCode);
		$this->assertStringContainsString('#7 lb-a: not attempted: no SSH credentials for it in the credential file', $rOut);
		$this->assertStringContainsString('#8 lb-b: re-enrolled', $rOut);
		$this->assertNotContains('connect 10.0.0.7:22', $this->rSsh->rLog);
	}

	public function testALicenceRefusalStopsTheRun(): void {
		$rServers = $this->servers();
		$rOld = [7 => $this->enrolled(7), 8 => $this->enrolled(8)];

		// Unlicensed from the start: nothing is touched.
		$this->rCrypto->rLicensed = false;
		[$rCode, $rOut] = $this->reenrol($rServers, null, self::creds());
		$this->assertSame(1, $rCode);
		$this->assertStringContainsString('CLUSTER_LICENCE_REQUIRED', $rOut);
		$this->assertSame([], $this->rSsh->rLog);

		// Refused on the first node: the others are left alone, since each
		// would have its agent stopped only to be refused the same way.
		$this->rCrypto->rLicensed = true;
		$this->rCrypto->rRefuseIssue = 'LICENCE';
		[$rCode, $rOut] = $this->reenrol($rServers, null, self::creds());
		$this->assertSame(1, $rCode);
		$this->assertStringContainsString('#7 lb-a: failed: CLUSTER_LICENCE_REQUIRED', $rOut);
		$this->assertStringContainsString('#8 lb-b: not attempted: the run stopped at the licence refusal', $rOut);
		$this->assertNotContains('connect 10.0.0.8:22', $this->rSsh->rLog);
		$this->assertSame($rOld[8]['node_uuid'], NodeRegistry::byServer(8)['node_uuid']);
		$this->assertSame([['ok' => [], 'failed' => [7]]], $this->audit('cluster.reenrol'));
	}

	public function testNothingToReenrol(): void {
		[$rCode, $rOut] = $this->reenrol($this->servers(), null, self::creds());
		$this->assertSame(0, $rCode);
		$this->assertStringContainsString('No enrolled node to re-enrol', $rOut);
	}

	public function testCredentialFileRules(): void {
		$rPath = $this->rDir . 'fleet.cred';
		$rJson = (string) json_encode(['u' => 'root', 'p' => 'pw', 'port' => 22, 'nodes' => ['7' => ['p' => 'other', 'port' => 2222, 'hostkey' => implode(':', str_split(strtoupper(sha1('host-7')), 2))], '8' => ['u' => 'admin']]]);
		$rWrite = function (string $rContent, int $rMode = 0600) use ($rPath): void {
			file_put_contents($rPath, $rContent);
			chmod($rPath, $rMode);
		};

		$rWrite($rJson);
		$rExpected = [
			'default' => ['username' => 'root', 'password' => 'pw', 'port' => 22],
			'nodes' => [7 => ['password' => 'other', 'port' => 2222, 'hostkey' => sha1('host-7')], 8 => ['username' => 'admin']],
		];
		$this->assertSame($rExpected, ClusterReenrolCommand::readCredentials($rPath, true, $this->rDir));
		$this->assertFileExists($rPath, 'a dry run keeps it for the real run');
		$this->assertSame($rExpected, ClusterReenrolCommand::readCredentials($rPath, false, $this->rDir));
		$this->assertFileDoesNotExist($rPath, 'deleted once read');

		$rWrite($rJson, 0640);
		$this->assertIsString(ClusterReenrolCommand::readCredentials($rPath, false, $this->rDir), 'readable by others: refused');
		$this->assertFileExists($rPath);
		@unlink($rPath);

		$rOutside = sys_get_temp_dir() . '/xcvm_' . uniqid('', true) . '.cred';
		file_put_contents($rOutside, $rJson);
		chmod($rOutside, 0600);
		$this->assertIsString(ClusterReenrolCommand::readCredentials($rOutside, false, $this->rDir), 'outside bin/install: refused');
		$this->assertFileExists($rOutside, 'and not deleted either');
		@unlink($rOutside);
		file_put_contents($this->rDir . 'fleet.json', $rJson);
		chmod($this->rDir . 'fleet.json', 0600);
		$this->assertIsString(ClusterReenrolCommand::readCredentials($this->rDir . 'fleet.json', false, $this->rDir), 'not a .cred');

		// A single server:install credential ({"u", "p"}) is every node's default.
		$this->assertSame(['default' => ['username' => 'root', 'password' => 'pw'], 'nodes' => []], ClusterReenrolCommand::parseCredentials('{"u":"root","p":"pw"}'));
		foreach ([
			'not json',
			'[]',
			'{"u":"root","pasword":"pw"}',
			'{"u":"root","p":"pw","port":0}',
			'{"u":"root","p":"pw","port":"22"}',
			'{"u":"root","p":"pw","hostkey":"' . sha1('x') . '"}',
			'{"u":"root","p":"pw","nodes":{"7":{"hostkey":"SHA256:abc"}}}',
			'{"u":"root","p":"pw","nodes":{"lb-a":{"p":"x"}}}',
			'{"u":"root","p":"pw","nodes":{"7":{"p":1}}}',
			'{"u":"root","p":"pw","nodes":[{"p":"x"}]}',
		] as $rBad) {
			$this->assertIsString(ClusterReenrolCommand::parseCredentials($rBad), $rBad);
		}
	}

	public function testArguments(): void {
		$this->assertSame(['ids' => null, 'states' => ['enrolling', 'active'], 'cred-file' => '/x/f.cred', 'dry-run' => false], ClusterReenrolCommand::parseArgs(['--all', '--cred-file=/x/f.cred']));
		$this->assertSame(['ids' => null, 'states' => ['quarantined', 'active'], 'cred-file' => null, 'dry-run' => true], ClusterReenrolCommand::parseArgs(['--all', '--state=quarantined,active', '--dry-run']));
		$this->assertSame(['ids' => [7, 9], 'states' => [], 'cred-file' => '/x/f.cred', 'dry-run' => false], ClusterReenrolCommand::parseArgs(['7', '9', '7', '--cred-file=/x/f.cred']));
		foreach ([
			[],
			['--cred-file=/x/f.cred'],
			['--all', '7', '--cred-file=/x/f.cred'],
			['--all'],
			['--all', '--state=bogus', '--dry-run'],
			['7', '--state=active', '--cred-file=/x/f.cred'],
			['seven', '--cred-file=/x/f.cred'],
			['0', '--cred-file=/x/f.cred'],
			['--all', '--force', '--cred-file=/x/f.cred'],
			['--all', '--cred-file=/x/f.cred', '--expect-hostkey=' . sha1('x')],
		] as $rArgs) {
			$this->assertIsString(ClusterReenrolCommand::parseArgs($rArgs), implode(' ', $rArgs));
		}
	}

	private function storedHostKey(int $rServerID): ?string {
		$this->rDb->query('SELECT `ssh_hostkey_sha1` FROM `servers` WHERE `id` = ?', $rServerID);
		return $this->rDb->get_row()['ssh_hostkey_sha1'];
	}
}
