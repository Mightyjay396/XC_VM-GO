<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Domain\Cluster\DbPassword;

/**
 * ClusterRotateDbPasswordCommand — rotate the panel's database password
 * (plan, section 10; Phase 9) through `xcvm_core`'s `db_set_password`: MAIN's
 * accounts and `config.enc`, every load balancer's grant, and the new
 * password SEALed to each node that takes root commands, as `node.root
 * rotate_db` (DbPassword).
 *
 * It first lists what each load balancer needs, since a node MAIN cannot send
 * the password to keeps the old one and loses MAIN's database until an
 * operator sets it there (`cluster:set-db-password`, on the node). The operator types `rotate` to
 * confirm, or passes `--yes`. The new password is generated and never shown,
 * unless `--password-stdin` reads it from standard input (one line), which is
 * what an operator who has such nodes to update by hand uses.
 *
 * Usage: `console.php cluster:rotate-db-password [--yes] [--password-stdin]`. MAIN only.
 *
 * @package XC_VM_CLI_Commands
 */
class ClusterRotateDbPasswordCommand implements CommandInterface {
	/** @var \Closure(string): string|null Tests: what the operator types at the prompt. */
	private static ?\Closure $rAsk = null;

	/** @var \Closure(): string|null Tests: standard input. */
	private static ?\Closure $rStdin = null;

	/** What each refusal says on the command line. */
	public const MESSAGES = [
		'password' => 'The password must be 16-128 characters of A-Z a-z 0-9 - _ . ~ + = @ % ^ * ! , : / ?',
		'no_extension' => 'This MAIN\'s xcvm_core cannot rotate the password (no XC_VM::db_set_password): update xcvm_core.',
		'RECORD:is_lb' => 'This server is not MAIN.',
		'DB' => 'The database is unreachable, or the panel user cannot read mysql.user: nothing changed.',
	];

	public function getName(): string {
		return 'cluster:rotate-db-password';
	}

	public function getDescription(): string {
		return 'Rotate the panel\'s database password on MAIN and on every load balancer MAIN can reach';
	}

	/** Tests: answer the prompt with $rAsk and read standard input with $rStdin; null restores the terminal. */
	public static function useIo(?\Closure $rAsk, ?\Closure $rStdin = null): void {
		self::$rAsk = $rAsk;
		self::$rStdin = $rStdin;
	}

	public function execute(array $rArgs): int {
		$rYes = in_array('--yes', $rArgs, true);
		$rFromStdin = in_array('--password-stdin', $rArgs, true);
		if (!DbPassword::available()) {
			echo self::MESSAGES['no_extension'] . "\n";
			return 1;
		}
		$rNew = $rFromStdin ? trim(self::$rStdin !== null ? (self::$rStdin)() : (string) fgets(STDIN)) : DbPassword::generate();
		if (!DbPassword::valid($rNew)) {
			echo self::MESSAGES['password'] . "\n";
			return 1;
		}
		$rPlan = DbPassword::plan();
		$rManual = array_values(array_filter($rPlan, static fn(array $rN): bool => $rN['how'] === 'manual'));
		echo "This changes the panel's database password on MAIN (its accounts and config.enc) and on every load balancer's grant.\n";
		foreach ($rPlan as $rNode) {
			echo '  server ' . $rNode['server_id'] . ' (' . $rNode['server_name'] . '): ' . self::describe($rNode) . "\n";
		}
		if ($rManual !== []) {
			echo count($rManual) . " load balancer(s) keep the old password in their config and lose MAIN's database until it is set there:\n"
				. "  on each, as root: console.php cluster:set-db-password (reads the new password from standard input).\n";
			if (!$rFromStdin) {
				echo "  The password is generated and never shown: pass --password-stdin to choose one you can set there.\n";
			}
		}
		if (!$rYes && !self::confirmed()) {
			echo "Not confirmed: nothing changed.\n";
			return 1;
		}
		$rOut = DbPassword::rotate($rNew, 'cli');
		if (!$rOut['ok']) {
			echo 'Refused (' . $rOut['why'] . '): ' . (self::MESSAGES[(string) $rOut['why']] ?? 'nothing changed; see the extension\'s refusal code.') . "\n";
			return 1;
		}
		echo "MAIN runs on the new password.\n";
		if ($rOut['partial']) {
			echo "Some load balancer's grant kept the old password (PARTIAL): see the database's mysql.user, and run this again.\n";
		}
		$rFailed = 0;
		foreach ($rOut['nodes'] as $rNode) {
			if ($rNode['how'] !== 'sealed') {
				continue;
			}
			if ($rNode['result'] === null) {
				echo '  server ' . $rNode['server_id'] . ": new password sent sealed (node.root rotate_db)\n";
			} else {
				$rFailed++;
				echo '  server ' . $rNode['server_id'] . ': password NOT sent (' . $rNode['result'] . "): set it there by hand\n";
			}
		}
		return $rFailed === 0 ? 0 : 2;
	}

	/** @param array{how: string, why: string|null} $rNode */
	private static function describe(array $rNode): string {
		return match ($rNode['how']) {
			'sealed' => 'sent the new password sealed to its box key (node.root rotate_db)',
			'mode2' => 'mode 2, does not use MAIN\'s database (its grant follows; drop its credentials with cluster:strip-credentials)',
			'revoked' => 'its grant was revoked: nothing to change',
			default => 'BY HAND: ' . (string) $rNode['why'],
		};
	}

	private static function confirmed(): bool {
		$rPrompt = 'Type rotate to confirm: ';
		if (self::$rAsk !== null) {
			$rAnswer = (self::$rAsk)($rPrompt);
		} else {
			if (!function_exists('posix_isatty') || !posix_isatty(STDIN)) {
				echo "No terminal to confirm on: pass --yes.\n";
				return false;
			}
			echo $rPrompt;
			$rAnswer = (string) fgets(STDIN);
		}
		return trim($rAnswer) === 'rotate';
	}
}
