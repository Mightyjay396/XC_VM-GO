<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\Crypto\ClusterCrypto;
use XcVm\Core\Cluster\Crypto\ClusterCryptoFactory;
use XcVm\Core\Cluster\ReplicaSections;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * Wakes the nodes whose R2 `streams` section changed (plan, section 9: R2 "on
 * change"). StreamVersions records the change, and a node used to see it at
 * its next delta, within a minute. Every server a change stamped during this
 * request is told once, when the request ends, with `config.changed
 * {sections: [streams]}`: its agent syncs the replica and takes a streams
 * delta at once. A mass edit of many streams is still one command per node,
 * and a later change in the same request cannot land after the node's sync.
 *
 * Only an active node whose STREAMS flow is on (it reads the section) and whose
 * agent takes the command (CommandBus::accepts, the `config_changed` feature).
 * The command is restrictive, so it signs without a licence, and a newer one
 * supersedes one not yet acked. Never fails the change: a node that cannot be
 * told takes the change at its next delta.
 *
 * Encoding work MAIN queued onto a node (QueueSink) pokes it the same way:
 * `queue.poke` to an active node whose CONTENT flow is on (it asks MAIN for its
 * queue) and that takes commands, so its daemon's pass comes at once rather
 * than within `queue_loop`. Granting, so a MAIN without a licence sends none.
 */
final class StreamPush {
	use DatabaseAware;

	/** @var array<int, true> server ids to tell */
	private static array $rPending = [];

	private static bool $rAll = false;

	/** @var array<int, true> server ids whose queue MAIN wrote */
	private static array $rQueued = [];

	private static bool $rRegistered = false;

	/** @param list<int> $rServerIDs the servers a change stamped */
	public static function changed(array $rServerIDs): void {
		foreach ($rServerIDs as $rServerID) {
			if ((int) $rServerID > 0) {
				self::$rPending[(int) $rServerID] = true;
			}
		}
		self::register();
	}

	/** @param list<int> $rServerIDs servers MAIN queued encoding work for */
	public static function queued(array $rServerIDs): void {
		foreach ($rServerIDs as $rServerID) {
			if ((int) $rServerID > 0) {
				self::$rQueued[(int) $rServerID] = true;
			}
		}
		self::register();
	}

	/** Every stream changed at once (StreamVersions::reset()): every node is told. */
	public static function changedAll(): void {
		self::$rAll = true;
		self::register();
	}

	/**
	 * Tell the nodes changed so far, and forget them.
	 *
	 * @return int the nodes told
	 */
	public static function flush(?ClusterCrypto $rCrypto = null): int {
		$rIDs = array_keys(self::$rPending);
		$rQueued = self::$rQueued;
		$rAll = self::$rAll;
		self::$rPending = self::$rQueued = [];
		self::$rAll = false;
		if ($rIDs === [] && $rQueued === [] && !$rAll) {
			return 0;
		}
		$rWanted = array_keys($rQueued + array_flip($rIDs));
		try {
			$rCrypto ??= ClusterCryptoFactory::create();
			self::db()->query("SELECT * FROM `cluster_nodes` WHERE `state` = 'active'" . ($rAll ? '' : ' AND `server_id` IN (' . implode(',', array_map('intval', $rWanted)) . ')') . ';');
			$rNodes = self::db()->get_rows() ?: [];
		} catch (\Throwable) {
			return 0;
		}
		$rSent = 0;
		foreach ($rNodes as $rNode) {
			$rServerID = (int) $rNode['server_id'];
			$rFlows = (int) ($rNode['flows'] ?? 0);
			if (!CommandBus::accepts($rNode)) {
				continue;
			}
			// The streams section, for a node that reads it and whose agent takes config.changed.
			if (($rAll || in_array($rServerID, $rIDs, true)) && ($rFlows & NodeRegistry::FLOW_STREAMS) !== 0 && in_array(ReplicaBuilder::FEATURE_CONFIG_CHANGED, explode(',', (string) ($rNode['features'] ?? '')), true)) {
				$rSent += self::send($rCrypto, $rServerID, 'config.changed', ['sections' => [ReplicaSections::STREAMS]]);
			}
			// The queue, for a node that asks MAIN for it.
			if (isset($rQueued[$rServerID]) && ($rFlows & NodeRegistry::FLOW_CONTENT) !== 0) {
				$rSent += self::send($rCrypto, $rServerID, 'queue.poke', []);
			}
		}
		return $rSent;
	}

	/** Queue one command (its type is its dedupe key); 0 when it could not be: the node's next pass takes the change. */
	private static function send(ClusterCrypto $rCrypto, int $rServerID, string $rType, array $rArgs): int {
		try {
			CommandBus::enqueue($rCrypto, $rServerID, $rType, $rArgs, $rType);
			return 1;
		} catch (\Throwable) {
			return 0;
		}
	}

	private static function register(): void {
		if (!self::$rRegistered) {
			self::$rRegistered = true;
			register_shutdown_function(static function (): void {
				self::flush();
			});
		}
	}
}
