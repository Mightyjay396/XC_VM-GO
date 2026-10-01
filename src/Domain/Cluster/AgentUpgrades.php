<?php

namespace XcVm\Domain\Cluster;

use XcVm\Cli\Commands\AgentBinaryCommand;
use XcVm\Cli\Commands\XcvmCoreCommand;
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
 * runs it or MAIN pins another, or an operator releases it (release(), for a
 * failure that was not the version's: the node was down, MAIN unreachable).
 * A node is offered a version at most MAX_TRIES times.
 */
final class AgentUpgrades {
	use DatabaseAware;

	/** How long before a node that did not take the version is offered it again. */
	public const RETRY_SEC = 900;

	/** How many times a node is offered the same version. */
	public const MAX_TRIES = 3;

	/** The binaries MAIN pins (plan, section 5): the agent, and the fanout daemon and xcvm_core, which "follow the same path on nodes in mode 1 or 2". */
	public const KINDS = ['agent', 'fanout', 'core'];

	/**
	 * Offer the active nodes the binary of $rKind MAIN pinned for them, at
	 * most `cluster_agent_upgrade_parallel` of them at a time (a node offered
	 * it and not yet back on the new version counts against that, until
	 * RETRY_SEC). Lowest server id first, so a staged rollout is in a fixed
	 * order.
	 *
	 * - `agent`: the xc_agent for the node's arch, whenever the node reports
	 *   another version (MAIN's is the fleet's).
	 * - `fanout`, `core`: the xc_fanout for its arch, the xcvm_core archive
	 *   for its PHP group (both from the node's watchdog `versions`), to a
	 *   node in mode 1 or 2 that runs an older one. Never a downgrade: a node
	 *   in mode 0 or 1 still updates them from GitHub itself, and may be ahead
	 *   of MAIN's copy.
	 *
	 * @param callable(int, string): bool|null $rSend fn(serverID, arch or PHP group): queued (tests)
	 * @return int The pushes queued.
	 */
	public static function push(?callable $rSend = null, ?int $rNow = null, string $rKind = 'agent'): int {
		$rNow ??= ClusterClock::now();
		$rSend ??= match ($rKind) {
			'fanout' => static fn(int $rServerID, string $rFor): bool => NodeActions::fanoutBinary($rServerID, $rFor),
			'core' => static fn(int $rServerID, string $rFor): bool => NodeActions::xcvmCore($rServerID, $rFor),
			default => static fn(int $rServerID, string $rFor): bool => NodeActions::agentBinary($rServerID, $rFor),
		};
		// lb-settings: cluster_agent_upgrade_parallel
		$rAtOnce = ClusterSettings::int('cluster_agent_upgrade_parallel', SettingsManager::get('cluster_agent_upgrade_parallel'));
		self::db()->query($rKind === 'agent'
			? "SELECT * FROM `cluster_nodes` WHERE `state` = 'active' ORDER BY `server_id` ASC;"
			: "SELECT `cluster_nodes`.*, `servers`.`watchdog_data` FROM `cluster_nodes` LEFT JOIN `servers` ON `servers`.`id` = `cluster_nodes`.`server_id` WHERE `cluster_nodes`.`state` = 'active' ORDER BY `cluster_nodes`.`server_id` ASC;");
		$rNodes = self::db()->get_rows() ?: [];

		// The versions a node was offered and does not run RETRY_SEC later.
		$rFailed = [];
		foreach ($rNodes as $rNode) {
			$rDoc = self::pushed((int) $rNode['server_id'], (int) $rNode['gen'], $rKind);
			if ($rDoc !== null && self::runs($rNode, $rKind) !== $rDoc['version'] && $rNow - $rDoc['at'] >= self::RETRY_SEC) {
				$rFailed[$rDoc['version']] ??= (int) $rNode['server_id'];
			}
		}

		$rQueued = 0;
		$rBusy = 0;
		foreach ($rNodes as $rNode) {
			[$rFor, $rWant] = self::wanted($rNode, $rKind);
			$rHas = self::runs($rNode, $rKind);
			if ($rWant === null || $rHas === '' || $rHas === $rWant) {
				continue;
			}
			if ($rKind !== 'agent' && ((int) ($rNode['mode'] ?? 0) < 1 || version_compare($rHas, $rWant, '>'))) {
				continue;
			}
			// Only an agent that downloads artefacts can be given a binary (and
			// the daemon or the extension, one whose node's PHP installs them).
			if (!($rKind === 'agent' ? ArtefactGrants::takes($rNode) : ArtefactGrants::takesBinaries($rNode))) {
				continue;
			}
			$rServerID = (int) $rNode['server_id'];
			$rDoc = self::pushed($rServerID, (int) $rNode['gen'], $rKind);
			$rOffered = $rDoc !== null && $rDoc['version'] === $rWant;
			if ($rOffered && $rDoc['tries'] >= self::MAX_TRIES) {
				continue; // given up on this node; it still holds the rollout back
			}
			if ($rOffered && $rNow - $rDoc['at'] < self::RETRY_SEC) {
				$rBusy++;
				continue;
			}
			if (!$rOffered && isset($rFailed[$rWant])) {
				self::held($rWant, $rFailed[$rWant], $rKind);
				continue;
			}
			if ($rQueued + $rBusy >= $rAtOnce) {
				break;
			}
			$rOK = (bool) $rSend($rServerID, $rFor);
			self::record($rServerID, (int) $rNode['gen'], $rWant, $rNow, $rOffered ? $rDoc['tries'] + 1 : 1, $rKind);
			ClusterAudit::log('node.' . $rKind . '_push', $rServerID, [($rKind === 'core' ? 'group' : 'arch') => $rFor, 'version' => $rWant, 'was' => $rHas, 'queued' => $rOK], 'cron');
			$rQueued++;
		}
		return $rQueued;
	}

	/**
	 * What the node runs of $rKind: the agent its hello reported, the fanout
	 * daemon and xcvm_core its watchdog reported ('' when it reported none).
	 *
	 * @param array<string, mixed> $rNode
	 */
	private static function runs(array $rNode, string $rKind): string {
		if ($rKind === 'agent') {
			return (string) ($rNode['agent_version'] ?? '');
		}
		$rVersions = json_decode((string) ($rNode['watchdog_data'] ?? ''), true)['versions'] ?? null;
		return is_array($rVersions) ? (string) ($rVersions[$rKind === 'fanout' ? 'xc_fanout' : 'xcvm_core'] ?? '') : '';
	}

	/**
	 * What MAIN holds of $rKind for this node: [what it is for (the arch, or
	 * the PHP group), its version], or [.., null] when MAIN holds none.
	 *
	 * @param array<string, mixed> $rNode
	 * @return array{0: string, 1: ?string}
	 */
	private static function wanted(array $rNode, string $rKind): array {
		if ($rKind === 'core') {
			$rGroup = (string) (json_decode((string) ($rNode['watchdog_data'] ?? ''), true)['versions']['php'] ?? '');
			return [$rGroup, $rGroup === '' ? null : XcvmCoreCommand::cachedVersion($rGroup)];
		}
		$rArch = (string) ($rNode['arch'] ?? '');
		return [$rArch, $rArch === '' ? null : AgentBinaryCommand::cachedVersion($rArch, $rKind === 'fanout' ? AgentBinaryCommand::FANOUT_PREFIX : AgentBinaryCommand::ASSET_PREFIX)];
	}

	/** cluster_meta's key of what a node was offered of $rKind (the agent's as before). */
	private static function pushKey(string $rKind, int $rServerID): string {
		return $rKind . '_push:' . $rServerID;
	}

	/**
	 * What this node (this generation) was last offered.
	 *
	 * @return array{version: string, at: int, tries: int}|null
	 */
	private static function pushed(int $rServerID, int $rGen, string $rKind = 'agent'): ?array {
		$rDoc = json_decode((string) ClusterMeta::get(self::pushKey($rKind, $rServerID)), true);
		if (!is_array($rDoc) || ($rDoc['gen'] ?? null) !== $rGen || !is_string($rDoc['version'] ?? null)) {
			return null;
		}
		return ['version' => $rDoc['version'], 'at' => (int) ($rDoc['at'] ?? 0), 'tries' => max(1, (int) ($rDoc['tries'] ?? 1))];
	}

	private static function record(int $rServerID, int $rGen, string $rVersion, int $rNow, int $rTries, string $rKind = 'agent'): void {
		ClusterMeta::set(self::pushKey($rKind, $rServerID), (string) json_encode(['gen' => $rGen, 'version' => $rVersion, 'at' => $rNow, 'tries' => $rTries]));
	}

	/**
	 * Release a held rollout of $rVersion: forget what each node that did not
	 * take it was offered (RETRY_SEC ago or more, so a failure; an offer still
	 * installing is left alone), so none counts as a failure and each is
	 * offered it afresh, MAX_TRIES times; and the hold's audit, so a new
	 * failure is audited again. Audited as `cluster.agent_rollout_released`.
	 *
	 * @return int The nodes whose offer was forgotten.
	 */
	public static function release(string $rVersion, string $rBy = 'cli', ?int $rNow = null, string $rKind = 'agent'): int {
		$rNow ??= ClusterClock::now();
		self::db()->query($rKind === 'agent'
			? 'SELECT * FROM `cluster_nodes`;'
			: 'SELECT `cluster_nodes`.*, `servers`.`watchdog_data` FROM `cluster_nodes` LEFT JOIN `servers` ON `servers`.`id` = `cluster_nodes`.`server_id`;');
		$rForgotten = 0;
		foreach (self::db()->get_rows() ?: [] as $rNode) {
			$rDoc = self::pushed((int) $rNode['server_id'], (int) $rNode['gen'], $rKind);
			if ($rDoc !== null && $rDoc['version'] === $rVersion && self::runs($rNode, $rKind) !== $rVersion && $rNow - $rDoc['at'] >= self::RETRY_SEC) {
				ClusterMeta::delete(self::pushKey($rKind, (int) $rNode['server_id']));
				$rForgotten++;
			}
		}
		$rWasHeld = ClusterMeta::get(self::heldKey($rKind, $rVersion)) !== null;
		ClusterMeta::delete(self::heldKey($rKind, $rVersion));
		ClusterAudit::log('cluster.' . $rKind . '_rollout_released', null, ['version' => $rVersion, 'nodes' => $rForgotten, 'was_held' => $rWasHeld], $rBy);
		return $rForgotten;
	}

	/** The rollout of $rVersion is held (a node it failed on): audited once. */
	private static function held(string $rVersion, int $rServerID, string $rKind = 'agent'): void {
		if (ClusterMeta::get(self::heldKey($rKind, $rVersion)) === null) {
			ClusterMeta::set(self::heldKey($rKind, $rVersion), (string) $rServerID);
			ClusterAudit::log('cluster.' . $rKind . '_rollout_held', $rServerID, ['version' => $rVersion], 'cron');
		}
	}

	/** cluster_meta's key of a held rollout of $rKind (the agent's as before). */
	private static function heldKey(string $rKind, string $rVersion): string {
		return $rKind . '_rollout_held:' . $rVersion;
	}
}
