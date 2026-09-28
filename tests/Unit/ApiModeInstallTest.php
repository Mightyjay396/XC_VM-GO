<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\LbInstallFlow;
use XcVm\Core\Cluster\ClusterSettings;
use XcVm\Core\Cluster\CredentialFreeConfig;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ClusterAdmin;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\EnrolmentService;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\FakeClusterCrypto;

/**
 * `lb_new_node_mode = api` (plan, section 12; ADR 0004, Phase 9): a new load
 * balancer installed in mode 2 from its first boot, with no DB grant and a
 * config.enc that carries none of MAIN's credentials. The extension packs it
 * (`config_pack(…, ['db_credentials' => false])`, found by `install_config`);
 * an older one would pack MAIN's credentials, so API mode is refused there.
 * `api_mode_allowed` stays false: the switch itself is the operator's cutover.
 */
final class ApiModeInstallTest extends TestCase {
	private const SID = 9;

	private TestDb $rDb;

	/** @var list<string> */
	private array $rRan = [];

	/** @var array<string, string> */
	private array $rSent = [];

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
		$rSql = (string) file_get_contents(dirname(__DIR__, 2) . '/src/migrations/database/up/029_create_cluster_nodes.sql');
		foreach (array_filter(array_map('trim', explode(';', (string) preg_replace(
			['/^--.*$/m', '/`id` bigint\(20\) unsigned NOT NULL AUTO_INCREMENT/', '/,\s*PRIMARY KEY \(`id`\)/', '/,\s*(UNIQUE )?KEY `\w+` \([^)]*\)/', '/ unsigned| COLLATE \w+/', '/\) ENGINE=[^;]*;/'],
			['', '`id` INTEGER PRIMARY KEY AUTOINCREMENT', '', '', '', ');'],
			$rSql
		)))) as $rStatement) {
			$this->rDb->exec($rStatement);
		}
		$this->rDb->exec('ALTER TABLE `cluster_node_epochs` ADD COLUMN `agent_eph_pub` binary(32) DEFAULT NULL');
		$this->rDb->exec('CREATE TABLE `servers` (`id` INTEGER PRIMARY KEY, `status` int NOT NULL DEFAULT 0)');
		$this->rDb->exec('INSERT INTO `servers` (`id`, `status`) VALUES (9, 0)');
		DatabaseFactory::set($this->rDb);
		ClusterClock::fix(null);
		SettingsManager::set(['cluster_api_enabled' => 1, 'lb_new_node_mode' => 'api']);
	}

	protected function tearDown(): void {
		CredentialFreeConfig::useSeams(null);
		DatabaseFactory::reset();
		SettingsManager::set([]);
	}

	private function install(bool $rApi): bool {
		$rRun = function ($rConn, string $rCmd): array {
			$this->rRan[] = $rCmd;
			return ['output' => str_contains($rCmd, 'install_id') ? "0f8fad5b-install-id-0001\n" : '', 'error' => ''];
		};
		$rSend = function ($rConn, string $rLocal, string $rRemote): bool {
			$this->rSent[$rRemote] = (string) file_get_contents($rLocal);
			return true;
		};
		ob_start();
		try {
			return LbInstallFlow::provisionConfig(null, $rRun, $rSend, [SERVER_ID => ['server_ip' => '10.0.0.1'], self::SID => []], self::SID, $this->rDb, $rApi);
		} finally {
			ob_end_clean();
		}
	}

	private function serverStatus(): int {
		$this->rDb->query('SELECT `status` FROM `servers` WHERE `id` = ?', self::SID);
		return (int) $this->rDb->get_row()['status'];
	}

	public function testTheSwitchStaysTheOperatorsAndNeedsTheExtension(): void {
		$rMain = ['http_broadcast_port' => 80, 'https_broadcast_port' => 443];
		$this->assertSame([[], [['lb_new_node_mode', 'cluster_error_api_mode']]], ClusterSettings::normalize(['lb_new_node_mode' => 'api'], $rMain, [], ['credential_free_config' => true]), 'api_mode_allowed is still false');
		$this->assertSame([[], [['lb_new_node_mode', 'cluster_error_api_mode_extension']]], ClusterSettings::normalize(['lb_new_node_mode' => 'api'], $rMain, [], ['api_mode_allowed' => true, 'credential_free_config' => false]));
		$this->assertSame([['lb_new_node_mode' => 'api'], []], ClusterSettings::normalize(['lb_new_node_mode' => 'api'], $rMain, [], ['api_mode_allowed' => true, 'credential_free_config' => true]));

		$rService = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Domain/Server/SettingsService.php');
		$this->assertStringContainsString("'api_mode_allowed' => false,", $rService, 'not flipped here');
		$this->assertStringContainsString("'credential_free_config' => CredentialFreeConfig::supported(),", $rService);
		$this->assertStringContainsString('cluster_error_api_mode_extension = ', (string) file_get_contents(dirname(__DIR__, 2) . '/src/Core/Localization/lang/en.ini'));
	}

	public function testApiModeIsOnlyWithTheClusterApi(): void {
		$this->assertTrue(ClusterSettings::newNodesInApiMode(['cluster_api_enabled' => 1, 'lb_new_node_mode' => 'api']));
		$this->assertFalse(ClusterSettings::newNodesInApiMode(['cluster_api_enabled' => 0, 'lb_new_node_mode' => 'api']));
		$this->assertFalse(ClusterSettings::newNodesInApiMode(['cluster_api_enabled' => 1, 'lb_new_node_mode' => 'legacy']));
		$this->assertFalse(ClusterSettings::newNodesInApiMode(['cluster_api_enabled' => 1]));
	}

	/** An older extension would pack MAIN's credentials: the install stops before anything is written. */
	public function testAnOldExtensionRefusesApiMode(): void {
		CredentialFreeConfig::useSeams(static fn(): bool => false, static function (): string {
			throw new \LogicException('packed without the feature');
		});
		$this->assertFalse($this->install(true));
		$this->assertSame(4, $this->serverStatus());
		$this->assertSame([], $this->rRan, 'nothing ran on the node');
		$this->assertSame([], $this->rSent);
		$this->assertFalse(CredentialFreeConfig::pack('0f8fad5b-install-id-0001', ['hostname' => 'x']));
	}

	public function testApiModeShipsACredentialFreeConfig(): void {
		$rPacked = [];
		CredentialFreeConfig::useSeams(static fn(): bool => true, static function (string $rIid, array $rParams) use (&$rPacked): string {
			$rPacked[] = [$rIid, $rParams];
			return 'XCVT-credential-free';
		});
		$this->assertTrue($this->install(true));
		$this->assertSame([['0f8fad5b-install-id-0001', ['db_credentials' => false, 'hostname' => '10.0.0.1', 'database' => 'xc_vm', 'server_id' => self::SID, 'is_lb' => 1]]], $rPacked);
		$this->assertSame('XCVT-credential-free', $this->rSent[CONFIG_PATH . 'config.enc']);
		$this->assertSame(0, $this->serverStatus());
	}

	/** Born in mode 2 with the flows mode 2 needs, as a promoted node must have. */
	public function testAnApiModeNodeIsEnrolledInModeTwoWithItsFlows(): void {
		$rCrypto = new FakeClusterCrypto();
		EnrolmentService::begin($rCrypto, self::SID, '0f8fad5b-d9cb-469f-a165-70867728950e', random_bytes(32), random_bytes(32), random_bytes(32), ['lb_new_node_mode' => 'api', 'lb_token_rotation_min' => 60]);
		$rNode = NodeRegistry::byServer(self::SID);
		$this->assertSame([2, ClusterAdmin::MODE2_FLOWS], [(int) $rNode['mode'], (int) $rNode['flows']]);

		EnrolmentService::begin($rCrypto, self::SID, '1f8fad5b-d9cb-469f-a165-70867728950e', random_bytes(32), random_bytes(32), random_bytes(32), ['lb_new_node_mode' => 'legacy', 'lb_token_rotation_min' => 60]);
		$rNode = NodeRegistry::byServer(self::SID);
		$this->assertSame([1, 0], [(int) $rNode['mode'], (int) $rNode['flows']], 'legacy stays mode 1, flows off');
	}

	/** No DB grant for an API-mode load balancer, at creation or at the end of its install; and no silent legacy fallback. */
	public function testAnApiModeNodeGetsNoGrantAndNoLegacyFallback(): void {
		$rService = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Domain/Server/ServerService.php');
		$this->assertMatchesRegularExpression('/if \(\$rArray\[\'server_type\'\] == 0 && !ClusterSettings::newNodesInApiMode\(SettingsManager::getAll\(\)\)\) \{\s*BackupService::grantPrivileges/', $rService);
		$rInstall = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Cli/Commands/ServerInstallCommand.php');
		$this->assertStringContainsString('!($rType == 2 && ClusterSettings::newNodesInApiMode(SettingsManager::getAll()))', $rInstall);
		$this->assertMatchesRegularExpression('/if \(\$rGrant\) \{\s*BackupService::grantPrivileges\(\$rHost\);/', $rInstall);

		// Without the cluster API's crypto an API-mode install fails rather than "stays legacy".
		ob_start();
		$rOk = LbInstallFlow::provisionCluster(null, static fn(): array => ['output' => '', 'error' => ''], static fn(): bool => true, [], self::SID, $this->rDb, null, static fn(): ?string => null);
		ob_end_clean();
		$this->assertFalse($rOk);
		$this->assertSame(4, $this->serverStatus());
	}
}
