<?php

namespace XcVm\Cli\Commands;

/**
 * The passphrase for cluster:export-keys and cluster:import-keys: from
 * `--passphrase-file=<path>`, else typed at the terminal without echo, else
 * one line of standard input. Never an argument, which any user could read
 * in the process list. The extension checks its strength.
 *
 * @package XC_VM_CLI_Commands
 */
final class ClusterPassphrase {
	/**
	 * @param array<string, string> $rOptions --name=value options.
	 * @param resource|null         $rIn      Input stream (tests); STDIN by default.
	 * @return string|null Null when none was given or the two entries differ.
	 */
	public static function read(array $rOptions, bool $rConfirm, $rIn = null): ?string {
		if (isset($rOptions['passphrase-file'])) {
			$rText = @file_get_contents($rOptions['passphrase-file']);
			return $rText === false ? null : self::line($rText);
		}
		$rIn = $rIn ?? STDIN;
		$rTty = function_exists('posix_isatty') && @posix_isatty($rIn);
		$rFirst = self::prompt($rIn, $rTty, 'Passphrase: ');
		if ($rFirst === null || !$rConfirm || !$rTty) {
			return $rFirst;
		}
		return self::prompt($rIn, true, 'Repeat it: ') === $rFirst ? $rFirst : null;
	}

	/** @param resource $rIn */
	private static function prompt($rIn, bool $rTty, string $rLabel): ?string {
		if ($rTty) {
			echo $rLabel;
			shell_exec('stty -echo 2>/dev/null');
		}
		$rLine = fgets($rIn);
		if ($rTty) {
			shell_exec('stty echo 2>/dev/null');
			echo "\n";
		}
		return $rLine === false ? null : self::line($rLine);
	}

	private static function line(string $rText): ?string {
		$rLine = rtrim(strtok($rText, "\n") ?: '', "\r");
		return $rLine === '' ? null : $rLine;
	}
}
