<?php

/**
 * MAIN's cluster API: `/cluster/v1/<op>` (MAIN ↔ LB plan, Phase 2).
 *
 * A thin HTTP shell around Domain\Cluster\ClusterApi, which does all the
 * checking and is tested without a web server. Deliberately not the web-API
 * bootstrap: no flood/host stages (agents call by IP; nginx rate-limits) and
 * no legacy globals. Settings come from the file cache when it is complete,
 * else from SQL.
 *
 * MAIN only: the LB build strips this file and Domain/Cluster, and the LB
 * nginx has no /cluster/ route.
 *
 * @package XC_VM_Public_Cluster
 */

use XcVm\Core\Cache\FileCache;
use XcVm\Core\Cluster\Crypto\ClusterCryptoFactory;
use XcVm\Core\Config\ConstantsInitializer;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Config\SettingsRepository;
use XcVm\Core\Database\DatabaseHandler;
use XcVm\Domain\Cluster\ClusterApi;
use XcVm\Domain\Cluster\DenialFactory;
use XcVm\Infrastructure\Database\DatabaseFactory;

if (!defined('MAIN_HOME')) {
	define('MAIN_HOME', dirname(dirname(__DIR__)) . '/');
}
require_once MAIN_HOME . 'vendor/autoload.php';
ConstantsInitializer::init();

$rEmit = static function (array $rRes): void {
	http_response_code($rRes['status']);
	foreach ($rRes['headers'] as $rName => $rValue) {
		header($rName . ': ' . $rValue);
	}
	header('Content-Length: ' . strlen($rRes['body']));
	echo $rRes['body'];
};

try {
	$rCrypto = ClusterCryptoFactory::create();
} catch (\Throwable) {
	// Without the extension MAIN cannot sign; agents treat this as a transport error.
	$rEmit(DenialFactory::unsigned(503, 'STARTING'));
	return;
}

$rUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
$rReq = [
	'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
	'path' => (string) parse_url($rUri, PHP_URL_PATH),
	'query' => (string) ($_SERVER['QUERY_STRING'] ?? ''),
	'headers' => function_exists('getallheaders') ? (array) getallheaders() : [],
	'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
];
if ($rReq['path'] === '/cluster/v1/health') {
	// Needs neither the database nor settings: it is how agents tell a MAIN
	// whose database is down from one that is gone.
	$rEmit(ClusterApi::handle($rCrypto, $rReq, [], []));
	return;
}

try {
	// Connect gracefully: DatabaseHandler's constructor exit()s on failure, and
	// an agent must get a signed 503 it can act on instead.
	$db = new class extends DatabaseHandler {
		public function __construct() {
			$this->dbh = false;
		}
	};
	if (!$db->db_connect(false, true)) {
		throw new \RuntimeException('db');
	}
	$rDb = $db;
	DatabaseFactory::set($rDb);
	$rSettings = FileCache::getCache('settings') ?: [];
	if (empty($rSettings['enable_cache'])) {
		$rSettings = SettingsRepository::getAll(true);
	}
	SettingsManager::set($rSettings);
	$rDb->query('SELECT * FROM `servers` WHERE `is_main` = 1 LIMIT 1;');
	$rMain = $rDb->num_rows() > 0 ? (array) $rDb->get_row() : [];
} catch (\Throwable) {
	$rEmit(DenialFactory::deny($rCrypto, 503, 'DB'));
	return;
}

$rReq['body'] = (string) file_get_contents('php://input', false, null, 0, ClusterApi::MAX_BODY + 1);
$rEmit(ClusterApi::handle($rCrypto, $rReq, $rSettings, $rMain));
