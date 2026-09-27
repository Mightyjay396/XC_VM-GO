<?php

namespace XcVm\Tests\Support;

/**
 * A cluster bus for tests: a real redis-server on a unix socket in a
 * throwaway directory, never persisted, reached through ClusterBus as MAIN
 * reaches its own.
 */
final class BusServer {
	/** @var resource|null */
	private $rProc = null;

	private function __construct(public readonly string $rDir) {
	}

	/** A running bus, or null without redis-server or phpredis (the caller skips). */
	public static function start(string $rName): ?self {
		if (!class_exists(\Redis::class) || trim((string) shell_exec('command -v redis-server')) === '') {
			return null;
		}
		$rBus = new self(sys_get_temp_dir() . '/xcvm-' . $rName . '-' . bin2hex(random_bytes(4)));
		mkdir($rBus->rDir);
		$rBus->restart();
		return $rBus;
	}

	public function socket(): string {
		return $this->rDir . '/cluster.sock';
	}

	/** Kill the server, so nothing it held survives, and start an empty one. */
	public function restart(): void {
		$this->kill();
		// A killed server leaves its socket behind; wait for the new one.
		@unlink($this->socket());
		$rNull = ['file', '/dev/null', 'w'];
		$this->rProc = proc_open(['redis-server', '--port', '0', '--unixsocket', $this->socket(), '--unixsocketperm', '700', '--save', '', '--appendonly', 'no', '--dir', $this->rDir], [0 => ['file', '/dev/null', 'r'], 1 => $rNull, 2 => $rNull], $rPipes) ?: null;
		for ($i = 0; $i < 100 && !file_exists($this->socket()); $i++) {
			usleep(20000);
		}
	}

	/** Kill the server and remove its directory. */
	public function stop(): void {
		$this->kill();
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/** The bus's own clock, in ms (ClusterSemaphore keeps its time). */
	public static function nowMs(\Redis $rRedis): int {
		[$rSec, $rMicro] = $rRedis->time();
		return (int) $rSec * 1000 + intdiv((int) $rMicro, 1000);
	}

	/** Kill the server: nothing it held survives, and its socket is left behind. */
	public function kill(): void {
		if ($this->rProc !== null) {
			proc_terminate($this->rProc, 9);
			proc_close($this->rProc);
			$this->rProc = null;
		}
	}
}
