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
 *
 * **A canary.** A version that one node was offered and does not run
 * RETRY_SEC later (its install failed, or run.sh put the previous binary back
 * after the new one kept failing at start) is held back from every node not
 * yet offered it, audited once as `cluster.agent_rollout_held`, until the node
 * runs it or MAIN pins another. A node is offered a version at most MAX_TRIES
 * times.
 */
final class AgentUpgrades {
	use DatabaseAware;

	/** How long before a node that did not take the version is offered it again. */
	public const RETRY_SEC = 900;

	/** How many times a node is offered the same version. */
	public const MAX_TRIES = 3;

	private const PUSHED = 'agent_push:';

	private const HELD = 'agent_rollout_held:';

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
		$rAtOnce = ClusterSettings::int('cluster_agent_upgrade_parallel', SettingsManager::get('cluster_agent_upgrade_parallel'));
		self::db()->query("SELECT * FROM `cluster_nodes` WHERE `state` = 'active' ORDER BY `server_id` ASC;");
		$rNodes = self::db()->get_rows() ?: [];
		// The versions a node was offered and does not run RETRY_SEC later.
		$rFailed = [];
		foreach ($rNodes as $rNode) {
			$rDoc = self::pushed((int) $rNode['server_id'], (int) $rNode['gen']);
			if ($rDoc !== null && (string) ($rNode['agent_version'] ?? '') !== $rDoc['version'] && $rNow - $rDoc['at'] >= self::RETRY_SEC) {
				$rFailed[$rDoc['version']] ??= (int) $rNode['server_id'];
			}
		}
		$rQueued = 0;
		$rBusy = 0;

		foreach ($rNodes as $rNode) {
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
			$rDoc = self::pushed($rServerID, (int) $rNode['gen']);
			$rOffered = $rDoc !== null && $rDoc['version'] === $rWant;
			if ($rOffered && $rDoc['tries'] >= self::MAX_TRIES) {
				continue; // given up on this node; it still holds the rollout back
			}
			if ($rOffered && $rNow - $rDoc['at'] < self::RETRY_SEC) {
				$rBusy++;
				continue;
			}
			if (!$rOffered && isset($rFailed[$rWant])) {
				self::held($rWant, $rFailed[$rWant]);
				continue;
			}
			if ($rQueued + $rBusy >= $rAtOnce) {
				break;
			}
			$rOK = (bool) $rSend($rServerID, $rArch);
			self::record($rServerID, (int) $rNode['gen'], $rWant, $rNow, $rOffered ? $rDoc['tries'] + 1 : 1);
			ClusterAudit::log('node.agent_push', $rServerID, ['arch' => $rArch, 'version' => $rWant, 'was' => $rNode['agent_version'], 'queued' => $rOK], 'cron');
			$rQueued++;
		}

		return $rQueued;
	}

	/**
	 * What this node (this generation) was last offered.
	 *
	 * @return array{version: string, at: int, tries: int}|null
	 */
	private static function pushed(int $rServerID, int $rGen): ?array {
		$rDoc = json_decode((string) ClusterMeta::get(self::PUSHED . $rServerID), true);
		if (!is_array($rDoc) || ($rDoc['gen'] ?? null) !== $rGen || !is_string($rDoc['version'] ?? null)) {
			return null;
		}
		return ['version' => $rDoc['version'], 'at' => (int) ($rDoc['at'] ?? 0), 'tries' => max(1, (int) ($rDoc['tries'] ?? 1))];
	}

	private static function record(int $rServerID, int $rGen, string $rVersion, int $rNow, int $rTries): void {
		ClusterMeta::set(self::PUSHED . $rServerID, (string) json_encode(['gen' => $rGen, 'version' => $rVersion, 'at' => $rNow, 'tries' => $rTries]));
	}

	/** The rollout of $rVersion is held (a node it failed on): audited once. */
	private static function held(string $rVersion, int $rServerID): void {
		if (ClusterMeta::get(self::HELD . $rVersion) === null) {
			ClusterMeta::set(self::HELD . $rVersion, (string) $rServerID);
			ClusterAudit::log('cluster.agent_rollout_held', $rServerID, ['version' => $rVersion], 'cron');
		}
	}
}
