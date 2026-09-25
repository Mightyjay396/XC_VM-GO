<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\CronJobs\RootSignalsCronJob;
use XcVm\Core\Cluster\Crypto\Enc;
use XcVm\Core\Cluster\RootPin;
use XcVm\Domain\Server\ServerRepository;

/**
 * ClusterRootCommand — run MAIN's signed root commands on this node (Phase 4).
 *
 * Root's crontab starts it every minute; it then watches the root inbox for
 * about a minute, once a second, so a root command runs within ~1 s of the
 * agent handing it over. Each command is checked against root's own pin of
 * the panel key (/etc/xc_vm/cluster, see RootPin), not against anything the
 * panel's user can write; root's high-water (root.seq) is raised before the
 * action runs, so a command never runs twice. The action itself runs through
 * the same code as the legacy signals (RootSignalsCronJob::executeAction).
 *
 * Without a pin it does nothing: root actions then keep the signals table.
 *
 * Usage: `console.php cluster:root` (root)
 *
 * @package XC_VM_CLI_Commands
 */
class ClusterRootCommand implements CommandInterface {
	public const WATCH_SECONDS = 58;

	public function getName(): string {
		return 'cluster:root';
	}

	public function getDescription(): string {
		return "Run MAIN's signed root commands (root)";
	}

	public function execute(array $rArgs): int {
		if ((posix_getpwuid(posix_geteuid())['name'] ?? null) !== 'root') {
			echo "Please run as root!\n";
			return 1;
		}
		if (RootPin::read() === null || !is_dir(RootPin::inbox())) {
			return 0;
		}
		$rLock = fopen(RootPin::dir() . 'root.lock', 'c');
		if ($rLock === false || !flock($rLock, LOCK_EX | LOCK_NB)) {
			return 0; // the previous minute's run is still watching
		}
		cli_set_process_title('XC_VM[ClusterRoot]');
		$rUntil = time() + (in_array('--once', $rArgs, true) ? 0 : self::WATCH_SECONDS);
		do {
			self::drain(static function (array $rAction): string {
				global $db;
				ob_start();
				(new RootSignalsCronJob())->executeAction($rAction, ServerRepository::getAll(), $db);
				return (string) ob_get_clean();
			}, time());
			if (time() >= $rUntil) {
				break;
			}
			sleep(1);
		} while (true);
		return 0;
	}

	/**
	 * One pass over the inbox, oldest seq first.
	 *
	 * @param callable(array<string, mixed>): string $rRun runs an action, returns its output
	 * @return list<array{seq: int, ok: bool, detail: string}> what was done
	 */
	public static function drain(callable $rRun, int $rNow): array {
		$rPin = RootPin::read();
		if ($rPin === null) {
			return [];
		}
		$rDone = [];
		$rFiles = glob(RootPin::inbox() . '*.json') ?: [];
		natsort($rFiles);
		foreach ($rFiles as $rFile) {
			$rSeq = (int) basename($rFile, '.json');
			$rDonePath = RootPin::inbox() . $rSeq . '.done';
			if ($rSeq <= 0 || is_link($rFile) || !is_file($rFile) || filesize($rFile) > RootPin::MAX_COMMAND) {
				@unlink($rFile);
				continue;
			}
			$rIn = json_decode((string) file_get_contents($rFile), true);
			@unlink($rFile);
			$rCmd = RootPin::verify($rPin, (string) ($rIn['doc'] ?? ''), (string) Enc::b64urlDecode((string) ($rIn['sig'] ?? '')), $rNow, RootPin::highWater());
			if (is_string($rCmd)) {
				RootPin::writeDone($rDonePath, (string) json_encode(['ok' => false, 'result' => 'refused by root: ' . $rCmd]));
				$rDone[] = ['seq' => $rSeq, 'ok' => false, 'detail' => $rCmd];
				continue;
			}
			// At most once: the high-water goes up before the action runs.
			if (!RootPin::raiseHighWater((int) $rCmd['seq'])) {
				continue;
			}
			try {
				$rOutput = $rRun((array) $rCmd['args']);
				$rOk = true;
			} catch (\Throwable $rE) {
				$rOutput = $rE->getMessage();
				$rOk = false;
			}
			RootPin::writeDone($rDonePath, (string) json_encode(['ok' => $rOk, 'result' => substr(trim($rOutput), 0, 4096)]));
			$rDone[] = ['seq' => (int) $rCmd['seq'], 'ok' => $rOk, 'detail' => (string) $rCmd['args']['action']];
		}
		return $rDone;
	}
}
