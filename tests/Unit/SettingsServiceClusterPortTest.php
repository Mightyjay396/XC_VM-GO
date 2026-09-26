<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cache\FileCache;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Database\DatabaseHandler;
use XcVm\Core\Localization\Translator;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\ClusterNginxConfig;
use XcVm\Domain\Server\SettingsService;
use XcVm\Infrastructure\Database\DatabaseFactory;

if (!defined('STATUS_FAILURE')) {
	define('STATUS_FAILURE', 0);
}
if (!defined('STATUS_SUCCESS')) {
	define('STATUS_SUCCESS', 1);
}
if (!defined('STATUS_INVALID_DATA')) {
	define('STATUS_INVALID_DATA', 17);
}

/**
 * A settings save that changes `cluster_api_port` (plan §3: refused on port
 * collisions, applied only after `nginx -t` passes). When nginx cannot take
 * the new port, SettingsService::edit() refuses the whole save: the stored
 * port and `cluster_policy_ver` stay, so no node is sent to it.
 *
 * Runs edit() on the TestDb. Under SQLite, QueryHelper::verifyPostTable()'s
 * `information_schema.columns` is an attached table, with DATABASE() as a
 * function; on MariaDB (XCVM_TEST_DB_DSN) it is the real one. nginx and the
 * port checks are ClusterNginxConfig's fakes.
 */
final class SettingsServiceClusterPortTest extends TestCase {
	private TestDb $rDb;

	private string $rDir;

	/** What the faked `nginx -t` answers: [exit code, output]. */
	private array $rTest = [0, ''];

	/** @var list<int> */
	private array $rBusy = [];

	/** @var list<string> the nginx actions run: "test" or "reload" */
	private array $rRuns = [];

	private array $rSettingsBefore = [];

	/** The settings columns the save touches: name => [data_type, default]. */
	private const COLUMNS = [
		'cluster_api_enabled' => ['int', '0'],
		'cluster_api_port' => ['int', '0'],
		'cluster_policy_ver' => ['int', '1'],
		'cluster_legacy_ports' => ['varchar', ''],
		'search_items' => ['int', '15'],
		'disable_table_responsive' => ['int', '0'],
		'allowed_stb_types_for_local_recording' => ['text', ''],
		'allowed_stb_types' => ['text', ''],
		'maxmind_editions' => ['text', ''],
		'shared_mount_prefixes' => ['text', ''],
		'allow_countries' => ['text', ''],
	];

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-settings-port-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir . 'bin/nginx/conf/ports', 0777, true);
		mkdir($this->rDir . 'cache', 0777, true);
		file_put_contents($this->rDir . 'bin/nginx/conf/ports/http.conf', 'listen 25461;');
		copy(dirname(__DIR__, 2) . '/src/bin/nginx/conf/nginx.conf', $this->rDir . 'bin/nginx/conf/nginx.conf');

		$this->rDb = new TestDb();
		$rDdl = [];
		foreach (self::COLUMNS as $rName => [$rType, $rDefault]) {
			$rDdl[] = '`' . $rName . '` ' . ($rType === 'int' ? 'int' : 'varchar(255)') . " DEFAULT '" . $rDefault . "'";
		}
		$this->rDb->exec('CREATE TABLE `settings` (`id` INTEGER PRIMARY KEY, ' . implode(', ', $rDdl) . ')');
		$this->rDb->exec('INSERT INTO `settings` (`id`, `cluster_api_enabled`) VALUES (1, 1)');
		$this->rDb->exec('CREATE TABLE `streams_arguments` (`argument_key` varchar(64), `argument_default_value` varchar(255))');
		$this->rDb->exec('CREATE TABLE `cluster_audit` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `time` int, `server_id` int, `actor` varchar(64), `event` varchar(64), `detail` text, `ip` varchar(64))');
		if ($this->rDb->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
			$this->rDb->pdo->exec("ATTACH DATABASE ':memory:' AS `information_schema`");
			$this->rDb->pdo->exec('CREATE TABLE `information_schema`.`columns` (`table_schema` text, `table_name` text, `column_name` text, `column_default` text, `is_nullable` text, `data_type` text, `ordinal_position` int)');
			$this->rDb->pdo->sqliteCreateFunction('DATABASE', static fn(): string => 'xc_vm', 0);
			$rPosition = 0;
			foreach (self::COLUMNS as $rName => [$rType, $rDefault]) {
				$this->rDb->query('INSERT INTO `information_schema`.`columns` VALUES (?, ?, ?, ?, ?, ?, ?)', 'xc_vm', 'settings', $rName, "'" . $rDefault . "'", 'NO', $rType, ++$rPosition);
			}
		}
		DatabaseFactory::set($this->rDb);
		// QueryHelper::verifyPostTable() reads the global handler.
		$GLOBALS['db'] = $this->rDb;

		// The main server row, from the servers cache.
		(new \ReflectionProperty(FileCache::class, 'defaultInstance'))->setValue(null, new FileCache($this->rDir . 'cache/'));
		FileCache::setCache('servers', [1 => ['id' => 1, 'is_main' => 1, 'server_ip' => '10.0.0.1', 'http_broadcast_port' => 25461]]);
		$this->rSettingsBefore = SettingsManager::getAll();
		$this->rDb->query('SELECT * FROM `settings`');
		SettingsManager::set($this->rDb->get_row());
		unset($_COOKIE['lang']);
		Translator::init(dirname(__DIR__, 2) . '/src/Core/Localization/lang');

		ClusterClock::fix(1800000000 * 1000);
		ClusterNginxConfig::useBase($this->rDir, (string) (posix_getpwuid(posix_geteuid())['name'] ?? ''));
		ClusterNginxConfig::useRunner(function (array $rArgv): array {
			$this->rRuns[] = in_array('-t', $rArgv, true) ? 'test' : 'reload';
			return in_array('-t', $rArgv, true) ? $this->rTest : [0, ''];
		});
		ClusterNginxConfig::useProbe(fn(string $rCheck, int $rPort): bool => $rCheck === 'free' ? !in_array($rPort, $this->rBusy, true) : true);
	}

	protected function tearDown(): void {
		ClusterNginxConfig::useRunner(null);
		ClusterNginxConfig::useProbe(null);
		ClusterNginxConfig::useBase(null);
		ClusterClock::fix(null);
		SettingsManager::set($this->rSettingsBefore);
		(new \ReflectionProperty(FileCache::class, 'defaultInstance'))->setValue(null, null);
		unset($GLOBALS['db']);
		DatabaseFactory::reset();
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/** @return array{0: int, 1: int} the stored cluster_api_port and cluster_policy_ver */
	private function stored(): array {
		$this->rDb->query('SELECT `cluster_api_port`, `cluster_policy_ver` FROM `settings`');
		$rRow = $this->rDb->get_row();
		return [(int) $rRow['cluster_api_port'], (int) $rRow['cluster_policy_ver']];
	}

	/** The handler, with the save's own settings UPDATE failing the way DatabaseHandler fails: false. */
	private function failingSave(): DatabaseHandler {
		return new class ($this->rDb) extends DatabaseHandler {
			public function __construct(private TestDb $rInner) {
			}

			public function query($query, ...$args): bool {
				return !str_contains((string) $query, 'SET `search_items` = ?') && $this->rInner->query($query, ...$args);
			}

			public function get_rows($use_id = false, $column_as_id = '', $unique_row = true, $sub_row_id = '') {
				return $this->rInner->get_rows($use_id, $column_as_id, $unique_row, $sub_row_id);
			}

			public function get_row() {
				return $this->rInner->get_row();
			}

			public function num_rows(): int {
				return $this->rInner->num_rows();
			}
		};
	}

	private function save(string $rPort): array {
		return SettingsService::edit(['user_agent' => '', 'http_proxy' => '', 'cookie' => '', 'headers' => '', 'search_items' => '15', 'cluster_api_port' => $rPort]);
	}

	public function testAPortNginxRefusesFailsTheSave(): void {
		$this->rTest = [1, "nginx: [emerg] unknown directive \"oops\" in custom.conf:1\nnginx: configuration file test failed\n"];
		$rResult = $this->save('31200');
		$this->assertSame(STATUS_INVALID_DATA, $rResult['status']);
		$this->assertStringStartsWith('The cluster API port was not changed: nginx refused it', $rResult['data']['message']);
		$this->assertStringContainsString('unknown directive &quot;oops&quot; in custom.conf:1', $rResult['data']['message'], "nginx's words, escaped");
		$this->assertSame([0, 1], $this->stored(), 'the stored port and the policy version stay');
		$this->assertFileDoesNotExist($this->rDir . 'bin/nginx/conf/' . ClusterNginxConfig::LISTEN);
	}

	public function testAPortAnotherProgramHoldsFailsTheSave(): void {
		$this->rBusy = [31200];
		$rResult = $this->save('31200');
		$this->assertSame(STATUS_INVALID_DATA, $rResult['status']);
		$this->assertSame('The cluster API port was not changed: another program on MAIN already listens on it.', $rResult['data']['message']);
		$this->assertSame([0, 1], $this->stored());
		$this->assertFileDoesNotExist($this->rDir . 'bin/nginx/conf/' . ClusterNginxConfig::LISTEN);
		$this->assertSame([], $this->rRuns);
	}

	/** A save whose UPDATE fails: nothing is announced, and nginx goes back to the stored port. */
	public function testASaveThatIsNotStoredPutsNginxBackOnTheStoredPort(): void {
		$rDb = $this->failingSave();
		DatabaseFactory::set($rDb);
		$GLOBALS['db'] = $rDb;
		$this->assertSame(['status' => STATUS_FAILURE], $this->save('31200'));
		$this->assertSame([0, 1], $this->stored(), 'nothing announced');
		$this->assertFileDoesNotExist($this->rDir . 'bin/nginx/conf/' . ClusterNginxConfig::LISTEN, 'staged, then taken back');
		$this->assertSame(['test', 'reload', 'test', 'reload'], $this->rRuns);
	}
}
