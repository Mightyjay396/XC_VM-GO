<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\Crypto\Enc;
use XcVm\Core\Cluster\Crypto\PanelSig;
use XcVm\Domain\Cluster\ClusterApi;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\ClusterMeta;
use XcVm\Domain\Cluster\ClusterPool;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\FakeClusterCrypto;

/**
 * MAIN's cluster FPM pools (plan §3, "Transport"): control ops run on
 * `cluster_ctl`, ingest on `cluster_ingest`, each sized by the plan's
 * formula; ensure() writes, starts and reloads them only when needed, and the
 * API answers a panel-signed 503 STARTING until both pools answer.
 *
 * The pools' processes are faked in most tests; the ping runs against a
 * FastCGI responder, the master lookup against a fake procfs, and
 * testRealPhpFpm against a real php-fpm, opt-in: XCVM_TEST_FPM=/path/to/php-fpm.
 */
final class ClusterPoolTest extends TestCase {
	/** A FastCGI responder: one connection per mode given after the socket, in order. */
	private const RESPONDER = <<<'PHP'
		<?php
		$rServer = stream_socket_server('unix://' . $argv[1], $rErrNo, $rErrStr);
		if ($rServer === false) {
			exit(1);
		}
		$rRead = static function ($rConn, int $rLength): ?string {
			$rOut = '';
			while (strlen($rOut) < $rLength) {
				$rChunk = fread($rConn, $rLength - strlen($rOut));
				if ($rChunk === false || $rChunk === '') {
					return null;
				}
				$rOut .= $rChunk;
			}
			return $rOut;
		};
		$rRecord = static fn(int $rType, string $rBody, int $rPad = 0): string => pack('CCnnCC', 1, $rType, 1, strlen($rBody), $rPad, 0) . $rBody . str_repeat("\0", $rPad);
		$rEnd = $rRecord(3, pack('NCx3', 0, 0));
		$rHeaders = "Content-type: text/plain;charset=UTF-8\r\nExpires: Thu, 01 Jan 1970 00:00:00 GMT\r\n\r\n";
		foreach (array_slice($argv, 2) as $rMode) {
			$rConn = stream_socket_accept($rServer, 10);
			if ($rConn === false) {
				exit(1);
			}
			// The request, up to its empty STDIN record.
			while (($rHead = $rRead($rConn, 8)) !== null) {
				$rRec = unpack('Cversion/Ctype/nid/nlength/Cpadding/Creserved', $rHead);
				$rRead($rConn, $rRec['length'] + $rRec['padding']);
				if ($rRec['type'] === 5 && $rRec['length'] === 0) {
					break;
				}
			}
			$rOut = match ($rMode) {
				'pong' => [$rRecord(6, $rHeaders . 'pong') . $rRecord(6, '') . $rEnd],
				'other' => [$rRecord(6, $rHeaders . 'File not found.') . $rRecord(6, '') . $rEnd],
				'cut' => [$rRecord(6, $rHeaders . 'pong')],
				// Padded records, a STDERR record, the body across two records, in 5-byte writes.
				'split' => str_split($rRecord(6, $rHeaders, 3) . $rRecord(7, 'a notice', 1) . $rRecord(6, 'po', 6) . $rRecord(6, 'ng', 7) . $rRecord(6, '') . $rEnd, 5),
			};
			foreach ($rOut as $rPiece) {
				fwrite($rConn, $rPiece);
				fflush($rConn);
				usleep(2000);
			}
			fclose($rConn);
		}
		PHP;

	private TestDb $rDb;

	private string $rBase;

	/** @var list<string> "<action> <pool>", in call order */
	private array $rCalls = [];

	/** @var array<string, bool> pool => its master runs */
	private array $rAlive = [];

	/** @var array<string, bool> pool => once running, it answers */
	private array $rWorks = ['cluster_ctl' => true, 'cluster_ingest' => true];

	/** @var array<string, bool> pool => reloading: FPM still lets a held long-poll finish */
	private array $rReloading = [];

	/** @var resource|null the FastCGI responder */
	private $rResponder = null;

	protected function setUp(): void {
		$this->rBase = sys_get_temp_dir() . '/xcvm-pool-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rBase . 'tmp', 0777, true);
		$this->rDb = new TestDb();
		$this->rDb->exec('CREATE TABLE `servers` (`id` INTEGER PRIMARY KEY, `is_main` int NOT NULL DEFAULT 0, `server_type` int NOT NULL DEFAULT 0)');
		// MAIN, two LBs and a proxy: two nodes.
		$this->rDb->exec('INSERT INTO `servers` (`id`, `is_main`, `server_type`) VALUES (1, 1, 0), (2, 0, 0), (3, 0, 0), (4, 0, 1)');
		$this->rDb->exec('CREATE TABLE `settings` (`id` INTEGER PRIMARY KEY, `cluster_ingest_concurrency` int DEFAULT 6)');
		$this->rDb->exec('INSERT INTO `settings` (`id`) VALUES (1)');
		$this->rDb->exec('CREATE TABLE `cluster_meta` (`name` varchar(64) PRIMARY KEY, `value` text, `updated_at` int)');
		DatabaseFactory::set($this->rDb);
		ClusterClock::fix(1800000000000);
		// ensure() runs only as the pool user: here, whoever runs the tests.
		ClusterPool::useBase($this->rBase, self::user());
		ClusterPool::useProcs(function (string $rAction, string $rPool): bool {
			$this->rCalls[] = $rAction . ' ' . $rPool;
			switch ($rAction) {
				case 'start':
					$this->rAlive[$rPool] = true;
					return true;
				case 'reload':
					$this->rReloading[$rPool] = true;
					return true;
				case 'answers':
					return ($this->rAlive[$rPool] ?? false) && $this->rWorks[$rPool] && !($this->rReloading[$rPool] ?? false);
				case 'alive':
					return $this->rAlive[$rPool] ?? false;
				default:
					return true;
			}
		});
	}

	protected function tearDown(): void {
		// Every FPM master testRealPhpFpm started, the decoy included.
		foreach (glob($this->rBase . 'bin/php/var/run/*.pid') ?: [] as $rPidFile) {
			$rPid = (int) @file_get_contents($rPidFile);
			if ($rPid > 0) {
				posix_kill($rPid, defined('SIGTERM') ? SIGTERM : 15);
			}
		}
		if (is_resource($this->rResponder)) {
			proc_terminate($this->rResponder);
			proc_close($this->rResponder);
		}
		ClusterPool::useProcs(null);
		ClusterPool::useProcRoot();
		ClusterPool::useBase(null);
		ClusterClock::fix(null);
		DatabaseFactory::reset();
		exec('rm -rf ' . escapeshellarg($this->rBase));
	}

	private static function user(): string {
		$rUser = posix_getpwuid(posix_geteuid());
		return is_array($rUser) ? (string) $rUser['name'] : '';
	}

	/** @return list<string> calls other than the liveness checks, since the last take */
	private function takeCalls(): array {
		$rCalls = array_values(array_filter($this->rCalls, static fn(string $rCall): bool => !str_starts_with($rCall, 'alive ') && !str_starts_with($rCall, 'answers ')));
		$this->rCalls = [];
		return $rCalls;
	}

	private function conf(string $rPool): string {
		return (string) file_get_contents($this->rBase . 'bin/php/etc/cluster/' . $rPool . '.conf');
	}

	public function testPoolSizesFollowThePlan(): void {
		$this->assertSame(24, ClusterPool::ctlChildren(0), '2·nodes + 24');
		$this->assertSame(44, ClusterPool::ctlChildren(10));
		$this->assertSame(24, ClusterPool::ctlChildren(-3), 'never fewer than for no node');

		$this->assertSame(20, ClusterPool::ingestChildren(6, null), '2·concurrency + 8 without a known MariaDB limit');
		$this->assertSame(20, ClusterPool::ingestChildren(6, 151), 'under a quarter of MariaDB max_connections');
		$this->assertSame(37, ClusterPool::ingestChildren(64, 151), 'capped at floor(0.25 · max_connections)');
		$this->assertSame(10, ClusterPool::ingestChildren(6, 40));
		$this->assertSame(10, ClusterPool::ingestChildren(0, null), 'concurrency clamps to its 1..64 bounds');
		$this->assertSame(136, ClusterPool::ingestChildren(1000, null));
		$this->assertSame(ClusterPool::MIN_INGEST, ClusterPool::ingestChildren(6, 4), 'one P0 and one bulk request, however small MariaDB is');
	}

	public function testSizesComeFromTheServersAndSettings(): void {
		// SQLite has no max_connections; against MariaDB (XCVM_TEST_DB_DSN) its own caps the pool.
		$rMax = $this->rDb->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? (int) $this->rDb->pdo->query('SELECT @@GLOBAL.max_connections')->fetchColumn() : null;
		$this->assertSame(['cluster_ctl' => 28, 'cluster_ingest' => ClusterPool::ingestChildren(6, $rMax)], ClusterPool::sizes(), 'two LBs; the proxy and MAIN do not count');
		$this->rDb->exec('UPDATE `settings` SET `cluster_ingest_concurrency` = 10');
		$this->rDb->exec('INSERT INTO `servers` (`id`, `is_main`, `server_type`) VALUES (5, 0, 0)');
		$this->assertSame(['cluster_ctl' => 30, 'cluster_ingest' => ClusterPool::ingestChildren(10, $rMax)], ClusterPool::sizes());
		$this->assertSame(28, ClusterPool::ingestChildren(10, null));
	}

	public function testPoolConfigs(): void {
		$rCtl = ClusterPool::render('cluster_ctl', 28, '/home/xc_vm/');
		$this->assertStringContainsString("[cluster_ctl]\n", $rCtl);
		$this->assertStringContainsString("pid = /home/xc_vm/bin/php/var/run/cluster_ctl.pid\n", $rCtl, 'not in sockets/: restart_php_fpm counts the panel pools there');
		$this->assertStringContainsString("listen = /home/xc_vm/bin/php/sockets/cluster_ctl.sock\n", $rCtl);
		$this->assertStringContainsString("listen.owner = xc_vm\n", $rCtl);
		$this->assertStringContainsString("pm = ondemand\n", $rCtl);
		$this->assertStringContainsString("pm.max_children = 28\n", $rCtl);
		$this->assertStringContainsString("request_terminate_timeout = 60s\n", $rCtl);
		$this->assertStringContainsString("ping.path = /ping\n", $rCtl);
		$this->assertStringContainsString("security.limit_extensions = .php\n", $rCtl);

		$rIngest = ClusterPool::render('cluster_ingest', 20, '/home/xc_vm/');
		$this->assertStringContainsString("[cluster_ingest]\n", $rIngest);
		$this->assertStringContainsString("listen = /home/xc_vm/bin/php/sockets/cluster_ingest.sock\n", $rIngest);
		$this->assertStringContainsString("pm.max_children = 20\n", $rIngest);
		$this->assertStringContainsString("request_terminate_timeout = 90s\n", $rIngest);
	}

	public function testEnsureStartsBothPoolsAndMarksReadyOnce(): void {
		$this->assertFalse(ClusterPool::ready());
		$this->assertTrue(ClusterPool::ensure(0.0));
		$this->assertSame(['start cluster_ctl', 'start cluster_ingest'], $this->takeCalls());
		$this->assertTrue(ClusterPool::ready());
		$this->assertStringContainsString('pm.max_children = 28', $this->conf('cluster_ctl'));
		$this->assertStringContainsString('pm.max_children = 20', $this->conf('cluster_ingest'));
		$this->assertSame(1800000000000, ClusterMeta::readyAtMs(), "the silence clock restarts when MAIN's API is back");

		// Nothing changed: nothing is written, started or reloaded, and the
		// nodes' silence clock is left alone.
		$rMtime = filemtime($this->rBase . 'bin/php/etc/cluster/cluster_ctl.conf');
		ClusterClock::fix(1800000060000);
		$this->assertTrue(ClusterPool::ensure(0.0));
		$this->assertSame([], $this->takeCalls());
		$this->assertSame($rMtime, filemtime($this->rBase . 'bin/php/etc/cluster/cluster_ctl.conf'));
		$this->assertSame(1800000000000, ClusterMeta::readyAtMs());
	}

	public function testANewSizeReloadsOnlyThatPool(): void {
		ClusterPool::ensure(0.0);
		$this->takeCalls();
		$this->rDb->exec('INSERT INTO `servers` (`id`, `is_main`, `server_type`) VALUES (5, 0, 0)');
		// The reloading pool does not answer (the fake): FPM lets a held
		// long-poll run for up to process_control_timeout before it re-executes.
		$this->assertTrue(ClusterPool::ensure(0.0));
		$this->assertSame(['reload cluster_ctl'], $this->takeCalls());
		$this->assertStringContainsString('pm.max_children = 30', $this->conf('cluster_ctl'));
		$this->assertTrue(ClusterPool::ready(), 'a reload keeps the API serving: its socket stays open, requests queue on it');
		$this->assertSame(1800000000000, ClusterMeta::readyAtMs(), "and leaves the nodes' silence clock alone");
	}

	public function testEnsureRunsOnlyAsThePoolUser(): void {
		// As root it would follow a link the pool user planted among its files.
		ClusterPool::useBase($this->rBase, 'xcvm-no-such-user');
		$this->assertFalse(ClusterPool::ensure(0.0));
		$this->assertSame([], $this->rCalls, 'nothing started');
		$this->assertDirectoryDoesNotExist($this->rBase . 'bin/php/etc/cluster', 'nothing written');
		$this->assertFalse(ClusterPool::ready());
	}

	public function testNoMarkerUntilBothPoolsAnswer(): void {
		$this->rWorks['cluster_ingest'] = false;
		$this->assertFalse(ClusterPool::ensure(0.0));
		$this->assertFalse(ClusterPool::ready());
		$this->assertSame(0, ClusterMeta::readyAtMs());

		$this->rWorks['cluster_ingest'] = true;
		$this->assertTrue(ClusterPool::ensure(0.0), 'the next pass finds it answering');
		$this->assertTrue(ClusterPool::ready());
	}

	public function testADeadPoolIsNotReadyUntilRestarted(): void {
		ClusterPool::ensure(0.0);
		$this->takeCalls();
		$this->rAlive['cluster_ingest'] = false;
		$this->rWorks['cluster_ingest'] = false;
		$this->assertFalse(ClusterPool::ensure(0.0));
		$this->assertSame(['start cluster_ingest'], $this->takeCalls());
		$this->assertFalse(ClusterPool::ready(), 'agents back off instead of piling onto the panel pool');

		$this->rWorks['cluster_ingest'] = true;
		ClusterClock::fix(1800000120000);
		$this->assertTrue(ClusterPool::ensure(0.0));
		$this->assertTrue(ClusterPool::ready());
		$this->assertSame(1800000120000, ClusterMeta::readyAtMs(), 'the time MAIN was STARTING never counts against a node');
	}

	public function testTheApiIsStartingUntilReady(): void {
		$rCrypto = new FakeClusterCrypto();
		$rNonce = str_repeat('ab', 16);
		$rUuid = '0f8fad5b-d9cb-469f-a165-70867728950e';
		$rReq = ['method' => 'POST', 'path' => '/cluster/v1/heartbeat', 'headers' => [
			'X-XCVM-Proto' => '1', 'X-XCVM-Node' => $rUuid, 'X-XCVM-Epoch' => '1',
			'X-XCVM-Ts' => '1800000000000', 'X-XCVM-Nonce' => $rNonce, 'X-XCVM-Sig' => str_repeat('0', 64),
		]];

		$rRes = ClusterPool::gate($rCrypto, $rReq);
		$this->assertNotNull($rRes);
		$this->assertSame(503, $rRes['status']);
		$rSig = Enc::b64urlDecode($rRes['headers']['X-XCVM-Panel-Sig']);
		$this->assertTrue(PanelSig::verify($rCrypto->info()['panel_sign_pub'], 'den', $rRes['body'], (string) $rSig), 'panel-signed');
		$rDoc = json_decode($rRes['body'], true);
		$this->assertSame('STARTING', $rDoc['reason']);
		$this->assertSame($rUuid, $rDoc['node'], 'bound to the node');
		$this->assertSame($rNonce, $rDoc['req_nonce'], 'and to the request');
		$this->assertSame(ClusterPool::RETRY_AFTER_MS, $rDoc['retry_after_ms']);

		$rChallenge = ClusterPool::gate($rCrypto, ['method' => 'GET', 'path' => '/cluster/v1/challenge', 'headers' => []]);
		$this->assertSame(503, $rChallenge['status'] ?? null, 'unauthenticated ops wait too');
		$this->assertNull(json_decode($rChallenge['body'], true)['node']);
		$this->assertNull(ClusterPool::gate($rCrypto, ['method' => 'GET', 'path' => '/cluster/v1/health', 'headers' => []]), 'health needs neither the database nor the pools');

		ClusterPool::ensure(0.0);
		$this->assertNull(ClusterPool::gate($rCrypto, $rReq), 'served once both pools answer');
	}

	public function testEveryOpHasALaneAndNginxRoutesTheIngestOnes(): void {
		$rServed = array_keys((new ReflectionClassConstant(ClusterApi::class, 'OPS'))->getValue());
		foreach ($rServed as $rOp) {
			$this->assertContains($rOp, array_merge(ClusterPool::CTL_OPS, ClusterPool::INGEST_OPS), $rOp . ' needs a lane');
		}
		$this->assertSame([], array_intersect(ClusterPool::CTL_OPS, ClusterPool::INGEST_OPS));
		$this->assertCount(24, array_merge(ClusterPool::CTL_OPS, ClusterPool::INGEST_OPS), "the plan's 24 ops");
		$this->assertSame('cluster_ctl', ClusterPool::poolFor('commands'), 'the long-poll');
		$this->assertSame('cluster_ctl', ClusterPool::poolFor('heartbeat'));
		$this->assertSame('cluster_ingest', ClusterPool::poolFor('events'));
		$this->assertSame('cluster_ctl', ClusterPool::poolFor('no_such_op'), 'refused on the control pool');

		$rConf = (string) file_get_contents(dirname(__DIR__, 2) . '/src/bin/nginx/conf/nginx.conf');
		$this->assertMatchesRegularExpression('#location \^~ /cluster/v1/ \{[^}]*fastcgi_pass cluster_ctl;#', $rConf);
		$this->assertMatchesRegularExpression('#location ~ \^/cluster/v1/\(([a-z_|]+)\)\$ \{\s*fastcgi_pass cluster_ingest;#', $rConf);
		preg_match('#location ~ \^/cluster/v1/\(([a-z_|]+)\)\$ \{#', $rConf, $rMatch);
		$rRouted = explode('|', $rMatch[1]);
		sort($rRouted);
		$rIngest = ClusterPool::INGEST_OPS;
		sort($rIngest);
		$this->assertSame($rIngest, $rRouted, "nginx.conf's ingest location lists INGEST_OPS");

		foreach (array_keys(ClusterPool::POOLS) as $rPool) {
			$this->assertMatchesRegularExpression('#upstream ' . $rPool . ' \{\s*server unix:' . preg_quote(ClusterPool::socket($rPool, '/home/xc_vm/'), '#') . ' max_fails=0;\s*server unix:/home/xc_vm/bin/php/sockets/1\.sock backup;\s*\}#', $rConf, $rPool . ': its own socket, never taken out of rotation; the panel pool for a request that cannot reach it');
		}
	}

	/**
	 * What keeps the API STARTING until the pools answer lives outside
	 * ClusterPool: the service, the gate's place in the API's entry point, and
	 * who runs ensure(), as whom.
	 */
	public function testTheCallSitesKeepTheApiStartingUntilThePoolsAnswer(): void {
		$rSrc = dirname(__DIR__, 2) . '/src/';

		$this->assertMatchesRegularExpression('#^boot\(\) \{[^}]*^  rm -f \$SCRIPT/tmp/cluster_ready$[^}]*console\.php startup$#m', (string) file_get_contents($rSrc . 'service'), 'boot() removes the marker before startup runs status');
		$this->assertMatchesRegularExpression('#function start\(\): int \{.*?ClusterPool::unmark\(\);.*?function stop\(#s', (string) file_get_contents($rSrc . 'Cli/Commands/ServiceCommand.php'), 'and so does the service command');

		$rIndex = (string) file_get_contents($rSrc . 'Public/cluster/index.php');
		$rOrder = [];
		foreach (['ClusterCryptoFactory::create()', 'ClusterPool::gate($rCrypto, $rReq)', "=== '/cluster/v1/health'", 'db_connect('] as $rStep) {
			$rAt = strpos($rIndex, $rStep);
			$this->assertNotFalse($rAt, $rStep);
			$rOrder[] = $rAt;
		}
		$rSorted = $rOrder;
		sort($rSorted);
		$this->assertSame($rSorted, $rOrder, 'the gate needs the crypto to sign, and comes before health and the database');

		$rStatus = (string) file_get_contents($rSrc . 'Cli/Commands/StatusCommand.php');
		$this->assertMatchesRegularExpression('#if \(\$rServers\[SERVER_ID\]\[.is_main.\]\) \{[^}]*\$this->ensureClusterPools\(\);#', $rStatus, 'MAIN only');
		$this->assertStringContainsString("'sudo -u xc_vm ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php cluster:pools'", $rStatus, 'status runs as root, the pools as xc_vm');
		$this->assertStringNotContainsString('ClusterPool::ensure(', $rStatus);
		$this->assertMatchesRegularExpression('#if \(\$rServers\[SERVER_ID\]\[.is_main.\] && class_exists\(ClusterPool::class\)\) \{\s*ClusterPool::ensure\(#', (string) file_get_contents($rSrc . 'Cli/CronJobs/ServersCronJob.php'), 'cron:servers, as xc_vm, on MAIN only');
	}

	/** The ping over a pool's socket: FPM's response, and nothing else, is an answer. */
	public function testThePingReadsFastCgiRecords(): void {
		ClusterPool::useProcs(null);
		$rScript = $this->rBase . 'responder.php';
		file_put_contents($rScript, self::RESPONDER);
		$rSocket = ClusterPool::socket('cluster_ctl', $this->rBase);
		mkdir(dirname($rSocket), 0777, true);
		$rNull = ['file', '/dev/null', 'w'];
		$this->rResponder = proc_open([PHP_BINARY, '-n', $rScript, $rSocket, 'pong', 'other', 'cut', 'split'], [0 => ['file', '/dev/null', 'r'], 1 => $rNull, 2 => $rNull], $rPipes);
		for ($i = 0; $i < 250 && !file_exists($rSocket); $i++) {
			usleep(20000);
		}
		$this->assertFileExists($rSocket, 'the responder listens');

		$this->assertTrue(ClusterPool::answers('cluster_ctl'), "FPM's ping response");
		$this->assertFalse(ClusterPool::answers('cluster_ctl'), 'another body is not the ping');
		$this->assertFalse(ClusterPool::answers('cluster_ctl'), 'a reply cut before END_REQUEST');
		$this->assertTrue(ClusterPool::answers('cluster_ctl'), 'padded records, STDERR, a body over two records, split reads');
		$this->assertFalse(ClusterPool::answers('cluster_ingest'), 'no socket');
	}

	/** A pool's master is the FPM master started with its own config, never a panel pool's. */
	public function testAMasterIsFoundByItsOwnConfig(): void {
		ClusterPool::useProcs(null);
		$rProc = $this->rBase . 'proc/';
		ClusterPool::useProcRoot(rtrim($rProc, '/'));
		$rRun = static function (int $rPid, string $rTitle) use ($rProc): void {
			mkdir($rProc . $rPid, 0777, true);
			// FPM writes its title over its argv.
			file_put_contents($rProc . $rPid . '/cmdline', $rTitle . str_repeat("\0", 8));
		};
		$rConf = $this->rBase . 'bin/php/etc/cluster/cluster_ctl.conf';
		$rRun(9000001, 'php-fpm: master process (/home/xc_vm/bin/php/etc/1.conf)');
		$rRun(9000002, 'php-fpm: pool cluster_ctl');
		$rRun(9000003, 'php-fpm: master process (' . $rConf . '.tmp)');
		$this->assertFalse(ClusterPool::alive('cluster_ctl'), "a panel pool's master, a worker and another config");

		$rRun(9000004, 'php-fpm: master process (' . $rConf . ')');
		$this->assertTrue(ClusterPool::alive('cluster_ctl'));
		$this->assertFalse(ClusterPool::alive('cluster_ingest'));
	}

	/**
	 * The rendered configs against a real php-fpm, beside another FPM master
	 * as the panel's pools run on MAIN: ensure() starts the pool that is down
	 * through bin/php/sbin/php-fpm, reloads the one whose size changed, and
	 * both answer the FastCGI ping.
	 */
	public function testRealPhpFpm(): void {
		$rFpm = (string) getenv('XCVM_TEST_FPM');
		if ($rFpm === '' || !is_executable($rFpm)) {
			$this->markTestSkipped('no php-fpm (set XCVM_TEST_FPM)');
		}
		ClusterPool::useProcs(null);
		$rUser = self::user();
		foreach (['bin/php/etc/cluster', 'bin/php/sockets', 'bin/php/var/run', 'bin/php/sbin'] as $rDir) {
			mkdir($this->rBase . $rDir, 0777, true);
		}
		// bin/php/sbin/php-fpm: the given binary, without a php.ini, allowed to run as root.
		$rBin = $this->rBase . 'bin/php/sbin/php-fpm';
		file_put_contents($rBin, "#!/bin/sh\nexec " . escapeshellarg($rFpm) . " -n -R \"\$@\"\n");
		chmod($rBin, 0755);
		$rRunDir = $this->rBase . 'bin/php/var/run/';
		$rConf = $this->rBase . 'bin/php/etc/cluster/cluster_ctl.conf';
		file_put_contents($rConf, ClusterPool::render('cluster_ctl', 28, $this->rBase, $rUser));
		exec(escapeshellarg($rBin) . ' -t -y ' . escapeshellarg($rConf) . ' 2>&1', $rOut, $rCode);
		$this->assertSame(0, $rCode, implode("\n", $rOut));

		// A decoy master, as a panel pool, and the control pool, so ensure()
		// must find the ingest pool down.
		$rDecoy = $this->rBase . 'bin/php/etc/1.conf';
		file_put_contents($rDecoy, str_replace('cluster_ctl', 'decoy', ClusterPool::render('cluster_ctl', 2, $this->rBase, $rUser)));
		foreach ([$rDecoy => 'decoy', $rConf => 'cluster_ctl'] as $rFile => $rName) {
			exec(escapeshellarg($rBin) . ' -y ' . escapeshellarg($rFile) . ' 2>&1', $rOut, $rCode);
			$this->assertSame(0, $rCode, implode("\n", $rOut));
			for ($i = 0; $i < 50 && !file_exists($rRunDir . $rName . '.pid'); $i++) {
				usleep(100000);
			}
		}
		$rCtlPid = (int) file_get_contents($rRunDir . 'cluster_ctl.pid');
		$this->assertTrue(ClusterPool::alive('cluster_ctl'));
		$this->assertFalse(ClusterPool::alive('cluster_ingest'), 'the decoy is no cluster pool');
		$this->assertTrue(ClusterPool::answers('cluster_ctl'), 'FPM answers the FastCGI ping');
		$this->assertFalse(ClusterPool::answers('cluster_ingest'));

		// A new size: the control pool reloads, the ingest pool starts.
		$this->rDb->exec('INSERT INTO `servers` (`id`, `is_main`, `server_type`) VALUES (5, 0, 0)');
		$this->assertTrue(ClusterPool::ensure(5.0), 'both pools answer');
		$this->assertTrue(ClusterPool::ready());
		$this->assertTrue(ClusterPool::alive('cluster_ingest'));
		$this->assertStringContainsString('pm.max_children = 30', (string) file_get_contents($rConf));
		for ($i = 0; $i < 50 && (int) @file_get_contents($rRunDir . 'cluster_ctl.pid') === $rCtlPid; $i++) {
			usleep(100000);
		}
		$this->assertNotSame($rCtlPid, (int) @file_get_contents($rRunDir . 'cluster_ctl.pid'), 'reloaded: FPM re-executes itself on SIGUSR2');
		$this->assertTrue(ClusterPool::answers('cluster_ctl'));
	}
}
