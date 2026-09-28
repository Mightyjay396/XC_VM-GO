<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Cluster\ArtefactStage;
use XcVm\Core\Cluster\CacheJobs;
use XcVm\Core\Cluster\Crypto\Enc;
use XcVm\Core\Cluster\Crypto\PanelSig;
use XcVm\Core\Cluster\NodeRpc;
use XcVm\Core\Cluster\RootPin;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Public\Controllers\Api\InternalApiController;
use XcVm\Streaming\Fanout\FanoutClient;

/**
 * ClusterExecCommand — run one MAIN command on this node (Phase 4). The agent
 * pipes a command it has verified, `{"doc": "<json>", "sig": "<b64url>"}`, on
 * stdin; this checks it again against the panel key the agent pinned
 * (config/cluster/agent.json), runs it and writes its result to stdout for
 * the agent's ack. Exit 0 means done.
 *
 * - `node.rpc` — its `action` (the envelope's, never an argument) one of
 *   NodeRpc::ACTIONS, run with its `args` through the handlers of the legacy
 *   /api (InternalApiController::runCommand);
 * - `conn.drop {uuid}` — a viewer the fanout serves (the agent runs it
 *   itself when it can reach the fanout);
 * - `conn.kill_worker {pid, rtmp}` — a viewer's PHP worker (this user's
 *   processes only) or an RTMP client.
 *
 * - `node.root` (its `action` the envelope's too) — handed to root
 *   (cluster:root) through the root inbox; root checks it against its own
 *   pin of the panel key.
 * - `node.cache {jobs}` — cache jobs for a node in mode 2, whose signals
 *   daemon reads no `signals` row: run as the daemon ran the rows
 *   (CacheJobs::run), only when every job is in the form MAIN signs it
 *   (CacheJobs::job) and they name at most CacheJobs::MAX targets
 *   (CacheJobs::targets); otherwise refused whole (exit 2) before any runs.
 * - `config.changed {sections}` — the agent fetches its replica at once; an
 *   agent that hands it here instead is acked `{"deferred": true}`, and its
 *   next minute's poll fetches the change.
 * - `artefact.fetch {artefact}` — an off-air video MAIN granted, which the
 *   agent downloaded into config/cluster/artefacts/<cmd_id>: placed where
 *   the node's off-air code plays it once its size and SHA-256 are the
 *   grant's (ArtefactStage::placeOffAir), else refused and audited (exit 1).
 *
 * Runs as xc_vm. `console.php cluster:exec --types` prints the command
 * types this node's PHP runs (TYPES, a JSON array) and reads nothing: the
 * agent asks it before it says a feature at hello whose commands only a
 * newer PHP runs (`artefact`: `artefact.fetch`). A PHP from before this
 * option reads its empty stdin as a command and exits 2.
 *
 * Usage: `console.php cluster:exec < command.json`, `console.php cluster:exec --types`
 *
 * @package XC_VM_CLI_Commands
 */
class ClusterExecCommand implements CommandInterface {
	/** Commands older than their exp by more than this (LB clock) are refused. */
	public const SKEW = 300;

	/** The command types run here (`--types`). */
	public const TYPES = ['node.rpc', 'node.root', 'node.cache', 'conn.kill_worker', 'conn.drop', 'config.changed', ArtefactStage::TYPE_FETCH];

	public function getName(): string {
		return 'cluster:exec';
	}

	public function getDescription(): string {
		return 'Run one signed MAIN command (from xc_agent) on this node';
	}

	public function execute(array $rArgs): int {
		if (in_array('--types', $rArgs, true)) {
			echo json_encode(self::TYPES);
			return 0;
		}
		$rIn = json_decode((string) stream_get_contents(STDIN), true);
		$rState = json_decode((string) @file_get_contents(CONFIG_PATH . 'cluster/agent.json'), true);
		$rCmd = self::verify(is_array($rIn) ? $rIn : [], is_array($rState) ? $rState : [], time());
		if (is_string($rCmd)) {
			fwrite(STDERR, 'cluster:exec: ' . $rCmd . "\n");
			return 2;
		}
		if (($rCmd['type'] ?? '') === 'node.root') {
			return self::handToRoot($rIn, (int) ($rCmd['seq'] ?? 0), isset($rCmd['args']['artefact']) ? self::ROOT_WAIT_ARTEFACT : self::ROOT_WAIT);
		}
		return self::run($rCmd);
	}

	/** Seconds cluster:exec waits for cluster:root's result before acking "queued". */
	public const ROOT_WAIT = 5;

	/**
	 * The same for a root command that carries an artefact, which root
	 * stages and checks (up to 128 MiB) before its action runs: long enough
	 * for root's refusal to reach MAIN in the ack, within the agent's minute.
	 */
	public const ROOT_WAIT_ARTEFACT = 45;

	/**
	 * `node.root`: root runs it (cluster:root), after checking it against its
	 * own pin of the panel key; here the signed command is only handed over,
	 * and root's result waited for briefly. Root's result goes to stdout; a
	 * failure (exit 1) goes to stderr too, since the agent acks a non-zero
	 * exit with stderr alone.
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
				$rResult = is_array($rOut) ? (string) ($rOut['result'] ?? '') : '';
				echo $rResult;
				if (!is_array($rOut) || empty($rOut['ok'])) {
					fwrite(STDERR, 'cluster:exec: ' . ($rResult !== '' ? $rResult : 'root\'s result is unreadable') . "\n");
					return 1;
				}
				return 0;
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
				// The action is the envelope's, as the extension classes it; one
				// among the arguments as well would be a second answer to what runs.
				$rAction = $rCmd['action'] ?? null;
				if (!in_array($rAction, NodeRpc::ACTIONS, true) || array_key_exists('action', $rArgs)) {
					fwrite(STDERR, "cluster:exec: unknown action\n");
					return 2;
				}
				(new InternalApiController())->runCommand(['action' => $rAction] + $rArgs);
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

			case 'conn.drop':
				// The agent drops daemon viewers itself; this is its fallback.
				$rUUID = (string) ($rArgs['uuid'] ?? '');
				if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $rUUID)) {
					return 2;
				}
				echo json_encode(['result' => FanoutClient::dropConnection($rUUID)]);
				return 0;

			case 'node.cache':
				$rJobs = $rArgs['jobs'] ?? null;
				if (!is_array($rJobs) || $rJobs === [] || !array_is_list($rJobs) || count($rJobs) > CacheJobs::MAX) {
					fwrite(STDERR, "cluster:exec: bad cache jobs\n");
					return 2;
				}
				foreach ($rJobs as $rJob) {
					// Exactly the job MAIN's form makes of it, whatever its keys' order.
					$rClean = CacheJobs::job($rJob);
					if ($rClean !== null && is_array($rJob)) {
						ksort($rClean);
						ksort($rJob);
					}
					if ($rClean === null || $rClean !== $rJob) {
						fwrite(STDERR, "cluster:exec: bad cache jobs\n");
						return 2;
					}
				}
				// What one run may take within the agent's minute: MAIN splits longer lists.
				if (CacheJobs::targets($rJobs) > CacheJobs::MAX) {
					fwrite(STDERR, "cluster:exec: bad cache jobs\n");
					return 2;
				}
				CacheJobs::run($rJobs);
				echo json_encode(['result' => true, 'jobs' => count($rJobs)]);
				return 0;

			case 'config.changed':
				// PHP holds no key to fetch the replica: the agent's next poll does.
				echo json_encode(['deferred' => true]);
				return 0;

			case ArtefactStage::TYPE_FETCH:
				$rPlaced = ArtefactStage::placeOffAir($rCmd);
				if (is_string($rPlaced)) {
					fwrite(STDERR, 'cluster:exec: ' . $rPlaced . "\n");
					return 1;
				}
				echo json_encode($rPlaced);
				return 0;
		}
		fwrite(STDERR, "cluster:exec: unknown command type\n");
		return 2;
	}
}
