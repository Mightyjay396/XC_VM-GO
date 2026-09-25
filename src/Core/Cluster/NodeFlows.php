<?php

namespace XcVm\Core\Cluster;

/**
 * This load balancer's cluster mode and flow bits, as MAIN last sent them to
 * the node's agent (`config/cluster/flows.json`, written by xc_agent from its
 * authenticated heartbeat replies). A flow that is on moves that part of the
 * node's work to the agent, and the legacy PHP path for it stands down.
 *
 * No file (legacy node, agent not enrolled, or stopped by MAIN) means every
 * flow is off. MAIN itself never has flows. Lives in Core: it ships to LBs,
 * where the Domain\Cluster registry does not.
 */
final class NodeFlows {
	/** Flow bits, as NodeRegistry::FLOW_* on MAIN (plan, section 12). */
	public const TELEMETRY = 1;
	public const COMMANDS = 2;
	public const LOGS = 4;
	public const STREAMS = 8;
	public const CONTENT = 16;
	public const CONFIG = 32;
	public const CONNECTIONS = 64;
	public const DATAPLANE = 128;

	/** @var array{mode: int, flows: int, state: string}|null */
	private static ?array $rCache = null;

	private static int $rReadAt = 0;

	private static ?string $rPath = null;

	/** Is a flow on for this node? */
	public static function on(int $rFlow): bool {
		$rNow = self::current();
		return $rNow['mode'] >= 1 && ($rNow['flows'] & $rFlow) === $rFlow && in_array($rNow['state'], ['active', 'quarantined'], true);
	}

	/** @return array{mode: int, flows: int, state: string} */
	public static function current(): array {
		if (self::$rCache === null || time() - self::$rReadAt >= 5) {
			self::$rCache = self::read();
			self::$rReadAt = time();
		}
		return self::$rCache;
	}

	/** Tests: read another file, and forget what was read. */
	public static function usePath(?string $rPath): void {
		self::$rPath = $rPath;
		self::$rCache = null;
	}

	/** @return array{mode: int, flows: int, state: string} */
	private static function read(): array {
		$rOff = ['mode' => 0, 'flows' => 0, 'state' => ''];
		$rPath = self::$rPath ?? (defined('CONFIG_PATH') ? CONFIG_PATH . 'cluster/flows.json' : null);
		if ($rPath === null || (self::$rPath === null && NodeRole::isMain())) {
			return $rOff;
		}
		$rDoc = json_decode((string) @file_get_contents($rPath), true);
		if (!is_array($rDoc)) {
			return $rOff;
		}
		return ['mode' => max(0, min(2, (int) ($rDoc['mode'] ?? 0))), 'flows' => (int) ($rDoc['flows'] ?? 0) & 255, 'state' => (string) ($rDoc['state'] ?? '')];
	}
}
