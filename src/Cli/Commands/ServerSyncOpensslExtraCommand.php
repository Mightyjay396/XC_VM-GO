<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Config\OpensslExtra;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * Bring load balancers onto the main's OPENSSL_EXTRA.
 *
 * Run on the MAIN: php console.php server:sync-openssl-extra <server_id>|--all [--force]
 *
 * OPENSSL_EXTRA keys the stream tokens the main mints for the redirects an LB
 * serves. A main set up by the current installer holds a random value in
 * config/openssl_extra, while an LB added with server:install before
 * provisioning shipped that file runs on the built-in default and rejects
 * those tokens. Every node publishes a fingerprint of its value
 * (cron:servers); server:diagnose shows a mismatch.
 *
 * For each streaming LB that reports another fingerprint this queues one root
 * signal carrying the main's value. The LB's cron:root_signals writes it
 * (OpensslExtra::install) and, for OpensslExtra::PREVIOUS_WINDOW seconds, still
 * opens tokens it minted with the value it replaced. --force also sends to LBs
 * that report the same value, none (not updated yet) or are offline.
 *
 * The value passes through the signals table until the LB's root cron deletes
 * the row (about a minute); a legacy LB reads that database in full anyway.
 *
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class ServerSyncOpensslExtraCommand implements CommandInterface {
	use DatabaseAware;

	public function getName(): string {
		return 'server:sync-openssl-extra';
	}

	public function getDescription(): string {
		return "Send the main's OPENSSL_EXTRA to load balancers that report another one";
	}

	public function execute(array $rArgs): int {
		$rForce = in_array('--force', $rArgs, true);
		$rArgs = array_values(array_diff($rArgs, ['--force']));
		$rAll = (($rArgs[0] ?? '') === '--all');
		$rTargetID = $rAll ? 0 : intval($rArgs[0] ?? 0);
		if (!$rAll && $rTargetID <= 0) {
			echo "Usage: php console.php server:sync-openssl-extra <server_id>|--all [--force]\n";
			return 1;
		}

		$rServers = ServerRepository::getAll(true);
		if (!defined('SERVER_ID') || empty($rServers[SERVER_ID]['is_main'])) {
			echo "Run this on the MAIN server.\n";
			return 1;
		}
		if (!$rAll && !isset($rServers[$rTargetID])) {
			echo "Server #{$rTargetID} not found.\n";
			return 1;
		}

		$rFile = CONFIG_PATH . 'openssl_extra';
		$rValue = is_file($rFile) ? @file_get_contents($rFile) : '';
		if ($rValue === false) {
			echo "Cannot read {$rFile}: run this as xc_vm or root.\n";
			return 1;
		}
		if (trim($rValue) === '') {
			echo "The main has no {$rFile} and runs on the built-in OPENSSL_EXTRA, as LBs installed by server:install do: nothing to send.\n";
			echo "A node that still reports a mismatch has a stale {$rFile} of its own; delete it there.\n";
			return 0;
		}

		$rMainPrint = OpensslExtra::fingerprint(OPENSSL_EXTRA);
		$rCustomData = json_encode(['action' => 'set_openssl_extra', 'value' => OPENSSL_EXTRA]);
		$rQueued = 0;
		foreach (($rAll ? $rServers : [$rTargetID => $rServers[$rTargetID]]) as $rServerID => $rServer) {
			if ($rAll && !empty($rServer['is_main'])) {
				continue;
			}
			$rSkip = self::skipReason($rServer, $rMainPrint, $rForce);
			if ($rSkip !== null) {
				echo "#{$rServerID} " . ($rServer['server_name'] ?? '') . ": skipped, {$rSkip}\n";
				continue;
			}
			self::db()->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`) VALUES(?, ?, ?);', $rServerID, time(), $rCustomData);
			echo "#{$rServerID} " . ($rServer['server_name'] ?? '') . ": queued\n";
			$rQueued++;
		}
		echo "{$rQueued} node(s) queued. Each applies it at its next cron:root_signals run (within a minute) and reports it at its next cron:servers run; check with server:diagnose <id>.\n";

		return 0;
	}

	/**
	 * Why $rServer does not get the main's value, or null when it does.
	 *
	 * @param string $rMainPrint OpensslExtra::fingerprint() of the main's value.
	 */
	public static function skipReason(array $rServer, string $rMainPrint, bool $rForce): ?string {
		if (!empty($rServer['is_main'])) {
			return 'the main';
		}
		if ((int) ($rServer['server_type'] ?? 0) === 1) {
			return 'a proxy';
		}
		if ($rForce) {
			return null;
		}
		$rPrint = OpensslExtra::reportedFingerprint($rServer);
		if ($rPrint === null) {
			return 'unknown (node not updated): update it, or pass --force';
		}
		if (hash_equals($rMainPrint, $rPrint)) {
			return 'already in sync';
		}
		if (empty($rServer['server_online'])) {
			return 'offline: pass --force to queue it anyway';
		}

		return null;
	}
}
