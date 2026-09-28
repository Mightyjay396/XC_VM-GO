<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;

/**
 * ClusterSetDbPasswordCommand — on a load balancer, as root: put the panel's
 * new database password into this node's `config.enc` after MAIN rotated it
 * (`cluster:rotate-db-password`) and could not send the node a config itself
 * (a legacy node, mode 0, no root commands, install_id unknown). Only
 * `db.pass` changes, through `\XC_VM::config_set_db` (the extension's
 * ADR-002); the password is read from standard input, never from the command
 * line, where `ps` and the shell history would keep it.
 *
 * The extension refuses on MAIN (`RECORD:is_lb`), on a node whose config holds
 * no DB user (`RECORD:db.user`: one that gave up MAIN's credentials gets them
 * back whole through `install_config`), and a password `db_set_password`
 * would refuse (`ARG:password`).
 *
 * Usage: `echo "$PASS" | console.php cluster:set-db-password` (root, load balancer).
 *
 * @package XC_VM_CLI_Commands
 */
class ClusterSetDbPasswordCommand implements CommandInterface {
	/** @var \Closure(string, mixed...): mixed|null Tests: the extension. */
	private static ?\Closure $rExt = null;

	/** @var \Closure(): string|null Tests: standard input. */
	private static ?\Closure $rStdin = null;

	/** What each refusal code says on the command line. */
	public const MESSAGES = [
		'RECORD:is_lb' => 'This is MAIN: rotate the password there with cluster:rotate-db-password.',
		'RECORD:db.user' => 'This node\'s config holds no DB user (it gave up MAIN\'s credentials): MAIN sends it a whole config instead.',
		'RECORD:config.enc' => 'This node has no readable config.enc.',
		'ARG:password' => 'The password must be 16-128 characters of A-Z a-z 0-9 - _ . ~ + = @ % ^ * ! , : / ?',
	];

	public function getName(): string {
		return 'cluster:set-db-password';
	}

	public function getDescription(): string {
		return 'Set the panel\'s rotated DB password in this load balancer\'s config (root; reads standard input)';
	}

	/** Tests: reach the extension through $rCall and read standard input with $rStdin; null restores both. */
	public static function useExtension(?\Closure $rCall, ?\Closure $rStdin = null): void {
		self::$rExt = $rCall;
		self::$rStdin = $rStdin;
	}

	public function execute(array $rArgs): int {
		$rCall = self::$rExt;
		if ($rCall === null) {
			if ((posix_getpwuid(posix_geteuid())['name'] ?? null) !== 'root') {
				echo "Please run as root!\n";
				return 1;
			}
			if (!class_exists('XC_VM') || !method_exists('XC_VM', 'config_set_db')) {
				echo "This node's xcvm_core has no config_set_db(): update xcvm_core first.\n";
				return 1;
			}
			$rCall = static fn(string $rMethod, mixed ...$rA): mixed => \XC_VM::$rMethod(...$rA);
		}
		if (self::$rStdin === null && function_exists('posix_isatty') && posix_isatty(STDIN)) {
			echo 'New database password: ';
		}
		$rPass = trim(self::$rStdin !== null ? (self::$rStdin)() : (string) fgets(STDIN));
		if ($rPass === '') {
			echo "No password on standard input: nothing changed.\n";
			return 1;
		}
		if ($rCall('config_set_db', $rPass) === true) {
			echo "OK: this node's config.enc holds the new database password.\n";
			return 0;
		}
		$rWhy = $rCall('cluster_last_error');
		$rWhy = is_string($rWhy) && $rWhy !== '' ? $rWhy : 'unknown';
		echo 'Refused (' . $rWhy . '): ' . (self::MESSAGES[$rWhy] ?? 'nothing changed.') . "\n";
		return 1;
	}
}
