<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Bootstrap\BootKernel;
use XcVm\Core\Bootstrap\BootStageInterface;
use XcVm\Core\Bootstrap\BootState;
use XcVm\Core\Bootstrap\StageProfiles;
use XcVm\Core\Bootstrap\Stage\ConfigStage;
use XcVm\Core\Bootstrap\Stage\ConstantsStage;
use XcVm\Core\Bootstrap\Stage\ContainerPopulateStage;
use XcVm\Core\Bootstrap\Stage\DatabaseStage;
use XcVm\Core\Bootstrap\Stage\FloodProtectionStage;
use XcVm\Core\Bootstrap\Stage\HealthCheckStage;
use XcVm\Core\Bootstrap\Stage\HostVerificationStage;
use XcVm\Core\Bootstrap\Stage\LegacyCoreStage;
use XcVm\Core\Bootstrap\Stage\ProcessTitleStage;
use XcVm\Core\Bootstrap\Stage\ReplicaStage;
use XcVm\Core\Cache\FileCache;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\ReplicaBoot;
use XcVm\Core\Container\ServiceContainer;
use XcVm\Core\Enum\BootContext;
use XcVm\Infrastructure\Bootstrap\WebApiBootstrap;
use XcVm\Tests\Support\ReplicaFixture;

/**
 * Booting from the node replica (cluster plan, section 10, step 2; section 9,
 * "Storage and boot"). A node in mode 2 boots its CLI processes and web API
 * endpoints through ReplicaStage, from the caches its replica built, once an
 * apply built them since the reboot; `cluster:apply` boots that way in every
 * mode, so `service` builds the caches at boot while MAIN is unreachable.
 * Mode 0 and 1 nodes, and MAIN, boot exactly as before.
 *
 * The boots themselves run in a child PHP (the real console.php and
 * bootstrap), in a throwaway deploy root, with an xcvm_core stand-in whose
 * MAIN database never answers and logs each connect.
 */
final class ReplicaBootTest extends TestCase {
	private string $rHome;

	private ReplicaFixture $rFixture;

	/** The CLI profile of a mode 0 or 1 node, as before ReplicaStage. */
	private const LEGACY_CLI = [
		ConstantsStage::class, ConfigStage::class, FloodProtectionStage::class, HostVerificationStage::class,
		DatabaseStage::class, LegacyCoreStage::class, ProcessTitleStage::class, ContainerPopulateStage::class, HealthCheckStage::class,
	];

	protected function setUp(): void {
		$this->rHome = sys_get_temp_dir() . '/xcvm-boot-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rHome . 'config/cluster', 0777, true);
		mkdir($this->rHome . 'tmp/cache', 0777, true);
		// LegacyInitializer regenerates the xc_vm crontab once per boot: a
		// `crontab` first on the child's PATH logs it, never the test user's.
		mkdir($this->rHome . 'stub');
		file_put_contents($this->rHome . 'stub/crontab', "#!/bin/sh\necho \"crontab \$*\" >> " . escapeshellarg($this->rHome . 'crontab.log') . "\nfor f; do if [ -f \"\$f\" ]; then cat \"\$f\" >> " . escapeshellarg($this->rHome . 'crontab.log') . "; fi; done\nexit 0\n");
		chmod($this->rHome . 'stub/crontab', 0755);
		$this->rFixture = new ReplicaFixture($this->rHome . 'config/cluster/');
		NodeFlows::usePath($this->rHome . 'config/cluster/flows.json');
	}

	protected function tearDown(): void {
		NodeFlows::usePath(null);
		ReplicaBoot::reset();
		ServiceContainer::resetInstance();
		(new \ReflectionProperty(FileCache::class, 'defaultInstance'))->setValue(null, null);
		exec('rm -rf ' . escapeshellarg($this->rHome));
	}

	private function flows(?int $rMode, int $rFlows = 255, string $rState = 'active'): void {
		$rFile = $this->rHome . 'config/cluster/flows.json';
		if ($rMode === null) {
			@unlink($rFile);
			return;
		}
		file_put_contents($rFile, json_encode(['mode' => $rMode, 'flows' => $rFlows, 'state' => $rState]));
	}

	/** @return list<class-string> */
	private function cliProfile(array $rOptions = []): array {
		return array_map('get_class', StageProfiles::for(BootContext::Cli, BootKernel::resolve(BootContext::Cli, $rOptions + ['process' => 'XC_VM[Console]'])));
	}

	/** @return list<class-string> */
	private function webApiStages(): array {
		return array_map('get_class', WebApiBootstrap::coreStages(true));
	}

	// ── Which boot ───────────────────────────────────────────────────

	public function testModeZeroAndOneAndMainBootExactlyAsBefore(): void {
		foreach ([[null], [0, 0], [1, 63], [2, 255, 'enrolling'], [2, 255, 'revoked']] as $rCase) {
			$this->flows(...$rCase);
			$this->assertFalse(BootKernel::resolve(BootContext::Cli)['replica'], json_encode($rCase));
			$this->assertSame(self::LEGACY_CLI, $this->cliProfile(), json_encode($rCase));
			$this->assertSame([DatabaseStage::class, LegacyCoreStage::class], $this->webApiStages(), json_encode($rCase));
		}
		// A caller that asks for the database keeps it.
		$this->flows(2);
		$this->assertSame(self::LEGACY_CLI, $this->cliProfile(['replica' => false]));
	}

	public function testModeTwoBootsThroughReplicaStage(): void {
		foreach (['active', 'quarantined'] as $rState) {
			$this->flows(2, 255, $rState);
			$this->assertSame(ReplicaBoot::WHEN_READY, BootKernel::resolve(BootContext::Cli)['replica']);
			$rProfile = $this->cliProfile();
			$this->assertSame([ConstantsStage::class, ConfigStage::class, FloodProtectionStage::class, HostVerificationStage::class, ReplicaStage::class, ProcessTitleStage::class, ContainerPopulateStage::class, HealthCheckStage::class], $rProfile);
			$this->assertSame([ReplicaStage::class], $this->webApiStages());
			$rStage = WebApiBootstrap::coreStages(true)[0];
			$this->assertSame(ReplicaBoot::WHEN_READY, (new \ReflectionProperty(ReplicaStage::class, 'rWhen'))->getValue($rStage), 'the web API waits for an apply too');
		}
		// Only the CLI profile: the admin UI (MAIN's) and streaming boots keep theirs.
		$this->assertNull(BootKernel::resolve(BootContext::Admin)['replica']);
	}

	public function testClusterApplyBootsFromTheReplicaInEveryMode(): void {
		$this->assertSame(ReplicaBoot::ALWAYS, ReplicaBoot::forArgv(['console.php', 'cluster:apply', '--from-disk']));
		$this->assertSame(ReplicaBoot::ALWAYS, ReplicaBoot::forArgv(['console.php', 'cluster:apply']));
		$this->assertNull(ReplicaBoot::forArgv(['console.php', 'cron:cache']));
		$this->assertNull(ReplicaBoot::forArgv(['console.php']));
		foreach ([null, 0, 1, 2] as $rMode) {
			$this->flows($rMode);
			$this->assertSame(ReplicaBoot::ALWAYS, BootKernel::resolve(BootContext::Cli, ['replica' => ReplicaBoot::ALWAYS])['replica']);
			$this->assertContains(ReplicaStage::class, $this->cliProfile(['replica' => ReplicaBoot::ALWAYS]));
		}
	}

	public function testUntilAnApplyBuiltTheCachesModeTwoBootsThroughTheDatabase(): void {
		(new \ReflectionProperty(FileCache::class, 'defaultInstance'))->setValue(null, new FileCache($this->rHome . 'tmp/cache/'));
		$rLog = new ArrayObject();
		$rStage = static fn(string $rName): BootStageInterface => new class ($rName, $rLog) implements BootStageInterface {
			public function __construct(private string $rName, private ArrayObject $rLog) {
			}

			public function run(BootState $state): void {
				$this->rLog->append($this->rName);
			}
		};
		$rState = new BootState(BootContext::Cli, [], ServiceContainer::getInstance());
		(new ReplicaStage(false, ReplicaBoot::WHEN_READY, [$rStage('db'), $rStage('core')]))->run($rState);
		$this->assertSame(['db', 'core'], $rLog->getArrayCopy(), 'nothing to boot from yet: DatabaseStage and LegacyCoreStage');
		$this->assertFalse($rState->replica);
		$this->assertFalse(ReplicaBoot::active());

		// Only the settings built: the servers are still MAIN's database's.
		FileCache::setCache('replica_owned', ['settings' => 'e']);
		$this->assertFalse(ReplicaBoot::ready());
		FileCache::setCache('replica_owned', ['settings' => 'e', 'servers' => 'e/e']);
		$this->assertTrue(ReplicaBoot::ready());
	}

	// ── The boots, in a child PHP ────────────────────────────────────

	/**
	 * Run the real console.php (or $rScript) in the throwaway deploy root.
	 *
	 * @param list<string> $rArgs
	 * @return array{0: int, 1: string, 2: list<string>} exit code, stdout, the connects to MAIN's database
	 */
	private function child(array $rArgs, ?string $rScript = null): array {
		$rPrepend = $this->rHome . 'prepend.php';
		file_put_contents($rPrepend, <<<'PHP'
			<?php
			// A throwaway deploy root, and an xcvm_core whose MAIN database never answers.
			define('MAIN_HOME', getenv('XCVM_TEST_HOME'));
			final class XC_VM {
				public static function config_server(): array {
					return ['server_id' => 5];
				}

				public static function db_connect(bool $rMigrate = false) {
					file_put_contents(MAIN_HOME . 'connects.log', "sql\n", FILE_APPEND);
					return false;
				}
			}
			PHP);
		@unlink($this->rHome . 'connects.log');
		$rCommand = array_merge([PHP_BINARY, '-d', 'auto_prepend_file=' . $rPrepend, $rScript ?? dirname(__DIR__, 2) . '/src/console.php'], $rArgs);
		$rProc = proc_open($rCommand, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $rPipes, $this->rHome, ['XCVM_TEST_HOME' => $this->rHome, 'XCVM_TEST_SRC' => dirname(__DIR__, 2) . '/src/', 'PATH' => $this->rHome . 'stub:' . getenv('PATH')]);
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]);
		fclose($rPipes[1]);
		$rCode = proc_close($rProc);
		$rConnects = is_file($this->rHome . 'connects.log') ? file($this->rHome . 'connects.log', FILE_IGNORE_NEW_LINES) : [];
		return [$rCode, $rOut, $rConnects];
	}

	/** A child script that boots as a CLI command (or `webapi`: a cached web API endpoint) and dumps what the boot left. */
	private function dumpScript(): string {
		$rScript = $this->rHome . 'dump.php';
		file_put_contents($rScript, <<<'PHP'
			<?php
			use XcVm\Core\Bootstrap\BootPipeline;
			use XcVm\Core\Bootstrap\BootState;
			use XcVm\Core\Cluster\ReplicaBoot;
			use XcVm\Core\Config\ConstantsInitializer;
			use XcVm\Core\Config\SettingsManager;
			use XcVm\Core\Container\ServiceContainer;
			use XcVm\Core\Enum\BootContext;
			use XcVm\Infrastructure\Bootstrap\WebApiBootstrap;
			use XcVm\Infrastructure\Database\DatabaseFactory;

			require getenv('XCVM_TEST_SRC') . 'bootstrap.php';
			if (($argv[1] ?? '') === 'webapi') {
				// WebApiBootstrap::init's core step, for a cached endpoint.
				ConstantsInitializer::init();
				(new BootPipeline(WebApiBootstrap::coreStages(true)))->run(new BootState(BootContext::WebApi, ['cached' => true], ServiceContainer::getInstance()));
			} else {
				XC_Bootstrap::boot(BootContext::Cli, ['replica' => ReplicaBoot::forArgv(['console.php', 'cron:cache'])]);
			}
			$rC = ServiceContainer::getInstance();
			$rSettings = SettingsManager::getAll();
			SettingsManager::get('zz_not_carried'); // a read the replica would not answer (SettingsAudit)
			echo json_encode([
				'replica' => ReplicaBoot::active(),
				'db' => get_class($GLOBALS['db']),
				'opened' => method_exists($GLOBALS['db'], 'isOpen') ? $GLOBALS['db']->isOpen() : null,
				'factory' => DatabaseFactory::get() === $GLOBALS['db'],
				'container_db' => $rC->has('db') ? $rC->get('db') === $GLOBALS['db'] : null,
				'server_id' => SERVER_ID,
				'settings_global' => $GLOBALS['rSettings'] === $rSettings,
				'core_settings' => $rC->get('core.settings') === $rSettings,
				'container_settings' => $rC->has('settings') ? $rC->get('settings') === $rSettings : null,
				'live_streaming_pass' => $rSettings['live_streaming_pass'] ?? null,
				'on_demand_wait_time' => $rSettings['on_demand_wait_time'] ?? null,
				'timezone' => date_default_timezone_get(),
				'servers' => array_keys($GLOBALS['rServers']),
				'core_servers' => array_keys($rC->get('core.servers')),
				'api_url' => $GLOBALS['rServers'][5]['api_url'] ?? null,
				'core_bouquets' => $rC->get('core.bouquets'),
				'core_categories' => $rC->get('core.categories'),
				'container_bouquets' => $rC->has('bouquets') ? $rC->get('bouquets') : null,
				'core_config' => $rC->get('core.config'),
				'ffmpeg' => is_string($GLOBALS['rFFMPEG_CPU']) && is_string($GLOBALS['rFFPROBE']),
				'request' => is_array($GLOBALS['rRequest']),
			]);
			PHP);
		return $rScript;
	}

	/** @return array<string, mixed> */
	private function dump(bool $rWebApi = false): array {
		[$rCode, $rOut, $rConnects] = $this->child($rWebApi ? ['webapi'] : [], $this->dumpScript());
		$this->assertSame(0, $rCode, $rOut);
		$rDump = json_decode($rOut, true);
		$this->assertIsArray($rDump, $rOut);
		return $rDump + ['connects' => $rConnects];
	}

	/** @return mixed a cache the child wrote */
	private function cache(string $rKey): mixed {
		$rData = (string) @file_get_contents($this->rHome . 'tmp/cache/' . $rKey);
		return function_exists('igbinary_unserialize') ? @igbinary_unserialize($rData) : @unserialize($rData);
	}

	public function testFromDiskBuildsTheCachesWhileMainsDatabaseIsUnreachable(): void {
		$this->flows(1, 63);
		$this->rFixture->node();
		[$rCode, $rOut, $rConnects] = $this->child(['cluster:apply', '--from-disk']);
		$this->assertSame(0, $rCode, $rOut);
		$this->assertSame([], $rConnects, 'no connection to MAIN\'s database, at boot or after');
		$rReport = json_decode($rOut, true);
		$this->assertSame(['verified' => ['blocklist', 'settings', 'servers', 'node', 'crontab', 'secrets'], 'unverified' => []], $rReport['from_disk']);
		foreach (['secrets', 'settings', 'servers', 'crontab'] as $rPart) {
			$this->assertSame('applied', $rReport[$rPart]['mode'], $rPart);
		}
		$this->assertSame('applied', $rReport['mode'], 'the blocklist');

		$rSettings = $this->cache('settings');
		$this->assertSame(['stream-pass', 'UTC'], [$rSettings['live_streaming_pass'], $rSettings['default_timezone']]);
		$this->assertSame([1, 5], array_keys($this->cache('servers')));
		$this->assertStringContainsString('password=stream-pass', $this->cache('servers')[5]['api_url']);
		$this->assertSame(['203.0.113.1'], $this->cache('blocked_ips'));
		$this->assertSame('extra-from-main', trim((string) file_get_contents($this->rHome . 'config/openssl_extra')));
		$this->assertSame(['settings', 'servers', 'crontab'], array_keys($this->cache('replica_owned')));
		$this->assertFileDoesNotExist($this->rHome . 'crontab.log', 'the replica did not own the crontab at boot: left as it is, and MAIN\'s table not read');
	}

	public function testTheAgentsApplyNeedsNoDatabaseOnceConfigIsOn(): void {
		$this->flows(1, 63);
		$this->rFixture->node();
		[$rCode, $rOut, $rConnects] = $this->child(['cluster:apply']);
		$this->assertSame(0, $rCode, $rOut);
		$this->assertSame([], $rConnects);
		$this->assertArrayNotHasKey('from_disk', json_decode($rOut, true), 'the .json files the agent verified');
		$this->assertSame('stream-pass', $this->cache('settings')['live_streaming_pass']);
	}

	public function testAModeOneNodeStillConnectsAtBoot(): void {
		$this->flows(1, 63);
		$this->rFixture->node();
		$this->child(['cluster:apply', '--from-disk']);
		// Every other command boots through MAIN's database, as before.
		[, $rOut, $rConnects] = $this->child(['--list']);
		$this->assertSame(['sql'], $rConnects);
		$this->assertStringContainsString('Cannot connect to database', $rOut);
	}

	public function testAModeTwoNodeBootsFromItsReplicaOnceAnApplyBuiltIt(): void {
		$this->flows(2);
		$this->rFixture->node();
		// Rebooted, nothing applied yet: through MAIN's database, the CLI and the web API alike.
		[, , $rConnects] = $this->child(['--list']);
		$this->assertSame(['sql'], $rConnects, 'nothing to boot from yet');
		[, , $rConnects] = $this->child(['webapi'], $this->dumpScript());
		$this->assertSame(['sql'], $rConnects, 'nor for the web API');

		[$rCode, $rOut] = $this->child(['cluster:apply', '--from-disk']);
		$this->assertSame(0, $rCode, $rOut);
		[$rCode, $rOut, $rConnects] = $this->child(['--list']);
		$this->assertSame(0, $rCode, $rOut);
		$this->assertSame([], $rConnects);
		$this->assertStringContainsString('cluster:apply', $rOut);
		// That boot wrote the xc_vm crontab from the replica's jobs, without MAIN's table.
		$this->assertStringContainsString('console.php cron:cleanup # XC_VM', (string) @file_get_contents($this->rHome . 'crontab.log'));

		file_put_contents($this->rHome . 'tmp/cache/bouquets', function_exists('igbinary_serialize') ? igbinary_serialize([3 => ['id' => 3]]) : serialize([3 => ['id' => 3]]));
		foreach ([false, true] as $rWebApi) {
			$rDump = $this->dump($rWebApi);
			$this->assertSame([], $rDump['connects'], 'the boot opens no connection');
			$this->assertTrue($rDump['replica']);
			$this->assertSame(['XcVm\\Core\\Database\\LazyDatabaseHandler', false, true], [$rDump['db'], $rDump['opened'], $rDump['factory']]);
			$this->assertSame(5, $rDump['server_id']);
			$this->assertSame([true, true], [$rDump['settings_global'], $rDump['core_settings']]);
			$this->assertSame(['stream-pass', '20', 'UTC'], [$rDump['live_streaming_pass'], $rDump['on_demand_wait_time'], $rDump['timezone']]);
			$this->assertSame([[1, 5], [1, 5]], [$rDump['servers'], $rDump['core_servers']]);
			$this->assertStringContainsString('password=stream-pass', (string) $rDump['api_url']);
			$this->assertSame([[3 => ['id' => 3]], []], [$rDump['core_bouquets'], $rDump['core_categories']], 'the caches as they are');
			$this->assertSame(['server_id' => 5], $rDump['core_config']);
			$this->assertTrue($rDump['ffmpeg'] && $rDump['request']);
			if (!$rWebApi) {
				$this->assertSame([true, true, [3 => ['id' => 3]]], [$rDump['container_db'], $rDump['container_settings'], $rDump['container_bouquets']]);
			}
			// The boot itself read only allowlisted settings; the process's one miss
			// reached the day file at its exit, and the audit.json the agent sends.
			$rDays = 0;
			foreach (glob($this->rHome . 'storage/cluster/settings_misses/*.json') ?: [] as $rFile) {
				$rDays += json_decode((string) file_get_contents($rFile), true)['zz_not_carried'] ?? 0;
			}
			$this->assertSame($rWebApi ? 2 : 1, $rDays);
			$this->assertSame(['zz_not_carried'], array_keys(json_decode((string) @file_get_contents($this->rHome . 'config/cluster/audit.json'), true)['settings_misses'] ?? []));
		}
	}
}
