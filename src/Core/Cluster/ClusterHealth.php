<?php

namespace XcVm\Core\Cluster;

use XcVm\Core\Util\AtomicFile;

/**
 * Liveness of the nodes whose telemetry is authoritative, as MAIN's liveness
 * loop last judged it (`tmp/cluster/health.json`, written by
 * Domain\Cluster\LivenessService). Routing reads it:
 *
 * - `offline` — no new viewers are routed to the node;
 * - `suspended` — alive, but MAIN holds no licence and the node's token ends
 *   within LivenessService::LICENCE_CUTOFF_SEC: no new viewers either, so
 *   none lands on a node about to fence (plan section 4);
 * - `suspect` — its capacity weight is doubled, so it gets fewer;
 * - `ok` — it is online whatever its legacy last_check_ago says.
 *
 * Nodes not in the file (legacy nodes, the TELEMETRY flow off, every LB)
 * keep the legacy rule. In Core: ServerRepository and ConnectionTracker
 * ship to LBs, where the file simply does not exist.
 *
 * `guard` is the fleet guard, up while MAIN suspects its own fault; its
 * `reasons` say why (GUARD_REASONS), for the alert and the audit.
 */
final class ClusterHealth {
	/** Over half the judged nodes, and at least two, are silent together. */
	public const GUARD_SILENCE = 'silence';

	/** The cluster_ctl pool's listen queue has lasted over 5 s. */
	public const GUARD_CTL_QUEUE = 'ctl_queue';

	/** Every guard reason, in the order they are kept. */
	public const GUARD_REASONS = [self::GUARD_SILENCE, self::GUARD_CTL_QUEUE];

	/** @var array{states: array<int, string>, guard: bool, ok_since: array<int, int>, reasons: list<string>, ctl_queue: array{since: int, at: int}|null, ctl_queue_hold: array{from: int, until: int}|null}|null */
	private static ?array $rCache = null;

	private static float $rReadAt = 0.0;

	private static ?string $rPath = null;

	/** The states that route a node no new viewer. */
	public const NO_ROUTING = ['offline', 'suspended'];

	/** @return 'ok'|'suspect'|'offline'|'suspended'|null null when the node is not judged by the loop. */
	public static function state(int $rServerID): ?string {
		return self::read()['states'][$rServerID] ?? null;
	}

	/** Capacity weight: 2 for a suspect node, else 1. */
	public static function weight(int $rServerID): float {
		return self::state($rServerID) === 'suspect' ? 2.0 : 1.0;
	}

	/**
	 * `ok_since`, `ctl_queue` and `ctl_queue_hold` are the liveness loop's own
	 * bookkeeping: since when each node has been judged ok without a break
	 * (NodeHealth::settle); the cluster_ctl listen queue's run, since when it
	 * has lasted and when it was last seen; and that queue's hold on offline
	 * marking, which spares the nodes heard from `from` until `until` (MAIN's
	 * ms, LivenessService).
	 *
	 * @return array{states: array<int, string>, guard: bool, ok_since: array<int, int>, reasons: list<string>, ctl_queue: array{since: int, at: int}|null, ctl_queue_hold: array{from: int, until: int}|null}
	 */
	public static function read(): array {
		if (self::$rCache === null || microtime(true) - self::$rReadAt >= 1.0) {
			$rDoc = json_decode((string) @file_get_contents(self::path()), true);
			$rStates = [];
			foreach ((is_array($rDoc['states'] ?? null) ? $rDoc['states'] : []) as $rID => $rState) {
				if (in_array($rState, ['ok', 'suspect', 'offline', 'suspended'], true)) {
					$rStates[(int) $rID] = $rState;
				}
			}
			$rOkSince = [];
			foreach ((is_array($rDoc['ok_since'] ?? null) ? $rDoc['ok_since'] : []) as $rID => $rMs) {
				if (is_int($rMs)) {
					$rOkSince[(int) $rID] = $rMs;
				}
			}
			$rGuard = !empty($rDoc['guard']);
			$rQueue = $rDoc['ctl_queue'] ?? null;
			$rHold = $rDoc['ctl_queue_hold'] ?? null;
			self::$rCache = [
				'states' => $rStates, 'guard' => $rGuard, 'ok_since' => $rOkSince,
				'reasons' => self::reasons($rGuard, is_array($rDoc['reasons'] ?? null) ? $rDoc['reasons'] : []),
				'ctl_queue' => is_array($rQueue) && is_int($rQueue['since'] ?? null) && is_int($rQueue['at'] ?? null) ? ['since' => $rQueue['since'], 'at' => $rQueue['at']] : null,
				'ctl_queue_hold' => is_array($rHold) && is_int($rHold['from'] ?? null) && is_int($rHold['until'] ?? null) ? ['from' => $rHold['from'], 'until' => $rHold['until']] : null,
			];
			self::$rReadAt = microtime(true);
		}
		return self::$rCache;
	}

	/**
	 * @param array<int, string>                  $rStates
	 * @param array<int, int>                     $rOkSince
	 * @param list<string>                        $rReasons GUARD_REASONS; a guard without one is the fleet silence
	 * @param array{since: int, at: int}|null     $rCtlQueue
	 * @param array{from: int, until: int}|null   $rCtlQueueHold
	 */
	public static function write(array $rStates, bool $rGuard, array $rOkSince = [], array $rReasons = [], ?array $rCtlQueue = null, ?array $rCtlQueueHold = null): void {
		$rPath = self::path();
		if (!is_dir(dirname($rPath))) {
			@mkdir(dirname($rPath), 0750, true);
		}
		$rDoc = ['states' => $rStates, 'guard' => $rGuard, 'ok_since' => $rOkSince, 'reasons' => self::reasons($rGuard, $rReasons), 'ctl_queue' => $rCtlQueue, 'ctl_queue_hold' => $rCtlQueueHold];
		AtomicFile::write($rPath, (string) json_encode($rDoc));
		self::$rCache = $rDoc;
		self::$rReadAt = microtime(true);
	}

	/**
	 * The known reasons among $rReasons, in GUARD_REASONS' order; none
	 * without the guard. A guard without a reason, as written before the
	 * listen queue had its own, is the fleet silence.
	 *
	 * @param array<mixed> $rReasons
	 * @return list<string>
	 */
	private static function reasons(bool $rGuard, array $rReasons): array {
		if (!$rGuard) {
			return [];
		}
		$rKnown = array_values(array_intersect(self::GUARD_REASONS, array_filter($rReasons, 'is_string')));
		return $rKnown === [] ? [self::GUARD_SILENCE] : $rKnown;
	}

	/** Tests: use another file, and forget what was read. */
	public static function usePath(?string $rPath): void {
		self::$rPath = $rPath;
		self::$rCache = null;
	}

	private static function path(): string {
		return self::$rPath ?? ((defined('TMP_PATH') ? TMP_PATH : sys_get_temp_dir() . '/') . 'cluster/health.json');
	}
}
