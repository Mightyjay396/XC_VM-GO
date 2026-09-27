<?php

namespace XcVm\Domain\Cluster;

use XcVm\Cli\Commands\AgentBinaryCommand;
use XcVm\Core\Cluster\ClusterSettings;
use XcVm\Core\Cluster\NodeActions;
use XcVm\Core\Config\SettingsManager;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * The agent version the fleet runs (MAIN ↔ LB plan, section 5: "every node
 * runs the version MAIN pinned").
 *
 * MAIN keeps one verified `xc_agent` binary per architecture
 * (`console.php agent_binary`) and the install flow pushes it over SSH, so a
 * node starts on MAIN's version — and then stays on it for ever: nothing
 * offered an upgrade afterwards. `cron:cluster` does, every minute: a node
 * whose reported `agent_version` is not the cached one is sent
 * `node.root agent_binary`, the signed command that carries the binary's
 * artefact grant (root stages it, checks its size and SHA-256, installs it and
 * restarts the agent).
 *
 * A push is recorded in `cluster_meta` so the same one is not queued every
 * minute: the node reports its new version within seconds of the restart, and
 * one that does not (a failed install, a stopped agent) is offered it again
 * after RETRY_SEC.
 *
 * Nothing happens without the node's own architecture, which its agent reports
 * at hello: MAIN never guesses which binary a machine can run.
 */
final class AgentUpgrades {
	use DatabaseAware;

	/** How long before a node that did not take the version is offered it again. */
	public const RETRY_SEC = 900;

	private const PUSHED = 'agent_push:';

	/**
	 * Offer the active nodes the agent MAIN pinned for their arch, at most
	 * `cluster_agent_upgrade_parallel` of them at a time (a node offered it and
	 * not yet back on the new version counts against that, until RETRY_SEC).
	 * Lowest server id first, so a staged rollout is in a fixed order.
	 *
	 * @param callable(int, string): bool|null $rSend fn(serverID, arch): queued (tests)
	 * @return int The pushes queued.
	 */
	public static function push(?callable $rSend = null, ?int $rNow = null): int {
		$rNow ??= ClusterClock::now();
		$rSend ??= static fn(int $rServerID, string $rArch): bool => NodeActions::agentBinary($rServerID, $rArch);
		// lb-settings: cluster_agent_upgrade_parallel
		$rAtOnce = ClusterSettings::clampInt('cluster_agent_upgrade_parallel', (int) SettingsManager::get('cluster_agent_upgrade_parallel'));
		self::db()->query("SELECT * FROM `cluster_nodes` WHERE `state` = 'active' ORDER BY `server_id` ASC;");
		$rQueued = 0;
		$rBusy = 0;

		foreach (self::db()->get_rows() ?: [] as $rNode) {
			$rArch = (string) ($rNode['arch'] ?? '');
			$rWant = $rArch === '' ? null : AgentBinaryCommand::cachedVersion($rArch);
			if ($rWant === null || (string) ($rNode['agent_version'] ?? '') === '' || (string) $rNode['agent_version'] === $rWant) {
				continue;
			}
			// Only an agent that downloads artefacts can be given a binary.
			if (!ArtefactGrants::takes($rNode)) {
				continue;
			}
			$rServerID = (int) $rNode['server_id'];
			if (!self::due($rServerID, (int) $rNode['gen'], $rWant, $rNow)) {
				$rBusy++;
				continue;
			}
			if ($rQueued + $rBusy >= $rAtOnce) {
				break;
			}
			$rOK = (bool) $rSend($rServerID, $rArch);
			self::record($rServerID, (int) $rNode['gen'], $rWant, $rNow);
			ClusterAudit::log('node.agent_push', $rServerID, ['arch' => $rArch, 'version' => $rWant, 'was' => $rNode['agent_version'], 'queued' => $rOK], 'cron');
			$rQueued++;
		}

		return $rQueued;
	}

	/** Has this version not been offered to this node (or this generation) inside RETRY_SEC? */
	private static function due(int $rServerID, int $rGen, string $rVersion, int $rNow): bool {
		$rDoc = json_decode((string) ClusterMeta::get(self::PUSHED . $rServerID), true);
		if (!is_array($rDoc) || ($rDoc['gen'] ?? null) !== $rGen || ($rDoc['version'] ?? null) !== $rVersion) {
			return true;
		}
		return $rNow - (int) ($rDoc['at'] ?? 0) >= self::RETRY_SEC;
	}

	private static function record(int $rServerID, int $rGen, string $rVersion, int $rNow): void {
		ClusterMeta::set(self::PUSHED . $rServerID, (string) json_encode(['gen' => $rGen, 'version' => $rVersion, 'at' => $rNow]));
	}
}
