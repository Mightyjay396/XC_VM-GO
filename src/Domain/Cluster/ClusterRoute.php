<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\CacheJobs;
use XcVm\Core\Cluster\Crypto\ClusterCrypto;
use XcVm\Core\Cluster\Crypto\ClusterCryptoFactory;
use XcVm\Core\Config\SettingsManager;

/**
 * Where MAIN's calls to a node go: the node's signed command channel when its
 * COMMANDS flow is on, else the legacy transport (the caller's own). Core's
 * seams (NodeRpc, SignalDispatcher, ConnectionTracker's kills) ask here first; on load balancers this
 * class is not in the build and they go legacy directly.
 *
 * Every method returns [routed, result]: routed false means "not a command
 * node, use the legacy path".
 */
final class ClusterRoute {
	/** @var (callable(): ClusterCrypto)|null */
	private static $rCrypto;

	/**
	 * An RPC answered by the node: `node.rpc{action}`, waiting for its ack.
	 *
	 * @param array<string, mixed> $rData NodeRpc payload (action + args)
	 * @return array{0: bool, 1: mixed} the node's raw result, or null on failure/timeout
	 */
	public static function rpc(int $rServerID, array $rData, int $rTimeout): array {
		$rCrypto = self::target($rServerID);
		if (!$rCrypto instanceof \XcVm\Core\Cluster\Crypto\ClusterCrypto) {
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
		if (!$rCrypto instanceof \XcVm\Core\Cluster\Crypto\ClusterCrypto) {
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
		if (!$rCrypto instanceof \XcVm\Core\Cluster\Crypto\ClusterCrypto) {
			return [false, false];
		}
		try {
			CommandBus::enqueue($rCrypto, $rServerID, 'conn.kill_worker', ['pid' => $rPID, 'rtmp' => $rRTMP]);
			return [true, true];
		} catch (\Throwable) {
			return [true, false];
		}
	}

	/**
	 * Drop a viewer the node's fanout serves (a daemon viewer has no worker
	 * pid): `conn.drop {uuid}`, run by the node's agent against its fanout.
	 * Restrictive, like kill.
	 *
	 * @return array{0: bool, 1: bool}
	 */
	public static function drop(int $rServerID, string $rUUID): array {
		if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $rUUID)) {
			return [false, false];
		}
		$rCrypto = self::target($rServerID);
		if (!$rCrypto instanceof \XcVm\Core\Cluster\Crypto\ClusterCrypto) {
			return [false, false];
		}
		try {
			CommandBus::enqueue($rCrypto, $rServerID, 'conn.drop', ['uuid' => $rUUID], 'drop:' . $rUUID);
			return [true, true];
		} catch (\Throwable) {
			return [true, false];
		}
	}

	/**
	 * A close MAIN made to a connection another node's agent holds (its
	 * CONNECTIONS flow is on): `conn.close {uuid, remove}`, so the node's
	 * registry follows and a kicked HLS viewer is not resumed there.
	 *
	 * @return array{0: bool, 1: bool}
	 */
	public static function closeConnection(int $rServerID, string $rUUID, bool $rRemove): array {
		if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $rUUID)) {
			return [false, false];
		}
		try {
			$rNode = NodeRegistry::byServer($rServerID);
		} catch (\Throwable) {
			return [false, false];
		}
		if ($rNode === null || ((int) $rNode['flows'] & NodeRegistry::FLOW_CONNECTIONS) === 0) {
			return [false, false];
		}
		$rCrypto = self::target($rServerID);
		if (!$rCrypto instanceof \XcVm\Core\Cluster\Crypto\ClusterCrypto) {
			return [false, false];
		}
		try {
			CommandBus::enqueue($rCrypto, $rServerID, 'conn.close', ['uuid' => $rUUID, 'remove' => $rRemove], 'close:' . $rUUID);
			return [true, true];
		} catch (\Throwable) {
			return [true, false];
		}
	}

	/**
	 * Cache jobs for a node in mode 2 (SignalDispatcher::cache and
	 * cacheBatch): its signals daemon reads no `signals` row (its connects
	 * to MAIN are refused), so they go as signed `node.cache {jobs}`
	 * commands, in order, none naming more than CacheJobs::MAX targets (a
	 * list of ids split across them: CacheJobs::commands), which the node's
	 * cluster:exec runs as the daemon ran the rows. A job not in the form
	 * the node runs (CacheJobs::job) is left out.
	 * Granting to the extension, which classes by type: without a licence
	 * nothing is sent. MAIN's own jobs, nodes in mode 0 or 1 and nodes that
	 * take no command keep the legacy row.
	 *
	 * @param list<array<string, mixed>> $rJobs payloads as the signals rows carried them
	 * @return array{0: bool, 1: bool}
	 */
	public static function cache(int $rServerID, array $rJobs): array {
		if (defined('SERVER_ID') && $rServerID === (int) SERVER_ID) {
			return [false, false];
		}
		try {
			$rNode = empty(SettingsManager::get('cluster_api_enabled')) ? null : NodeRegistry::byServer($rServerID);
		} catch (\Throwable) {
			return [false, false];
		}
		if ($rNode === null || (int) $rNode['mode'] !== 2) {
			return [false, false];
		}
		$rCrypto = self::target($rServerID, false, $rNode);
		if (!$rCrypto instanceof \XcVm\Core\Cluster\Crypto\ClusterCrypto) {
			return [false, false];
		}
		$rCommands = CacheJobs::commands($rJobs);
		if ($rCommands === []) {
			return [true, false];
		}
		try {
			foreach ($rCommands as $rCommand) {
				CommandBus::enqueue($rCrypto, $rServerID, 'node.cache', ['jobs' => $rCommand]);
			}
			return [true, true];
		} catch (\Throwable) {
			return [true, false];
		}
	}

	/**
	 * A root action (NodeActions): a signed `node.root` command, run on the
	 * node by cluster:root after it checks the signature against its
	 * root-owned pin of the panel key. Only for nodes that report that pin.
	 * An action that needs a file of MAIN's carries its artefact grant
	 * (ArtefactGrants::forRoot); one that cannot have it is not sent.
	 *
	 * @param array<string, mixed> $rPayload {action, …} as the signals row carried it
	 * @return array{0: bool, 1: bool}
	 */
	public static function root(int $rServerID, array $rPayload): array {
		$rCrypto = self::target($rServerID, true);
		if (!$rCrypto instanceof \XcVm\Core\Cluster\Crypto\ClusterCrypto) {
			return [false, false];
		}
		try {
			$rPayload = ArtefactGrants::forRoot($rServerID, $rPayload);
			if ($rPayload === null) {
				return [true, false];
			}
			CommandBus::enqueue($rCrypto, $rServerID, 'node.root', $rPayload);
			return [true, true];
		} catch (\Throwable) {
			return [true, false];
		}
	}

	/** Tests: supply the extension handle. Null restores the factory. */
	public static function useCrypto(?callable $rFactory): void {
		self::$rCrypto = $rFactory;
	}

	/**
	 * The extension, when this node takes commands; null for the legacy path.
	 *
	 * @param array<string, mixed>|null $rNode the node's row, when the caller read it with cluster_api_enabled on
	 */
	private static function target(int $rServerID, bool $rRoot = false, ?array $rNode = null): ?ClusterCrypto {
		try {
			$rNode ??= empty(SettingsManager::get('cluster_api_enabled')) ? null : NodeRegistry::byServer($rServerID);
			if (!($rRoot ? CommandBus::acceptsRoot($rNode) : CommandBus::accepts($rNode))) {
				return null;
			}
			return self::$rCrypto !== null ? (self::$rCrypto)() : ClusterCryptoFactory::create();
		} catch (\Throwable) {
			return null; // no cluster tables, no extension: the legacy path
		}
	}
}
