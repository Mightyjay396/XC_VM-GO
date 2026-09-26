<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\ClusterMeta;
use XcVm\Domain\Cluster\ClusterPool;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * MAIN's cluster FPM pools (plan §3, "Transport"): `cluster_ctl` and
 * `cluster_ingest`, each sized by the plan's formula; ensure() writes,
 * starts and reloads them only when needed, and marks the API ready only
 * once both answer.
 *
 * The pools' processes are faked here; testRealPhpFpm runs a real php-fpm
 * when one is available (XCVM_TEST_FPM=/path/to/php-fpm, or php-fpm on PATH).
 */
final class ClusterPoolTest extends TestCase {
	private TestDb $rDb;

	private string $rBase;

	/** @var list<string> "<action> <pool>", in call order */
	private array $rCalls = [];

	/** @var array<string, bool> pool => its master runs */
	private array $rAlive = [];

	/** @var array<string, bool> pool => once running, it answers */
	private array $rWorks = ['cluster_ctl' => true, 'cluster_ingest' => true];

	private ?int $rFpmPid = null;

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
		ClusterPool::useBase($this->rBase);
		ClusterPool::useProcs(function (string $rAction, string $rPool): bool {
			$this->rCalls[] = $rAction . ' ' . $rPool;
			switch ($rAction) {
				case 'start':
					$this->rAlive[$rPool] = true;
					return true;
				case 'answers':
					return ($this->rAlive[$rPool] ?? false) && $this->rWorks[$rPool];
				case 'alive':
					return $this->rAlive[$rPool] ?? false;
				default:
					return true;
			}
		});
	}

	protected function tearDown(): void {
		if ($this->rFpmPid !== null) {
			posix_kill($this->rFpmPid, SIGTERM);
		}
		ClusterPool::useProcs(null);
		ClusterPool::useBase(null);
		ClusterClock::fix(null);
		DatabaseFactory::reset();
		exec('rm -rf ' . escapeshellarg($this->rBase));
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
		$this->assertTrue(ClusterPool::ensure(0.0));
		$this->assertSame(['reload cluster_ctl'], $this->takeCalls());
		$this->assertStringContainsString('pm.max_children = 30', $this->conf('cluster_ctl'));
		$this->assertTrue(ClusterPool::ready(), 'a reload keeps the API serving');
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

	/**
	 * The rendered configs against a real php-fpm: it starts them, answers the
	 * FastCGI ping, and a new size reloads it in place.
	 */
	public function testRealPhpFpm(): void {
		$rFpm = (string) (getenv('XCVM_TEST_FPM') ?: trim((string) shell_exec('command -v php-fpm 2>/dev/null')));
		if ($rFpm === '' || !is_executable($rFpm)) {
			$this->markTestSkipped('no php-fpm (set XCVM_TEST_FPM)');
		}
		ClusterPool::useProcs(null);
		$rUser = (string) (posix_getpwuid(posix_geteuid())['name'] ?? '');
		ClusterPool::useBase($this->rBase, $rUser);
		foreach (['bin/php/etc/cluster', 'bin/php/sockets', 'bin/php/var/run'] as $rDir) {
			mkdir($this->rBase . $rDir, 0777, true);
		}
		$rConf = $this->rBase . 'bin/php/etc/cluster/cluster_ctl.conf';
		file_put_contents($rConf, ClusterPool::render('cluster_ctl', 28, $this->rBase, $rUser));
		exec(escapeshellarg($rFpm) . ' -n -t -y ' . escapeshellarg($rConf) . ' -R 2>&1', $rOut, $rCode);
		$this->assertSame(0, $rCode, implode("\n", $rOut));

		// Run only the control pool, so ensure() must find the ingest pool down.
		exec(escapeshellarg($rFpm) . ' -n -y ' . escapeshellarg($rConf) . ' -R 2>&1', $rOut, $rCode);
		$this->assertSame(0, $rCode, implode("\n", $rOut));
		for ($i = 0; $i < 50 && !file_exists($this->rBase . 'bin/php/var/run/cluster_ctl.pid'); $i++) {
			usleep(100000);
		}
		$this->rFpmPid = (int) file_get_contents($this->rBase . 'bin/php/var/run/cluster_ctl.pid');
		$this->assertTrue(ClusterPool::answers('cluster_ctl'), 'FPM answers the FastCGI ping');
		$this->assertFalse(ClusterPool::answers('cluster_ingest'));

		// A new size reloads the running pool, which answers again.
		$this->rDb->exec('INSERT INTO `servers` (`id`, `is_main`, `server_type`) VALUES (5, 0, 0)');
		$this->assertFalse(ClusterPool::ensure(1.0), 'the ingest pool cannot start without bin/php/sbin/php-fpm');
		$this->assertFalse(ClusterPool::ready());
		for ($i = 0; $i < 50 && (int) @file_get_contents($this->rBase . 'bin/php/var/run/cluster_ctl.pid') === $this->rFpmPid; $i++) {
			usleep(100000);
		}
		$this->rFpmPid = (int) file_get_contents($this->rBase . 'bin/php/var/run/cluster_ctl.pid');
		$this->assertStringContainsString('pm.max_children = 30', (string) file_get_contents($rConf));
		$rAnswered = false;
		for ($i = 0; $i < 50 && !$rAnswered; $i++) {
			$rAnswered = ClusterPool::answers('cluster_ctl');
			usleep($rAnswered ? 0 : 100000);
		}
		$this->assertTrue($rAnswered, 'reloaded in place');
	}
}
