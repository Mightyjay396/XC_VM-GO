<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cache\FileCache;
use XcVm\Domain\Server\ServerRepository;

/**
 * ServerRepository::getAll keeps servers.php_pids out of the rows it returns
 * and of the 'servers' cache that every stream request reads. The column holds
 * one pid per live PHP-FPM worker of a node; only MAIN's cron:users needs it,
 * and it reads the column itself. (CacheCronJob's unset($rServers['php_pids'])
 * meant to strip it, but the map is keyed by server id, so it did nothing.)
 */
final class ServerRepositoryPhpPidsTest extends TestCase {

	private TestDb $db;

	private $rSettingsBackup;

	private $rSchemeBackup;

	protected function setUp(): void {
		if (!defined('CACHE_TMP_PATH')) {
			$rDir = sys_get_temp_dir() . '/xcvm_servers_cache_test/';
			@mkdir($rDir, 0755, true);
			define('CACHE_TMP_PATH', $rDir);
		}
		@mkdir(CACHE_TMP_PATH, 0755, true);
		if (!defined('SERVER_ID')) {
			define('SERVER_ID', 1);
		}

		$this->rSettingsBackup = $GLOBALS['rSettings'] ?? null;
		$this->rSchemeBackup = $_SERVER['REQUEST_SCHEME'] ?? null;
		$GLOBALS['rSettings'] = ['live_streaming_pass' => 'x'];

		$this->db = new TestDb();
		$this->db->exec(
			'CREATE TABLE servers (id INTEGER PRIMARY KEY, server_type INTEGER, is_main INTEGER, enabled INTEGER, status INTEGER, last_check_ago INTEGER, parent_id TEXT, domain_name TEXT, server_ip TEXT, private_ip TEXT, enable_https INTEGER, http_broadcast_port INTEGER, https_broadcast_port INTEGER, rtmp_port INTEGER, geoip_countries TEXT, isp_names TEXT, watchdog_data TEXT, php_pids TEXT, `order` INTEGER);'
		);
		$this->db->query(
			'INSERT INTO servers (id, server_type, is_main, enabled, status, last_check_ago, parent_id, domain_name, server_ip, private_ip, enable_https, http_broadcast_port, https_broadcast_port, rtmp_port, geoip_countries, isp_names, watchdog_data, php_pids, `order`) VALUES
			 (7, 0, 0, 1, 1, ?, NULL, "", "10.0.0.7", "", 0, 80, 443, 8880, "", "", "{}", "[11,12]", 0);',
			time()
		);
		ServerRepository::setDb($this->db);
	}

	protected function tearDown(): void {
		FileCache::delCache('servers');
		$GLOBALS['rSettings'] = $this->rSettingsBackup;
		if ($this->rSchemeBackup === null) {
			unset($_SERVER['REQUEST_SCHEME']);
		} else {
			$_SERVER['REQUEST_SCHEME'] = $this->rSchemeBackup;
		}
	}

	public function testReturnedRowsCarryNoPhpPids(): void {
		$rServers = ServerRepository::getAll(true);

		$this->assertArrayHasKey(7, $rServers);
		$this->assertSame('10.0.0.7', $rServers[7]['server_ip'], 'the row itself is still loaded');
		$this->assertArrayNotHasKey('php_pids', $rServers[7]);
	}

	public function testCachedRowsCarryNoPhpPids(): void {
		ServerRepository::getAll(true);

		$rCache = FileCache::getCache('servers');
		$this->assertIsArray($rCache);
		$this->assertArrayHasKey(7, $rCache);
		$this->assertArrayNotHasKey('php_pids', $rCache[7]);
	}
}
