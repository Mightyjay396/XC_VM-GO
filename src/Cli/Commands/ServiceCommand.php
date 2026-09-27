<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Process\ProcessRunner;

/**
 * Управление сервисом XC_VM (start/stop/restart/reload).
 *
 * Команда: service {start|stop|restart|reload}
 * Требует: root
 *
 * The boot sequence itself lives in one place only: `MAIN_HOME/service`, the
 * script systemd runs. This command used to be a second copy of it, and had
 * drifted — it started neither xc_fanout, nor xc_agent, nor `fanout_sync`, nor
 * the replica's `cluster:apply`, and left `storage/cluster` and the
 * `cluster_ready` marker alone — so a panel booted through the console ran a
 * different set of services from one booted by systemd. It delegates now;
 * `start` maps to the script's `boot`, which does everything its foreground
 * `start` does without holding the terminal, as this command never did.
 *
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ServiceCommand implements CommandInterface {
	/** The command's action => the script's. */
	private const ACTIONS = ['start' => 'boot', 'stop' => 'stop', 'restart' => 'restart', 'reload' => 'reload'];

	public function getName(): string {
		return 'service';
	}

	public function getDescription(): string {
		return 'Manage XC_VM service: start, stop, restart, reload';
	}

	public function execute(array $rArgs): int {
		if (posix_getpwuid(posix_geteuid())['name'] !== 'root') {
			echo "Please run as root!\n";
			return 1;
		}

		$rAction = self::ACTIONS[$rArgs[0] ?? ''] ?? null;
		if ($rAction === null) {
			echo "Usage: console.php service {start|stop|restart|reload}\n";
			return 1;
		}

		$rScript = MAIN_HOME . 'service';
		if (!is_file($rScript)) {
			echo 'Missing boot script: ' . $rScript . "\n";
			return 1;
		}

		// An argv list, no shell of ours: the script's own path and one of the four
		// words ACTIONS maps to. Its output is the operator's, as passthru left it.
		return ProcessRunner::passThrough(['/bin/sh', $rScript, $rAction]);
	}
}
