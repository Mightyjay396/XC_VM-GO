<?php

namespace XcVm\Core\Process;

/**
 * Run a program from its argv list, with no shell in between: each element
 * is one argument, exactly as it is, so nothing in a value is ever read as
 * shell syntax (no quoting to get right, no `;`, `$(…)` or backticks).
 *
 * It does what shell_exec() and exec() did with a command whose output the
 * caller dropped: it waits for the program, reading its stdout to the end
 * and dropping it (so the program never blocks on a full pipe), and returns
 * its exit status. Its stdin is /dev/null; its stderr is this process's, as
 * shell_exec() left it, or /dev/null (`$rQuiet`, a line's `2>/dev/null`).
 */
final class ProcessRunner {
	/** The most of a program's output read at a time (and dropped). */
	private const CHUNK = 65536;

	/**
	 * Tests: runs the argv lists instead, and nothing is started; null
	 * restores proc_open.
	 *
	 * @var (callable(non-empty-list<string>, bool): int)|null
	 */
	private static $rRunner = null;

	/** Tests: run argv lists through $rRunner (argv, quiet => exit status); null restores proc_open. */
	public static function useRunner(?callable $rRunner): void {
		self::$rRunner = $rRunner;
	}

	/**
	 * Run $rArgv (a program's path, or a name PATH finds, then its
	 * arguments) and wait for it to end.
	 *
	 * @param non-empty-list<string> $rArgv
	 * @param bool $rQuiet its stderr to /dev/null instead of this process's
	 * @return int its exit status; 127 when it did not start, as a shell answers
	 */
	public static function run(array $rArgv, bool $rQuiet = false): int {
		if (self::$rRunner !== null) {
			return (self::$rRunner)($rArgv, $rQuiet);
		}
		// Descriptor 2 left out is inherited: this process's stderr.
		$rSpec = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w']];
		if ($rQuiet) {
			$rSpec[2] = ['file', '/dev/null', 'w'];
		}
		// An argv list, no shell: each element one argument; callers pass constant paths and words and values they validated (integer ids, the store's own directory).
		// nosemgrep: php.lang.security.exec-use.exec-use
		$rProc = @proc_open($rArgv, $rSpec, $rPipes);
		if (!is_resource($rProc)) {
			return 127;
		}
		while (!feof($rPipes[1])) {
			if (fread($rPipes[1], self::CHUNK) === false) {
				break;
			}
		}
		fclose($rPipes[1]);
		return proc_close($rProc);
	}
}
