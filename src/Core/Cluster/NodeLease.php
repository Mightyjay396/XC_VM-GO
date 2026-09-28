<?php

namespace XcVm\Core\Cluster;

use XcVm\Core\Config\SettingsManager;

/**
 * What this load balancer's lease says it may still serve (plan, section 9).
 *
 * MAIN signs a lease with every token it hands a node: how long that node may
 * keep serving viewers once it can no longer reach MAIN. The node's agent
 * verifies it, keeps it, and writes what it holds — the lease's window and its
 * anchor on MAIN's clock — into `config/cluster/lease_state.json` at every
 * heartbeat interval, whether or not MAIN answers (XC_VM_Fanout, `lease.go`;
 * the anchor is MAIN's last authenticated time carried on the node's monotonic
 * clock, `mainclock.go`). This reads that file and answers what the streaming
 * paths ask: may a new viewer start, and may the ones running carry on.
 *
 * Every uncertainty serves. No file, a file the agent has stopped refreshing,
 * no lease, no anchor (MAIN never heard on this node), a legacy node, or the
 * switch off: all of them answer {@see SERVING}. The gate on a revoked licence
 * is MAIN refusing to issue a lease at all, not this check — a node whose agent
 * is stopped also stops being handed tokens, and MAIN sees it go offline — so
 * failing open here costs the operator nothing they cannot see, while failing
 * closed would take a fleet off the air over a stopped agent or a stale file.
 *
 * **The compiled verdict first.** When the node's `xcvm_core` offers it
 * (`cluster_lease_state`, ADR-002 "Lease verdict on a node"), the extension
 * judges the lease itself: it keeps the exact bytes MAIN signed (handed over
 * from the agent's state with `cluster_lease_store`), verifies them against its
 * own pin of the panel key, and holds an anchor on MAIN's clock that only a
 * verified lease moves, only forward, and that runs on the monotonic clock —
 * so neither the node's clock nor a MAIN whose clock was set back can lengthen
 * the window. A `live` or `expired` answer decides; `none` (no pin, no lease
 * stored yet, a file that does not open) falls back to the agent's file below,
 * which keeps every uncertainty serving. An extension without the method (or
 * none at all) is the same fallback. The extension's own `license_valid()`
 * fails closed on `none`; this fence does not, for the reasons above.
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
	public const FILE = AgentPaths::DIR . 'lease_state.json';

	/**
	 * The fence MAIN commanded (`node.fence`), as the agent holds it: written
	 * every heartbeat while the fence stands, removed by `node.unfence` (or,
	 * for a licence fence, when MAIN accepts the node's session again).
	 */
	public const FENCE_FILE = AgentPaths::DIR . 'fence.json';

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

	private static ?string $rFencePath = null;

	/** @var array{state: string, reason: string, drain_until_ms: int, wrote_at_ms: int}|null */
	private static ?array $rFence = null;

	/** @var array{exp: int, gen: int, anchor_ms: int, wrote_at_ms: int}|null The agent's file as last read. */
	private static ?array $rDoc = null;

	private static int $rReadAt = 0;

	/**
	 * How the extension is reached: null for `\XC_VM` when it offers the lease
	 * verdict, false for none, or a test's `fn(string $rMethod, mixed ...$rArgs)`.
	 */
	private static \Closure|false|null $rExt = null;

	private static ?string $rAgentState = null;

	/** @var array{state: string, exp: int, gen: int, main_time: int, at_ns: int}|null The compiled verdict as last read. */
	private static ?array $rCompiled = null;

	private static int $rCompiledAt = 0;

	/** The newest lease `iat` handed to the extension, so a refusal is not retried every read. */
	private static int $rOffered = 0;

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
	 * clock stands as the extension's anchor or the agent last vouched for it,
	 * `source` says which of the two decided (`extension`, `agent`, or `none`
	 * when nothing did), and `why` names the reason a verdict is SERVING when no
	 * lease decided it.
	 *
	 * @param array<string, mixed>|null $rSettings
	 * @return array{state: string, exp: int, drain_until: int, anchor: int, gen: int, why: string, source: string}
	 */
	public static function verdict(?array $rSettings = null): array {
		return self::judge($rSettings);
	}

	/**
	 * MAIN's clock on this node, in milliseconds, as its agent last vouched
	 * for it (`anchor_ms`) plus the time since it wrote that down; null when
	 * the agent has written no anchor or stopped refreshing its file. It is
	 * what a load balancer judges MAIN's tickets and its peers' proofs by
	 * (RelayGuard, FileTicketServer): their times are MAIN's, as the peer's
	 * agent measured them, not this host's.
	 */
	public static function mainNowMs(): ?int {
		$rDoc = self::agentFile();
		$rNowMs = (int) round(microtime(true) * 1000);
		if ($rDoc === null || $rDoc['anchor_ms'] <= 0 || $rDoc['wrote_at_ms'] <= 0 || $rNowMs - $rDoc['wrote_at_ms'] > self::STALE_SEC * 1000) {
			return null;
		}
		return $rDoc['anchor_ms'] + max(0, $rNowMs - $rDoc['wrote_at_ms']);
	}

	/** Tests: read other files, and forget what was read. */
	public static function usePath(?string $rPath, ?string $rFencePath = null): void {
		self::$rPath = $rPath;
		self::$rFencePath = $rFencePath;
		self::$rDoc = null;
		self::$rFence = null;
		self::$rReadAt = 0;
		self::$rCompiled = null;
		self::$rCompiledAt = 0;
	}

	/**
	 * Tests: reach the extension through $rCall (`fn(string $rMethod, mixed
	 * ...$rArgs)`), or none at all (false); null restores `\XC_VM`. $rAgentState
	 * is the agent's state file the lease is taken from (null: the node's own).
	 */
	public static function useExtension(\Closure|false|null $rCall, ?string $rAgentState = null): void {
		self::$rExt = $rCall;
		self::$rAgentState = $rAgentState;
		self::forgetCompiled();
	}

	private static function forgetCompiled(): void {
		self::$rCompiled = null;
		self::$rCompiledAt = 0;
		self::$rOffered = 0;
	}

	/**
	 * @param array<string, mixed>|null $rSettings
	 * @return array{state: string, exp: int, drain_until: int, anchor: int, gen: int, why: string, source: string}
	 */
	private static function judge(?array $rSettings): array {
		$rServing = ['state' => self::SERVING, 'exp' => 0, 'drain_until' => 0, 'anchor' => 0, 'gen' => 0, 'why' => '', 'source' => 'none'];
		// MAIN's own fence (`node.fence`) needs no switch: it is an operator's
		// or a lapsed licence's explicit word, verified by the agent.
		$rFenced = self::commandFence();
		if ($rFenced !== null) {
			return $rFenced + $rServing;
		}
		if (!self::switchedOn($rSettings)) {
			return ['why' => 'the switch is off'] + $rServing;
		}
		// A node MAIN does not hold a lease for: mode 0, no agent, or MAIN itself.
		if (NodeFlows::current()['mode'] < 1) {
			return ['why' => 'not a cluster node'] + $rServing;
		}
		$rCompiled = self::compiled();
		if ($rCompiled !== null) {
			// MAIN's time as the extension's anchor has it, plus the (monotonic)
			// time since it was read, at most the 2 s it is kept.
			$rAnchor = $rCompiled['main_time'] + min(2, intdiv(max(0, hrtime(true) - $rCompiled['at_ns']), 1_000_000_000));
			$rDrainUntil = $rCompiled['exp'] + self::drainMinutes($rSettings) * 60;
			$rOut = ['exp' => $rCompiled['exp'], 'drain_until' => $rDrainUntil, 'anchor' => $rAnchor, 'gen' => $rCompiled['gen'], 'why' => '', 'source' => 'extension'];
			if ($rAnchor < $rCompiled['exp']) {
				return ['state' => self::SERVING] + $rOut;
			}
			return ['state' => $rAnchor < $rDrainUntil ? self::DRAINING : self::FENCED] + $rOut;
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
		$rOut = ['exp' => $rDoc['exp'], 'drain_until' => $rDrainUntil, 'anchor' => $rAnchor, 'gen' => $rDoc['gen'], 'why' => '', 'source' => 'agent'];
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
	 * The verdict of a fence MAIN commanded, while the agent keeps its file
	 * fresh; null when there is none (no file, a stale one — the agent is not
	 * running, and a fence nobody maintains serves, as every uncertainty does —
	 * or a state it does not name).
	 *
	 * @return array{state: string, drain_until: int, why: string}|null
	 */
	private static function commandFence(): ?array {
		self::agentFile();
		$rFence = self::$rFence;
		if ($rFence === null || !in_array($rFence['state'], [self::DRAINING, self::FENCED], true)) {
			return null;
		}
		if ($rFence['wrote_at_ms'] <= 0 || (int) round(microtime(true) * 1000) - $rFence['wrote_at_ms'] > self::STALE_SEC * 1000) {
			return null;
		}
		return ['state' => $rFence['state'], 'drain_until' => intdiv($rFence['drain_until_ms'], 1000), 'why' => 'fenced by MAIN (' . $rFence['reason'] . ')'];
	}

	/**
	 * The extension's verdict when it judged one (`live` or `expired`), read at
	 * most every 2 s like the agent's file; null when it has none to give.
	 *
	 * @return array{state: string, exp: int, gen: int, main_time: int, at_ns: int}|null
	 */
	private static function compiled(): ?array {
		if (self::$rCompiledAt === 0 || time() - self::$rCompiledAt >= 2) {
			self::$rCompiled = self::askExtension();
			self::$rCompiledAt = time();
		}
		return self::$rCompiled;
	}

	/** The way to the extension, or null when it does not offer the verdict. */
	private static function extension(): ?\Closure {
		if (self::$rExt !== null) {
			return self::$rExt === false ? null : self::$rExt;
		}
		if (!class_exists('XC_VM') || !method_exists('XC_VM', 'cluster_lease_state') || !method_exists('XC_VM', 'cluster_lease_store')) {
			return null;
		}
		return static fn(string $rMethod, mixed ...$rArgs): mixed => \XC_VM::$rMethod(...$rArgs);
	}

	/**
	 * Hand the extension the agent's newest lease, then read its verdict. What
	 * throws or answers anything but an array is no verdict.
	 *
	 * @return array{state: string, exp: int, gen: int, main_time: int, at_ns: int}|null
	 */
	private static function askExtension(): ?array {
		$rCall = self::extension();
		if ($rCall === null) {
			return null;
		}
		try {
			$rState = $rCall('cluster_lease_state');
			if (!is_array($rState)) {
				return null;
			}
			$rState = self::offer($rCall, $rState);
		} catch (\Throwable) {
			return null;
		}
		if (!in_array($rState['state'] ?? null, ['live', 'expired'], true) || (int) ($rState['exp'] ?? 0) <= 0) {
			return null;
		}
		return [
			'state' => (string) $rState['state'],
			'exp' => (int) $rState['exp'],
			'gen' => (int) ($rState['gen'] ?? 0),
			'main_time' => (int) ($rState['main_time'] ?? 0),
			'at_ns' => hrtime(true),
		];
	}

	/**
	 * The lease the agent holds, stored in the extension when it is newer (by
	 * `iat`) than the one the extension judged. The extension checks it: the
	 * pinned panel key, this node, not older than what it holds, live on its
	 * own anchor. A lease it refused is not offered again until a newer one
	 * arrives. Without a pin there is nothing to check it against, and nothing
	 * is offered.
	 *
	 * @param array<string, mixed> $rState the extension's verdict
	 * @return array<string, mixed> the verdict, after a store if one was made
	 */
	private static function offer(\Closure $rCall, array $rState): array {
		if (($rState['why'] ?? '') === 'NOT_PINNED') {
			return $rState;
		}
		$rAgent = AgentPaths::readState(self::$rAgentState ?? AgentPaths::fileOrNull(AgentPaths::STATE) ?? '');
		$rLease = $rAgent['lease'] ?? null;
		$rNode = $rAgent['node_uuid'] ?? null;
		if (!is_array($rLease) || !is_string($rNode) || !is_string($rLease['payload'] ?? null) || !is_string($rLease['sig'] ?? null)) {
			return $rState;
		}
		$rIat = (int) ($rLease['iat'] ?? 0);
		$rHeld = in_array($rState['state'] ?? null, ['live', 'expired'], true) ? (int) ($rState['iat'] ?? 0) : 0;
		if ($rIat <= $rHeld || $rIat <= self::$rOffered) {
			return $rState;
		}
		self::$rOffered = $rIat;
		$rPayload = base64_decode($rLease['payload'], true);
		$rSig = base64_decode($rLease['sig'], true);
		if ($rPayload === false || $rSig === false) {
			return $rState;
		}
		$rStored = $rCall('cluster_lease_store', $rPayload, $rSig, $rNode);
		return is_array($rStored) ? $rStored : $rState;
	}

	/**
	 * The agent's file, read at most every 2 s: the streaming endpoints ask per
	 * request, and the file changes at the heartbeat's pace at best.
	 *
	 * @return array{exp: int, gen: int, anchor_ms: int, wrote_at_ms: int}|null
	 */
	private static function agentFile(): ?array {
		if (self::$rReadAt === 0 || time() - self::$rReadAt >= 2) {
			self::$rDoc = self::parse(self::$rPath ?? AgentPaths::fileOrNull(self::FILE));
			self::$rFence = self::parseFence(self::$rPath !== null ? self::$rFencePath : AgentPaths::fileOrNull(self::FENCE_FILE));
			self::$rReadAt = time();
		}
		return self::$rDoc;
	}

	/**
	 * The agent's fence file, or null when there is none (or it is not one).
	 *
	 * @return array{state: string, reason: string, drain_until_ms: int, wrote_at_ms: int}|null
	 */
	private static function parseFence(?string $rPath): ?array {
		$rDoc = $rPath === null ? null : json_decode((string) @file_get_contents($rPath), true);
		if (!is_array($rDoc) || !is_string($rDoc['state'] ?? null)) {
			return null;
		}
		return [
			'state' => $rDoc['state'],
			'reason' => substr(preg_replace('/[^a-z0-9_.-]/', '', strtolower((string) ($rDoc['reason'] ?? ''))) ?? '', 0, 32),
			'drain_until_ms' => (int) ($rDoc['drain_until_ms'] ?? 0),
			'wrote_at_ms' => (int) ($rDoc['wrote_at_ms'] ?? 0),
		];
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
