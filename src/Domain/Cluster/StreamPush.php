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
 */
final class StreamPush {
	use DatabaseAware;

	/** @var array<int, true> server ids to tell */
	private static array $rPending = [];

	private static bool $rAll = false;

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
		$rAll = self::$rAll;
		self::$rPending = [];
		self::$rAll = false;
		if ($rIDs === [] && !$rAll) {
			return 0;
		}
		try {
			$rCrypto ??= ClusterCryptoFactory::create();
			self::db()->query("SELECT * FROM `cluster_nodes` WHERE `state` = 'active'" . ($rAll ? '' : ' AND `server_id` IN (' . implode(',', array_map('intval', $rIDs)) . ')') . ';');
			$rNodes = self::db()->get_rows() ?: [];
		} catch (\Throwable) {
			return 0;
		}
		$rSent = 0;
		foreach ($rNodes as $rNode) {
			if (((int) ($rNode['flows'] ?? 0) & NodeRegistry::FLOW_STREAMS) === 0 || !CommandBus::accepts($rNode) || !in_array(ReplicaBuilder::FEATURE_CONFIG_CHANGED, explode(',', (string) ($rNode['features'] ?? '')), true)) {
				continue;
			}
			try {
				CommandBus::enqueue($rCrypto, (int) $rNode['server_id'], 'config.changed', ['sections' => [ReplicaSections::STREAMS]], 'config.changed');
				$rSent++;
			} catch (\Throwable) {
				// This node takes the change at its next delta.
			}
		}
		return $rSent;
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
