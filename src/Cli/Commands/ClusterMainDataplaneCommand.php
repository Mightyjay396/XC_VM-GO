<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Cluster\AgentPaths;
use XcVm\Core\Cluster\DataPlane;
use XcVm\Core\Cluster\MainAgentFiles;
use XcVm\Core\Process\ProcessRunner;
use XcVm\Core\Updates\ReleaseAsset;
use XcVm\Domain\Cluster\MainDataPlane;

/**
 * ClusterMainDataplaneCommand — MAIN's data-plane client (ADR 0004, Phase 9's
 * eighth increment; MainDataPlane).
 *
 * - `on`: MAIN pulls what it reads from other servers (a source probe, a
 *   node's certbot log, the relays and files of the streams it runs)
 *   through its own agent with a key of its own, listed in the signed node
 *   list. The agent binary for MAIN's arch comes from MAIN's cache
 *   (`agent_binary`); `run.sh` starts `xc_agent run -role main`.
 * - `off`: MAIN leaves the node list and keeps the legacy URLs. The key stays.
 * - `rekey`: a new key and generation; the old one's tickets and proofs stop.
 * - `status`: what is set, and whether the agent holds its port.
 *
 * Usage: `console.php cluster:main-dataplane on|off|rekey|status`. MAIN only
 * (stripped from LB builds); root or xc_vm.
 *
 * @package XC_VM_CLI_Commands
 */
class ClusterMainDataplaneCommand implements CommandInterface {
	/** The agent binary MAIN runs, and its supervisor. */
	public const AGENT_BIN = MAIN_HOME . 'bin/xc_agent/xc_agent';
	public const RUN_SH = MAIN_HOME . 'bin/xc_agent/run.sh';

	public function getName(): string {
		return 'cluster:main-dataplane';
	}

	public function getDescription(): string {
		return 'MAIN\'s data-plane client: pull other servers\' files and relays through MAIN\'s own agent (on | off | rekey | status)';
	}

	public function execute(array $rArgs): int {
		$rWhat = (string) ($rArgs[0] ?? 'status');
		if (!in_array($rWhat, ['on', 'off', 'rekey', 'status'], true)) {
			echo "Usage: cluster:main-dataplane on | off | rekey | status\n";
			return 1;
		}
		if ($rWhat === 'status') {
			return $this->status();
		}
		$rUser = function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '') : '';
		if (!in_array($rUser, ['root', 'xc_vm'], true)) {
			echo "Run it as root or xc_vm.\n";
			return 1;
		}
		if ($rWhat === 'off') {
			$rWhy = MainDataPlane::disable('cli');
			echo $rWhy === null ? "MAIN's data plane is off: MAIN left the node list and reads other servers with the legacy URLs again.\n" : 'Refused: ' . $rWhy . "\n";
			return $rWhy === null ? 0 : 1;
		}
		$rBinary = self::installBinary();
		if ($rBinary !== null) {
			echo 'Refused: ' . $rBinary . "\n";
			return 1;
		}
		$rWhy = MainDataPlane::enable(static fn(string $rState, string $rUuid): ?array => self::keygen($rUser, $rState, $rUuid), $rWhat === 'rekey', 'cli');
		if ($rWhy !== null) {
			echo 'Refused: ' . $rWhy . "\n";
			return 1;
		}
		$rID = (array) MainDataPlane::identity();
		echo "MAIN's data plane is on (key gen " . (int) ($rID['gen'] ?? 0) . "): the nodes get MAIN's entry in their node list within a minute.\n";
		echo self::startAgent($rUser) ? "Its agent is starting.\n" : "Its agent starts within a minute (cron:root_signals).\n";
		return 0;
	}

	private function status(): int {
		$rID = MainDataPlane::identity();
		if ($rID === null) {
			echo "MAIN's data plane: never switched on (cluster:main-dataplane on).\n";
			return 0;
		}
		echo "MAIN's data plane: " . ($rID['on'] ? 'on' : 'off') . ', key gen ' . $rID['gen'] . ' (uuid ' . $rID['node_uuid'] . ")\n";
		$rFile = MainAgentFiles::identity();
		echo '  main.json: ' . ($rFile === null ? 'missing or not an identity' : ($rFile['dataplane'] ? 'serving' : 'not serving') . ', gen ' . $rFile['gen']) . "\n";
		echo '  agent binary: ' . (is_executable(self::AGENT_BIN) ? self::AGENT_BIN : 'missing') . "\n";
		echo '  loopback proxy: ' . (DataPlane::loopback() !== null ? 'the agent holds 127.0.0.1:31290' : 'no agent holds 127.0.0.1:31290 (MAIN falls back to the legacy URLs)') . "\n";
		$rStore = json_decode((string) @file_get_contents(AgentPaths::file(MainAgentFiles::REPLICA) . 'tickets.json'), true);
		$rCount = 0;
		foreach (is_array($rStore['streams'] ?? null) ? $rStore['streams'] : [] as $rSlot) {
			$rCount += (isset($rSlot['relay']) ? 1 : 0) + (is_array($rSlot['files'] ?? null) ? count($rSlot['files']) : 0);
		}
		echo '  tickets held: ' . $rCount . ' (epoch ' . (int) ($rStore['epoch'] ?? 0) . ")\n";
		return 0;
	}

	/**
	 * Put the verified agent binary for MAIN's arch from MAIN's cache in
	 * place when it is missing or another; null when done, else why not.
	 */
	private static function installBinary(): ?string {
		$rArch = ReleaseAsset::arch(php_uname('m'));
		if ($rArch === null) {
			return 'no xc_agent build for this machine (' . php_uname('m') . ')';
		}
		$rCached = AgentBinaryCommand::cached($rArch);
		if ($rCached === null) {
			return is_executable(self::AGENT_BIN) ? null : 'no xc_agent binary for ' . $rArch . ' in MAIN\'s cache (console.php agent_binary)';
		}
		if (is_file(self::AGENT_BIN) && hash_file('sha256', self::AGENT_BIN) === hash_file('sha256', $rCached)) {
			return null;
		}
		$rTmp = self::AGENT_BIN . '.new';
		if (!is_dir(dirname(self::AGENT_BIN))) {
			@mkdir(dirname(self::AGENT_BIN), 0755, true);
		}
		if (!@copy($rCached, $rTmp) || !@chmod($rTmp, 0755)) {
			@unlink($rTmp);
			return 'cannot write ' . self::AGENT_BIN;
		}
		if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
			@chown($rTmp, 'xc_vm');
			@chgrp($rTmp, 'xc_vm');
		}
		if (!@rename($rTmp, self::AGENT_BIN)) {
			@unlink($rTmp);
			return 'cannot write ' . self::AGENT_BIN;
		}
		return null;
	}

	/**
	 * `xc_agent keygen` for MAIN's key, as the agent's user.
	 *
	 * @return array{node_uuid: string, sign_pub: string, box_pub: string}|null
	 */
	private static function keygen(string $rUser, string $rState, string $rUuid): ?array {
		$rArgv = [self::AGENT_BIN, 'keygen', '-state', $rState, '-uuid', $rUuid];
		if ($rUser === 'root') {
			$rArgv = array_merge(['sudo', '-n', '-u', 'xc_vm'], $rArgv);
		}
		[$rStatus, $rOut] = ProcessRunner::capture($rArgv);
		$rKeys = json_decode(trim($rOut), true);
		if ($rStatus !== 0 || !is_array($rKeys) || !is_string($rKeys['node_uuid'] ?? null) || !is_string($rKeys['sign_pub'] ?? null) || !is_string($rKeys['box_pub'] ?? null)) {
			return null;
		}
		return ['node_uuid' => $rKeys['node_uuid'], 'sign_pub' => $rKeys['sign_pub'], 'box_pub' => $rKeys['box_pub']];
	}

	/** Start the agent's supervisor unless one runs; whether one was started. */
	private static function startAgent(string $rUser): bool {
		if (!is_file(self::RUN_SH)) {
			return false;
		}
		// A supervisor already running picks the identity up itself.
		[$rStatus] = ProcessRunner::capture(['pgrep', '-u', 'xc_vm', '-f', self::RUN_SH]);
		if ($rStatus === 0) {
			return false;
		}
		return ProcessRunner::start($rUser === 'root' ? ['sudo', '-n', '-u', 'xc_vm', 'bash', self::RUN_SH] : ['bash', self::RUN_SH]);
	}
}
