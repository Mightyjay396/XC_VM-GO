<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\SignalsCommand;
use XcVm\Core\Cluster\Crypto\Enc;
use XcVm\Core\Cluster\EventSpool;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Tests\Support\AgentUser;
use XcVm\Tests\Support\ReplicaFixture;

/**
 * The signals daemon on a node in mode 2 (api), which has no database or
 * Redis of MAIN's (cluster plan, section 10; ADR 0004, fourteenth Phase 7
 * increment): it reads no `signals` row and no Redis signal (kills come as
 * `conn.kill_worker` and `conn.drop`, cache jobs as `node.cache`, which
 * cluster:exec runs, and the node runs its own where they are queued).
 *
 * Each path runs in a child PHP booted for real from the node replica, in a
 * throwaway deploy root, as ModeTwoPathsTest does: an xcvm_core stand-in logs
 * every connect it is asked for, the refusal (ConnectAudit) counts every
 * attempt, and `sudo`, `openssl`, `chown` and `crontab` are stand-ins first
 * on the child's PATH (the child checks they answer before it boots), as are
 * the deploy root's nginx and PHP binaries. `sudo certbot` answers what the
 * test says; `openssl` prints the fake certificate file it is given.
 */
final class ModeTwoSignalsCertbotTest extends TestCase {
	private string $rHome;

	private ReplicaFixture $rFixture;

	/** @var resource|null a process whose command line reads as nginx's master */
	private $rNginx = null;

	protected function setUp(): void {
		$this->rHome = sys_get_temp_dir() . '/xcvm-mode2-sc-' . bin2hex(random_bytes(4)) . '/';
		foreach (['config/cluster', 'tmp/cache', 'tmp/crons', 'tmp/logs', 'tmp/opened_cons', 'bin/nginx/conf', 'bin/nginx/sbin', 'bin/nginx_rtmp/sbin', 'bin/certbot/config/live/node.example', 'content/vod', 'storage', 'stub'] as $rDir) {
			mkdir($this->rHome . $rDir, 0777, true);
		}
		// As root, a node's audits and its kept state write as the owner of config/cluster/.
		AgentUser::own($this->rHome);
		$rLog = escapeshellarg($this->rHome . 'commands.log');
		$rStubs = [
			// certbot's dry run passes; the real run says what certbot.out holds.
			'sudo' => "echo \"sudo \$*\" >> " . $rLog . "\ncase \"\$*\" in\n\t*certbot*--dry-run*) echo 'The dry run was successful.' ;;\n\t*certbot*) cat " . escapeshellarg($this->rHome . 'certbot.out') . " 2>/dev/null ;;\nesac\n",
			// The certificate's fields, as `openssl x509 -serial -enddate -subject` prints them: the fake file holds them.
			'openssl' => "for rArg in \"\$@\"; do rLast=\$rArg; done\n[ -f \"\$rLast\" ] || exit 1\ncat \"\$rLast\"\n",
			'chown' => "echo \"chown \$*\" >> " . $rLog . "\n",
			// The boot writes the node's crontab: never this machine's.
			'crontab' => "echo \"crontab \$*\" >> " . escapeshellarg($this->rHome . 'crontab.log') . "\n",
		];
		foreach ($rStubs as $rTool => $rBody) {
			file_put_contents($this->rHome . 'stub/' . $rTool, "#!/bin/sh\n" . $rBody . "exit 0\n");
			chmod($this->rHome . 'stub/' . $rTool, 0755);
		}
		// The deploy root's own binaries: its nginx and the PHP its commands start.
		foreach (['nginx/sbin/nginx', 'nginx_rtmp/sbin/nginx_rtmp', 'php/bin/php'] as $rBinary) {
			@mkdir(dirname($this->rHome . 'bin/' . $rBinary), 0777, true);
			file_put_contents($this->rHome . 'bin/' . $rBinary, "#!/bin/sh\necho \"" . basename($rBinary) . " \$*\" >> " . $rLog . "\nexit 0\n");
			chmod($this->rHome . 'bin/' . $rBinary, 0755);
		}
		$this->rFixture = new ReplicaFixture($this->rHome . 'config/cluster/');
	}

	protected function tearDown(): void {
		if (is_resource($this->rNginx)) {
			proc_terminate($this->rNginx, 9);
			proc_close($this->rNginx);
		}
		NodeFlows::usePath(null);
		NodeRole::useMainBuild(null);
		EventSpool::useDir(null);
		exec('rm -rf ' . escapeshellarg($this->rHome));
	}

	private function flows(?int $rMode, int $rFlows = 255, string $rState = 'active'): void {
		$rFile = $this->rHome . 'config/cluster/flows.json';
		@unlink($rFile);
		if ($rMode !== null) {
			file_put_contents($rFile, json_encode(['mode' => $rMode, 'flows' => $rFlows, 'state' => $rState]));
		}
	}

	/** The same flows for this process, read again at once (NodeFlows keeps what it read for 5 s). */
	private function here(?int $rMode, int $rFlows = 255, string $rState = 'active'): void {
		$this->flows($rMode, $rFlows, $rState);
		NodeFlows::usePath($this->rHome . 'config/cluster/flows.json');
		NodeRole::useMainBuild(false);
	}

	/**
	 * A node in mode 2 with every flow, its replica applied from disk as
	 * `service` does at boot; its own row says HTTPS on for node.example.
	 *
	 * @param array<string, string|null> $rSettings MAIN's settings the replica carries
	 */
	private function node(array $rSettings = []): void {
		$this->flows(2);
		$this->rFixture->node($rSettings);
		$rNode = json_decode((string) file_get_contents($this->rFixture->dir() . 'node.json'), true)['data'];
		$this->rFixture->whole('node', ['enable_https' => 1, 'domain_name' => 'node.example,192.0.2.5'] + $rNode);
		[$rCode, $rOut] = $this->child(['cluster:apply', '--from-disk'], dirname(__DIR__, 2) . '/src/console.php');
		$this->assertSame(0, $rCode, $rOut);
		@unlink($this->rHome . 'commands.log');
	}

	/**
	 * Run $rScript (the scenario script by default) in the throwaway deploy root.
	 *
	 * @param list<string> $rArgs
	 * @return array{0: int, 1: string, 2: array<string, mixed>|null} exit code, output, the scenario's result
	 */
	private function child(array $rArgs, ?string $rScript = null, ?string $rStdin = null): array {
		$rPrepend = $this->rHome . 'prepend.php';
		file_put_contents($rPrepend, <<<'PHP'
			<?php
			// A throwaway deploy root, and an xcvm_core whose MAIN database and Redis never answer.
			define('MAIN_HOME', getenv('XCVM_TEST_HOME'));
			final class XC_VM {
				public static function config_server(): array {
					return ['server_id' => 5];
				}

				public static function db_connect(bool $rMigrate = false) {
					file_put_contents(MAIN_HOME . 'connects.log', "sql\n", FILE_APPEND);
					return false;
				}

				public static function redis_connect() {
					file_put_contents(MAIN_HOME . 'connects.log', "redis\n", FILE_APPEND);
					return null;
				}
			}
			PHP);
		@unlink($this->rHome . 'connects.log');
		$rIn = $this->rHome . 'stdin';
		file_put_contents($rIn, $rStdin ?? '');
		$rCommand = array_merge([PHP_BINARY, '-d', 'auto_prepend_file=' . $rPrepend, $rScript ?? $this->scenarios()], $rArgs);
		$rProc = proc_open($rCommand, [0 => ['file', $rIn, 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes, $this->rHome, ['XCVM_TEST_HOME' => $this->rHome, 'XCVM_TEST_SRC' => dirname(__DIR__, 2) . '/src/', 'PATH' => $this->rHome . 'stub:' . getenv('PATH')]);
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = (string) stream_get_contents($rPipes[2]);
		fclose($rPipes[1]);
		fclose($rPipes[2]);
		$rCode = proc_close($rProc);
		$rResult = null;
		if (preg_match('/^RESULT:(.*)$/m', $rOut, $rMatch)) {
			$rResult = json_decode($rMatch[1], true);
		}
		return [$rCode, $rOut . $rErr, $rResult];
	}

	/** The scenario script: boots as a CLI command (from the replica), then runs one path. */
	private function scenarios(): string {
		$rScript = $this->rHome . 'scenario.php';
		file_put_contents($rScript, <<<'PHP'
			<?php
			namespace {
				use XcVm\Cli\Commands\SignalsCommand;
				use XcVm\Core\Cluster\ReplicaBoot;
				use XcVm\Core\Cluster\SignalDispatcher;
				use XcVm\Core\Enum\BootContext;

				// Nothing runs unless the stand-ins answer for sudo, openssl, chown and crontab.
				foreach (['sudo', 'openssl', 'chown', 'crontab'] as $rTool) {
					if (trim((string) shell_exec('command -v ' . $rTool)) !== getenv('XCVM_TEST_HOME') . 'stub/' . $rTool) {
						echo "\nRESULT:" . json_encode(['error' => 'unsafe: ' . $rTool . ' is not the stand-in']) . "\n";
						exit(1);
					}
				}
				require getenv('XCVM_TEST_SRC') . 'bootstrap.php';
				$rResult = [];
				register_shutdown_function(static function () use (&$rResult): void {
					echo "\nRESULT:" . json_encode($rResult) . "\n";
				});
				try {
					XC_Bootstrap::boot(BootContext::Cli);
					$rResult['replica'] = ReplicaBoot::active();
					switch ($argv[1] ?? '') {
						case 'signals':
							$rDaemon = new class extends SignalsCommand {
								public int $rRestarts = 0;

								protected function assertRunAsXcVm(): bool {
									return true;
								}

								protected function acquireDaemonLock(string $rLockName): bool {
									return true;
								}

								protected function killStaleProcesses(string $rPattern): void {
								}

								protected function setProcessTitle(string $rTitle): void {
								}

								protected function restartDaemon(string $rCommandName): void {
									$this->rRestarts++;
								}
							};
							ob_start();
							try {
								$rResult['code'] = $rDaemon->execute([]);
							} finally {
								$rResult['output'] = (string) ob_get_clean();
							}
							$rResult['restarts'] = $rDaemon->rRestarts;
							break;
						case 'own_jobs':
							// A job the node queues for itself (a movie stopped here, a closed connection).
							$rResult['queued'] = [
								SignalDispatcher::cache(5, ['type' => 'delete_vod', 'id' => 7]),
								SignalDispatcher::cacheBatch(5, [['type' => 'delete_con', 'uuid' => str_repeat('ab', 16)], ['type' => 'delete_con', 'uuid' => '../../config/cluster/agent.json'], ['type' => 'update_line', 'id' => 3]]),
							];
							break;
					}
				} catch (\Throwable $e) {
					$rResult['error'] = get_class($e) . ': ' . $e->getMessage();
				}
			}
			PHP);
		return $rScript;
	}

	/**
	 * No connect to MAIN: xcvm_core was never asked for one, and none was
	 * even attempted (the refusal counts each attempt, refused or not).
	 */
	private function assertNoConnect(): void {
		$this->assertSame([], is_file($this->rHome . 'connects.log') ? file($this->rHome . 'connects.log', FILE_IGNORE_NEW_LINES) : [], 'xcvm_core was never asked to connect');
		$rAttempts = 0;
		foreach (glob($this->rHome . 'storage/cluster/sql_audit/*.json') ?: [] as $rFile) {
			$rDay = json_decode((string) file_get_contents($rFile), true);
			$rAttempts += (int) ($rDay['sql'] ?? 0) + (int) ($rDay['redis'] ?? 0);
		}
		$this->assertSame(0, $rAttempts, 'no connect attempted, refused or not');
	}

	/** @return list<string> what the stand-ins were asked, in order */
	private function commands(): array {
		return is_file($this->rHome . 'commands.log') ? file($this->rHome . 'commands.log', FILE_IGNORE_NEW_LINES) : [];
	}

	/** @return list<array<string, mixed>> the spooled events of a lane, in file order */
	private function spooled(string $rLane): array {
		$rFiles = glob($this->rHome . 'config/cluster/spool/' . $rLane . '/*.ndjson') ?: [];
		sort($rFiles);
		$rOut = [];
		foreach ($rFiles as $rFile) {
			foreach (array_filter(explode("\n", (string) file_get_contents($rFile))) as $rLine) {
				$rOut[] = json_decode($rLine, true);
			}
		}
		return $rOut;
	}

	/** A process whose command line reads as nginx's master (ProcessManager::isNginxRunning). */
	private function nginxRunning(): void {
		$this->rNginx = proc_open([PHP_BINARY, '-r', 'sleep(60);', '--', 'nginx: master process'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $rPipes);
		$this->assertIsResource($this->rNginx);
		for ($i = 0; $i < 50 && !str_contains((string) @file_get_contents('/proc/' . proc_get_status($this->rNginx)['pid'] . '/cmdline'), 'nginx: master'); $i++) {
			usleep(20000);
		}
	}

	// ── The signals daemon ───────────────────────────────────────────

	/**
	 * One pass of the daemon, with Redis on in the settings: no ping of
	 * MAIN's database, no Redis, no `signals` row read; it refreshes its
	 * settings and servers from the replica and ends the pass, as a pass
	 * always did, for the next one to start.
	 */
	public function testTheSignalsDaemonPassNeedsNeitherDatabaseNorRedis(): void {
		$this->node(['redis_handler' => '1']);
		$this->nginxRunning();
		[, $rOut, $rResult] = $this->child(['signals']);
		$this->assertIsArray($rResult, $rOut);
		$this->assertArrayNotHasKey('error', $rResult, $rOut);
		$this->assertTrue($rResult['replica']);
		$this->assertNoConnect();
		$this->assertSame([0, 1], [$rResult['code'], $rResult['restarts']], $rResult['output']);
		$this->assertStringNotContainsString('Not running', $rResult['output'], 'the pass reached its settings refresh');
		$this->assertStringNotContainsString('Redis', $rResult['output']);
	}

	/**
	 * Cache jobs reach a node in mode 2 as a signed `node.cache` command,
	 * which cluster:exec runs as the daemon ran the rows: a closed viewer's
	 * connection file and a deleted movie's files go, the rest stays.
	 */
	public function testCacheJobsRunFromANodeCacheCommand(): void {
		$this->node();
		$rUUID = str_repeat('ab', 16);
		touch($this->rHome . 'tmp/opened_cons/' . $rUUID);
		touch($this->rHome . 'tmp/opened_cons/' . str_repeat('cd', 16));
		foreach (['7.mp4', '8.mkv', '8.srt', '9.ts'] as $rFile) {
			touch($this->rHome . 'content/vod/' . $rFile);
		}
		$rDoc = (string) json_encode(['v' => 1, 'type' => 'node.cache', 'exp' => time() + 600, 'iat' => time(), 'cmd_id' => bin2hex(random_bytes(16)), 'seq' => 1, 'node_uuid' => $this->rFixture->rUuid, 'gen' => 1, 'dedupe_key' => null, 'args' => ['jobs' => [
			['type' => 'delete_con', 'uuid' => $rUUID],
			['type' => 'delete_vod', 'id' => 7],
			['type' => 'delete_vods', 'id' => [8]],
			['type' => 'drop_con', 'uuid' => 'no-fanout-here'],
		]]], JSON_UNESCAPED_SLASHES);
		$rIn = (string) json_encode(['doc' => $rDoc, 'sig' => Enc::b64url($this->rFixture->rCrypto->sign('cmd', $rDoc))]);
		[$rCode, $rOut] = $this->child(['cluster:exec'], dirname(__DIR__, 2) . '/src/console.php', $rIn);
		$this->assertSame(0, $rCode, $rOut);
		$this->assertSame(['result' => true, 'jobs' => 4], json_decode(trim($rOut), true), $rOut);
		$this->assertNoConnect();
		$this->assertFileDoesNotExist($this->rHome . 'tmp/opened_cons/' . $rUUID);
		$this->assertFileExists($this->rHome . 'tmp/opened_cons/' . str_repeat('cd', 16));
		$this->assertSame(['9.ts'], array_values(array_diff(scandir($this->rHome . 'content/vod/'), ['.', '..'])));
	}

	/**
	 * The jobs a node in mode 2 queues for itself (a movie it stopped, a
	 * connection it closed) would be rows its daemon never reads, refused
	 * at their write: they run where they are queued, only in the form
	 * MAIN sends, and make no connect. A cache rebuild (cron:cache_engine,
	 * MAIN's) is not started.
	 */
	public function testANodesOwnCacheJobsRunWhereTheyAreQueued(): void {
		$this->node();
		touch($this->rHome . 'tmp/opened_cons/' . str_repeat('ab', 16));
		touch($this->rHome . 'content/vod/7.mp4');
		touch($this->rHome . 'content/vod/8.mp4');
		[, $rOut, $rResult] = $this->child(['own_jobs']);
		$this->assertIsArray($rResult, $rOut);
		$this->assertArrayNotHasKey('error', $rResult, $rOut);
		$this->assertNoConnect();
		$this->assertSame([true, true], $rResult['queued']);
		$this->assertFileDoesNotExist($this->rHome . 'content/vod/7.mp4');
		$this->assertFileExists($this->rHome . 'content/vod/8.mp4');
		$this->assertFileDoesNotExist($this->rHome . 'tmp/opened_cons/' . str_repeat('ab', 16));
		$this->assertFileExists($this->rHome . 'config/cluster/agent.json', 'a path is not a uuid');
		$this->assertSame([], preg_grep('/cache_engine/', $this->commands()), 'MAIN\'s cache rebuilds are not a node\'s');
	}

	// ── Everywhere else, as before ───────────────────────────────────

	/**
	 * Only a node in mode 2 stops reading MAIN's `signals` table and Redis
	 * signals; MAIN, mode 0 and mode 1 keep their loop.
	 */
	public function testOnlyAModeTwoNodeStopsReadingSignals(): void {
		foreach ([[null, true], [0, true], [1, true], [2, false]] as [$rMode, $rReads]) {
			$this->here($rMode);
			$this->assertSame($rReads, SignalsCommand::readsMainDatabase(), 'mode ' . var_export($rMode, true));
		}
		foreach (['quarantined' => false, 'revoked' => true, 'enrolling' => true] as $rState => $rReads) {
			$this->here(2, 255, $rState);
			$this->assertSame($rReads, SignalsCommand::readsMainDatabase(), $rState);
		}
		$this->here(2);
		NodeRole::useMainBuild(true);
		$this->assertTrue(SignalsCommand::readsMainDatabase(), 'MAIN, even with a stray flows.json');
	}
}
