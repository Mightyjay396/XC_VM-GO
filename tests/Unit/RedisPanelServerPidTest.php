<?php

use PHPUnit\Framework\TestCase;
use XcVm\Infrastructure\Redis\RedisManager;

/**
 * Settings → Cache's "Enable/Disable handler" restarts the panel's Redis. It
 * used to kill the first redis-server of the panel's user, which can be the
 * cluster bus (a second redis-server, on a unix socket). It now takes the pid
 * from the panel's pidfile, and only while that pid is still a redis-server
 * that is not the bus's.
 */
final class RedisPanelServerPidTest extends TestCase {
	private string $rDir;

	/** @var list<resource> */
	private array $rProcs = [];

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-redis-pid-' . bin2hex(random_bytes(4));
		mkdir($this->rDir);
	}

	protected function tearDown(): void {
		foreach ($this->rProcs as $rProc) {
			proc_terminate($rProc, 9);
			proc_close($rProc);
		}
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/** A process titled as redis-server titles itself; its pid in a pidfile. */
	private function server(string $rTitle): string {
		$rProc = proc_open([PHP_BINARY, '-r', 'cli_set_process_title(' . var_export($rTitle, true) . '); sleep(30);'], [], $rPipes);
		$this->rProcs[] = $rProc;
		$rPid = (int) proc_get_status($rProc)['pid'];
		for ($i = 0; $i < 50 && !str_starts_with((string) @file_get_contents('/proc/' . $rPid . '/cmdline'), $rTitle); $i++) {
			usleep(20000);
		}
		$rFile = $this->rDir . '/' . $rPid . '.pid';
		file_put_contents($rFile, $rPid . "\n");
		return $rFile;
	}

	public function testThePanelsRedisByItsPidfileAndNeverTheBus(): void {
		$rPanel = $this->server('redis-server *:6379');
		$this->assertSame((int) file_get_contents($rPanel), RedisManager::panelServerPid($rPanel));
		$this->assertSame(0, RedisManager::panelServerPid($this->server('redis-server unixsocket:/home/xc_vm/tmp/cluster.sock')), 'the cluster bus');
		$this->assertSame(0, RedisManager::panelServerPid($this->server('php-fpm: pool www')), 'a pid reused by something else');
		$this->assertSame(0, RedisManager::panelServerPid($this->rDir . '/none.pid'), 'no pidfile');
	}
}
