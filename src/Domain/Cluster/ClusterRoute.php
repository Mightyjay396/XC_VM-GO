<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\Crypto\ClusterCrypto;
use XcVm\Core\Cluster\Crypto\ClusterCryptoFactory;
use XcVm\Core\Config\SettingsManager;

/**
 * Where MAIN's calls to a node go: the node's signed command channel when its
 * COMMANDS flow is on, else the legacy transport (the caller's own). Core's
 * seams (NodeRpc, SignalDispatcher) ask here first; on load balancers this
 * class is not in the build and they go legacy directly.
 *
 * Every method returns [routed, result]: routed false means "not a command
 * node, use the legacy path".
 */
final class ClusterRoute {
	/** @var (callable(): ClusterCrypto)|null */
	private static $rCrypto = null;

	/**
	 * An RPC answered by the node: `node.rpc{action}`, waiting for its ack.
	 *
	 * @param array<string, mixed> $rData NodeRpc payload (action + args)
	 * @return array{0: bool, 1: mixed} the node's raw result, or null on failure/timeout
	 */
	public static function rpc(int $rServerID, array $rData, int $rTimeout): array {
		$rCrypto = self::target($rServerID);
		if ($rCrypto === null) {
			return [false, null];
		}
		try {
			$rCmdID = CommandBus::enqueue($rCrypto, $rServerID, 'node.rpc', $rData);
		} catch (\Throwable) {
			return [true, null];
		}
		$rOutcome = CommandBus::await($rCmdID, max(1, $rTimeout));
		return [true, $rOutcome !== null && $rOutcome[0] ? $rOutcome[1] : null];
	}

	/**
	 * An RPC sent without waiting (NodeRpc::broadcast).
	 *
	 * @param array<string, mixed> $rData
	 * @return array{0: bool, 1: bool} queued?
	 */
	public static function send(int $rServerID, array $rData): array {
		$rCrypto = self::target($rServerID);
		if ($rCrypto === null) {
			return [false, false];
		}
		try {
			CommandBus::enqueue($rCrypto, $rServerID, 'node.rpc', $rData);
			return [true, true];
		} catch (\Throwable) {
			return [true, false];
		}
	}

	/**
	 * Kill a viewer's worker on its node (SignalDispatcher::kill): restrictive,
	 * so it is signed even without a licence.
	 *
	 * @return array{0: bool, 1: bool}
	 */
	public static function kill(int $rServerID, int $rPID, bool $rRTMP): array {
		$rCrypto = self::target($rServerID);
		if ($rCrypto === null) {
			return [false, false];
		}
		try {
			CommandBus::enqueue($rCrypto, $rServerID, 'conn.kill_worker', ['pid' => $rPID, 'rtmp' => $rRTMP]);
			return [true, true];
		} catch (\Throwable) {
			return [true, false];
		}
	}

	/** Tests: supply the extension handle. Null restores the factory. */
	public static function useCrypto(?callable $rFactory): void {
		self::$rCrypto = $rFactory;
	}

	/** The extension, when this node takes commands; null for the legacy path. */
	private static function target(int $rServerID): ?ClusterCrypto {
		try {
			if (empty(SettingsManager::get('cluster_api_enabled')) || !CommandBus::accepts(NodeRegistry::byServer($rServerID))) {
				return null;
			}
			return self::$rCrypto !== null ? (self::$rCrypto)() : ClusterCryptoFactory::create();
		} catch (\Throwable) {
			return null; // no cluster tables, no extension: the legacy path
		}
	}
}
