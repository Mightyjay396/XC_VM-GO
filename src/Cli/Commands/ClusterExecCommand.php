<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Cluster\Crypto\Enc;
use XcVm\Core\Cluster\Crypto\PanelSig;
use XcVm\Core\Cluster\NodeRpc;
use XcVm\Core\Cluster\RootPin;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Public\Controllers\Api\InternalApiController;

/**
 * ClusterExecCommand — run one MAIN command on this node (Phase 4). The agent
 * pipes a command it has verified, `{"doc": "<json>", "sig": "<b64url>"}`, on
 * stdin; this checks it again against the panel key the agent pinned
 * (config/cluster/agent.json), runs it and writes its result to stdout for
 * the agent's ack. Exit 0 means done.
 *
 * - `node.rpc {action, …}` — one of NodeRpc::ACTIONS, through the handlers of
 *   the legacy /api (InternalApiController::runCommand);
 * - `conn.kill_worker {pid, rtmp}` — a viewer's PHP worker (this user's
 *   processes only) or an RTMP client.
 *
 * - `node.root {action, …}` — handed to root (cluster:root) through the
 *   root inbox; root checks it against its own pin of the panel key.
 *
 * Runs as xc_vm.
 *
 * Usage: `console.php cluster:exec < command.json`
 *
 * @package XC_VM_CLI_Commands
 */
class ClusterExecCommand implements CommandInterface {
	/** Commands older than their exp by more than this (LB clock) are refused. */
	public const SKEW = 300;

	public function getName(): string {
		return 'cluster:exec';
	}

	public function getDescription(): string {
		return 'Run one signed MAIN command (from xc_agent) on this node';
	}

	public function execute(array $rArgs): int {
		$rIn = json_decode((string) stream_get_contents(STDIN), true);
		$rState = json_decode((string) @file_get_contents(CONFIG_PATH . 'cluster/agent.json'), true);
		$rCmd = self::verify(is_array($rIn) ? $rIn : [], is_array($rState) ? $rState : [], time());
		if (is_string($rCmd)) {
			fwrite(STDERR, 'cluster:exec: ' . $rCmd . "\n");
			return 2;
		}
		if (($rCmd['type'] ?? '') === 'node.root') {
			return self::handToRoot($rIn, (int) ($rCmd['seq'] ?? 0));
		}
		return self::run($rCmd);
	}

	/** Seconds cluster:exec waits for cluster:root's result before acking "queued". */
	public const ROOT_WAIT = 5;

	/**
	 * `node.root`: root runs it (cluster:root), after checking it against its
	 * own pin of the panel key; here the signed command is only handed over,
	 * and root's result waited for briefly.
	 *
	 * @param array<string, mixed> $rIn {doc, sig}
	 */
	public static function handToRoot(array $rIn, int $rSeq, int $rWait = self::ROOT_WAIT): int {
		$rInbox = RootPin::inbox();
		if ($rSeq <= 0 || !is_dir($rInbox)) {
			fwrite(STDERR, "cluster:exec: no root inbox (the node's root pin is not in place)\n");
			return 2;
		}
		$rTmp = $rInbox . '.' . $rSeq . '.tmp';
		$rDone = $rInbox . $rSeq . '.done';
		@unlink($rDone);
		if (@file_put_contents($rTmp, (string) json_encode(['doc' => $rIn['doc'], 'sig' => $rIn['sig']])) === false || !@rename($rTmp, $rInbox . $rSeq . '.json')) {
			fwrite(STDERR, "cluster:exec: cannot write the root inbox\n");
			return 2;
		}
		$rDeadline = microtime(true) + $rWait;
		while (microtime(true) < $rDeadline) {
			if (is_file($rDone)) {
				$rOut = json_decode((string) file_get_contents($rDone), true);
				@unlink($rDone);
				echo is_array($rOut) ? (string) ($rOut['result'] ?? '') : '';
				return is_array($rOut) && !empty($rOut['ok']) ? 0 : 1;
			}
			usleep(200000);
		}
		echo json_encode(['queued' => true]);
		return 0;
	}

	/**
	 * Check a command against the agent's pinned panel key and identity.
	 *
	 * @param array<string, mixed> $rIn {doc, sig}
	 * @param array<string, mixed> $rState the agent's state (base64 fields)
	 * @return array<string, mixed>|string the command, or why it is refused
	 */
	public static function verify(array $rIn, array $rState, int $rNow): array|string {
		$rPub = base64_decode((string) ($rState['panel_sign_pub'] ?? ''), true);
		$rDoc = is_string($rIn['doc'] ?? null) ? $rIn['doc'] : '';
		$rSig = Enc::b64urlDecode((string) ($rIn['sig'] ?? ''));
		if ($rPub === false || !PanelSig::verify($rPub, 'cmd', $rDoc, (string) $rSig)) {
			return 'bad signature';
		}
		$rCmd = json_decode($rDoc, true);
		if (!is_array($rCmd) || ($rCmd['node_uuid'] ?? null) !== ($rState['node_uuid'] ?? '') || (int) ($rCmd['exp'] ?? 0) + self::SKEW < $rNow) {
			return 'not for this node, or expired';
		}
		return $rCmd;
	}

	/** @param array<string, mixed> $rCmd */
	public static function run(array $rCmd): int {
		$rArgs = is_array($rCmd['args'] ?? null) ? $rCmd['args'] : [];
		switch ($rCmd['type'] ?? '') {
			case 'node.rpc':
				if (!in_array($rArgs['action'] ?? null, NodeRpc::ACTIONS, true)) {
					fwrite(STDERR, "cluster:exec: unknown action\n");
					return 2;
				}
				(new InternalApiController())->runCommand($rArgs);
				return 0;

			case 'conn.kill_worker':
				$rPID = (int) ($rArgs['pid'] ?? 0);
				if ($rPID <= 0) {
					return 2;
				}
				if (!empty($rArgs['rtmp'])) {
					$rUrl = (string) (ServerRepository::getAll()[SERVER_ID]['rtmp_mport_url'] ?? '');
					if ($rUrl !== '') {
						@file_get_contents($rUrl . 'control/drop/client?clientid=' . $rPID, false, stream_context_create(['http' => ['timeout' => 2]]));
					}
				} elseif (file_exists('/proc/' . $rPID)) {
					posix_kill($rPID, 9);
				}
				echo json_encode(['result' => true]);
				return 0;
		}
		fwrite(STDERR, "cluster:exec: unknown command type\n");
		return 2;
	}
}
