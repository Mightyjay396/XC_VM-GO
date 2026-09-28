<?php

namespace XcVm\Core\Cluster;

use XcVm\Core\Config\SettingsManager;

/**
 * What this load balancer's lease says it may still serve (plan, section 9).
 *
 * MAIN signs a lease with every token it hands a node: how long that node may
 * keep serving viewers once it can no longer reach MAIN. The node's agent
 * verifies it, keeps it, and writes what it holds — the lease's window and its
 * anchor on MAIN's clock — into `config/cluster/lease_state.json` on every
 * heartbeat (XC_VM_Fanout, `lease.go`). This reads that file and answers what
 * the streaming paths ask: may a new viewer start, and may the ones running
 * carry on.
 *
 * Every uncertainty serves. No file, a file the agent has stopped refreshing,
 * no lease, no anchor (MAIN never heard on this node), a legacy node, or the
 * switch off: all of them answer {@see SERVING}. The gate on a revoked licence
 * is MAIN refusing to issue a lease at all, not this check — a node whose agent
 * is stopped also stops being handed tokens, and MAIN sees it go offline — so
 * failing open here costs the operator nothing they cannot see, while failing
 * closed would take a fleet off the air over a stopped agent or a stale file.
 *
 * The switch is `lb_lease_fence`, off until an operator turns it on, and it
 * reaches a node the way its other settings do (MAIN's database, or the
 * replica's `settings` section). It must be on before the licence it guards
 * lapses: a replica section is a granting record, so a panel that cannot sign
 * one cannot change this either.
 *
 * @package XC_VM_Core_Cluster
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class NodeLease {
	/** The agent's file, under the config directory. */
	public const FILE = 'cluster/lease_state.json';

	/** Serving: the lease has time left on MAIN's clock, or there is nothing to go by. */
	public const SERVING = 'serving';

	/** Past the lease's `exp`: no new viewer starts, the ones running drain. */
	public const DRAINING = 'draining';

	/** Past the drain as well: nothing of this node's is served. */
	public const FENCED = 'fenced';

	/**
	 * How old the agent's file may be and still be read. It is rewritten every
	 * heartbeat (1-3 s), so anything older means the agent is not running, and a
	 * node whose agent is not running holds no statement of MAIN's to judge by.
	 */
	public const STALE_SEC = 60;

	private static ?string $rPath = null;

	/** @var array{exp: int, gen: int, anchor_ms: int, wrote_at_ms: int}|null The agent's file as last read. */
	private static ?array $rDoc = null;

	private static int $rReadAt = 0;

	/**
	 * The two settings this reads are passed in by the streaming endpoints, which
	 * hold their own $rSettings from the node's cache file and populate no
	 * SettingsManager (`Public/stream/segment.php`, `key.php`). Anything else —
	 * a cron, the CLI — passes nothing and gets SettingsManager's.
	 *
	 * @param array<string, mixed>|null $rSettings
	 */
	public static function refusesNewSessions(?array $rSettings = null): bool {
		return self::state($rSettings) !== self::SERVING;
	}

	/**
	 * Is every viewer refused (the drain is over too)?
	 *
	 * @param array<string, mixed>|null $rSettings
	 */
	public static function refusesEverything(?array $rSettings = null): bool {
		return self::state($rSettings) === self::FENCED;
	}

	/** @param array<string, mixed>|null $rSettings */
	public static function state(?array $rSettings = null): string {
		return self::verdict($rSettings)['state'];
	}

	/**
	 * The whole verdict, for the operator's page and the node's telemetry:
	 * `exp` and `drain_until` are on MAIN's clock, `anchor` is where MAIN's
	 * clock stands as the agent last vouched for it, and `why` names the reason
	 * a verdict is SERVING when no lease decided it.
	 *
	 * @param array<string, mixed>|null $rSettings
	 * @return array{state: string, exp: int, drain_until: int, anchor: int, gen: int, why: string}
	 */
	public static function verdict(?array $rSettings = null): array {
		return self::judge($rSettings);
	}

	/** Tests: read another file, and forget what was read. */
	public static function usePath(?string $rPath): void {
		self::$rPath = $rPath;
		self::$rDoc = null;
		self::$rReadAt = 0;
	}

	/**
	 * @param array<string, mixed>|null $rSettings
	 * @return array{state: string, exp: int, drain_until: int, anchor: int, gen: int, why: string}
	 */
	private static function judge(?array $rSettings): array {
		$rServing = ['state' => self::SERVING, 'exp' => 0, 'drain_until' => 0, 'anchor' => 0, 'gen' => 0, 'why' => ''];
		if (!self::switchedOn($rSettings)) {
			return ['why' => 'the switch is off'] + $rServing;
		}
		// A node MAIN does not hold a lease for: mode 0, no agent, or MAIN itself.
		if (NodeFlows::current()['mode'] < 1) {
			return ['why' => 'not a cluster node'] + $rServing;
		}
		$rDoc = self::agentFile();
		if ($rDoc === null) {
			return ['why' => 'the agent has written no lease state'] + $rServing;
		}
		$rNowMs = (int) round(microtime(true) * 1000);
		if ($rDoc['wrote_at_ms'] <= 0 || $rNowMs - $rDoc['wrote_at_ms'] > self::STALE_SEC * 1000) {
			return ['why' => 'the agent has stopped refreshing it'] + $rServing;
		}
		if ($rDoc['exp'] <= 0) {
			return ['why' => 'MAIN has sent this node no lease'] + $rServing;
		}
		if ($rDoc['anchor_ms'] <= 0) {
			return ['why' => 'MAIN has never been heard on this node'] + $rServing;
		}
		// MAIN's clock, as the agent last vouched for it, plus the time since it
		// wrote that down. A clock moved forward makes the file look stale (above)
		// and a clock moved back leaves the anchor where it was, so neither
		// shortens the window.
		$rAnchor = intdiv($rDoc['anchor_ms'] + max(0, $rNowMs - $rDoc['wrote_at_ms']), 1000);
		$rDrainUntil = $rDoc['exp'] + self::drainMinutes($rSettings) * 60;
		$rOut = ['exp' => $rDoc['exp'], 'drain_until' => $rDrainUntil, 'anchor' => $rAnchor, 'gen' => $rDoc['gen'], 'why' => ''];
		if ($rAnchor < $rDoc['exp']) {
			return ['state' => self::SERVING] + $rOut;
		}
		return ['state' => $rAnchor < $rDrainUntil ? self::DRAINING : self::FENCED] + $rOut;
	}

	/** @param array<string, mixed>|null $rSettings */
	private static function switchedOn(?array $rSettings): bool {
		return ClusterSettings::int('lb_lease_fence', self::setting($rSettings, 'lb_lease_fence')) === 1;
	}

	/**
	 * `lb_fence_drain_min`, within the bounds MAIN keeps it.
	 *
	 * @param array<string, mixed>|null $rSettings
	 */
	private static function drainMinutes(?array $rSettings): int {
		return ClusterSettings::int('lb_fence_drain_min', self::setting($rSettings, 'lb_fence_drain_min'));
	}

	/** @param array<string, mixed>|null $rSettings */
	private static function setting(?array $rSettings, string $rKey): mixed {
		if ($rSettings !== null) {
			// lb-settings: lb_lease_fence, lb_fence_drain_min
			return $rSettings[$rKey] ?? null;
		}
		// lb-settings: lb_lease_fence, lb_fence_drain_min
		return SettingsManager::get($rKey);
	}

	/**
	 * The agent's file, read at most every 2 s: the streaming endpoints ask per
	 * request, and the file changes at the heartbeat's pace at best.
	 *
	 * @return array{exp: int, gen: int, anchor_ms: int, wrote_at_ms: int}|null
	 */
	private static function agentFile(): ?array {
		if (self::$rReadAt === 0 || time() - self::$rReadAt >= 2) {
			self::$rDoc = self::parse(self::$rPath ?? (defined('CONFIG_PATH') ? CONFIG_PATH . self::FILE : null));
			self::$rReadAt = time();
		}
		return self::$rDoc;
	}

	/**
	 * The agent's file, or null when there is none (or it is not one).
	 *
	 * @return array{exp: int, gen: int, anchor_ms: int, wrote_at_ms: int}|null
	 */
	private static function parse(?string $rPath): ?array {
		$rDoc = $rPath === null ? null : json_decode((string) @file_get_contents($rPath), true);
		if (!is_array($rDoc)) {
			return null;
		}
		return [
			'exp' => (int) ($rDoc['exp'] ?? 0),
			'gen' => (int) ($rDoc['gen'] ?? 0),
			'anchor_ms' => (int) ($rDoc['anchor_ms'] ?? 0),
			'wrote_at_ms' => (int) ($rDoc['wrote_at_ms'] ?? 0),
		];
	}
}
