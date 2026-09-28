<?php

namespace XcVm\Core\Cluster;

use XcVm\Core\Cluster\Crypto\ClusterCrypto;
use XcVm\Core\Cluster\Crypto\ClusterCryptoFactory;
use XcVm\Core\Cluster\Crypto\FileDigest;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Domain\Cluster\NonceStore;

/**
 * What a server checking a data-plane request trusts, on either side of the
 * cluster (RelayGuard for relays, FileTicketServer for files):
 *
 * | | MAIN | a load balancer |
 * | --- | --- | --- |
 * | the panel key tickets verify under | `xcvm_core`'s own | the one its agent pinned (`agent.json`) |
 * | the node list (a child's key, generation, state, DATAPLANE) | `cluster_nodes` | the `servers` section's `nodes` |
 * | the nonce window | the cluster bus (`NonceStore`, node `relay:<sid>`) | its agent's (`POST /v1/nonce`) |
 * | who signs a file's digest | `xcvm_core` (tag `dig`) | its agent, with the node key |
 *
 * Every answer fails closed: no key, no list, no nonce window or no signer
 * is null or false, and the caller refuses the request. MAIN's half runs only
 * on MAIN's build (the load balancer build has no `Domain\Cluster`).
 */
final class DataPlaneTrust {
	/** How long a load balancer keeps the node list it read. */
	private const TTL = 5;

	/** @var array<int, array{sid: int, gen: int, state: string, ed_pub: string, dataplane: bool}>|null */
	private static ?array $rNodes = null;

	private static int $rReadAt = 0;

	/** @var (callable(int): ?array{sid: int, gen: int, state: string, ed_pub: string, dataplane: bool})|null */
	private static $rNodeSource = null;

	private static ?string $rPanelPub = null;

	/** @var (callable(int, string, int): bool)|null */
	private static $rNonceSource = null;

	/** @var (callable(string): ?string)|null */
	private static $rSigner = null;

	private static ?bool $rMain = null;

	private static ?string $rBoxSk = null;

	private static ?ClusterCrypto $rCrypto = null;

	/** Is this MAIN, with its cluster domain (tests: forced)? */
	public static function main(): bool {
		return self::$rMain ?? (NodeRole::mainBuild() && class_exists(NodeRegistry::class));
	}

	/**
	 * A node as the signed node list has it: its key (32 bytes), generation,
	 * state and whether its DATAPLANE flow is on. Null when the server is no
	 * node, or the list cannot be read.
	 *
	 * @return array{sid: int, gen: int, state: string, ed_pub: string, dataplane: bool}|null
	 */
	public static function node(int $rServerID): ?array {
		if (self::$rNodeSource !== null) {
			return (self::$rNodeSource)($rServerID);
		}
		if (self::main()) {
			if (!class_exists(NodeRegistry::class)) {
				return null;
			}
			try {
				$rRow = NodeRegistry::byServer($rServerID);
			} catch (\Throwable) {
				return null;
			}
			if ($rRow === null || strlen((string) $rRow['node_sign_pub']) !== 32) {
				return null;
			}
			return [
				'sid' => $rServerID, 'gen' => (int) $rRow['gen'], 'state' => (string) $rRow['state'], 'ed_pub' => (string) $rRow['node_sign_pub'],
				'dataplane' => ((int) $rRow['flows'] & NodeFlows::DATAPLANE) !== 0 && (int) $rRow['mode'] >= 1,
			];
		}
		if (self::$rNodes === null || time() - self::$rReadAt >= self::TTL) {
			self::$rNodes = self::replicaNodes();
			self::$rReadAt = time();
		}
		return self::$rNodes[$rServerID] ?? null;
	}

	/**
	 * The `servers` section's node list, by server id; [] when the agent
	 * stored none (or it does not read).
	 *
	 * @return array<int, array{sid: int, gen: int, state: string, ed_pub: string, dataplane: bool}>
	 */
	private static function replicaNodes(): array {
		$rDoc = ReplicaApply::whole(ReplicaSections::SERVERS);
		$rOut = [];
		foreach (is_array($rDoc) && is_array($rDoc['data']['nodes'] ?? null) ? $rDoc['data']['nodes'] : [] as $rEntry) {
			$rPub = is_string($rEntry['ed_pub'] ?? null) ? base64_decode($rEntry['ed_pub'], true) : false;
			if (!is_int($rEntry['sid'] ?? null) || !is_int($rEntry['gen'] ?? null) || !is_string($rEntry['state'] ?? null) || $rPub === false || strlen($rPub) !== 32) {
				continue;
			}
			$rOut[$rEntry['sid']] = ['sid' => $rEntry['sid'], 'gen' => $rEntry['gen'], 'state' => $rEntry['state'], 'ed_pub' => $rPub, 'dataplane' => ($rEntry['dataplane'] ?? false) === true];
		}
		return $rOut;
	}

	/**
	 * Now, in milliseconds, on MAIN's clock: the clock tickets and proofs are
	 * minted and signed by. MAIN's own; on a load balancer, MAIN's as its
	 * agent anchored it (NodeLease::mainNowMs), so a host clock set wrong
	 * neither admits an expired ticket nor refuses a fresh proof. A node
	 * whose agent has written no anchor falls back to its own clock.
	 */
	public static function nowMs(): int {
		$rLocal = (int) floor(microtime(true) * 1000);
		return self::main() ? $rLocal : (NodeLease::mainNowMs() ?? $rLocal);
	}

	/** The panel key tickets and MAIN's digests verify under, or null. */
	public static function panelPub(): ?string {
		if (self::$rPanelPub !== null) {
			return self::$rPanelPub;
		}
		if (self::main()) {
			try {
				$rPub = self::crypto()->info()['panel_sign_pub'] ?? null;
			} catch (\Throwable) {
				return null;
			}
			return is_string($rPub) && strlen($rPub) === 32 ? $rPub : null;
		}
		return ReplicaRecords::key32(AgentPaths::readState(), 'panel_sign_pub');
	}

	/**
	 * A path MAIN sealed to itself (a file ticket naming a file MAIN owns),
	 * or null: never off MAIN.
	 */
	public static function openMain(string $rBlob, string $rRef, string $rPurpose): ?string {
		if (!self::main()) {
			return null;
		}
		try {
			return self::crypto()->openLocal($rPurpose, $rBlob, $rRef);
		} catch (\Throwable) {
			return null;
		}
	}

	/** MAIN's cluster crypto (`xcvm_core`), or the one a test set. */
	private static function crypto(): ClusterCrypto {
		return self::$rCrypto ?? ClusterCryptoFactory::create();
	}

	/** Tests and the interop harness: MAIN's crypto; null restores the extension's. */
	public static function useCrypto(?ClusterCrypto $rCrypto): void {
		self::$rCrypto = $rCrypto;
	}

	/** This load balancer's box secret key (`agent.json`), which opens what MAIN sealed to it; null on MAIN or without it. */
	public static function boxSecret(): ?string {
		if (self::$rBoxSk !== null) {
			return self::$rBoxSk;
		}
		return self::main() ? null : ReplicaRecords::key32(AgentPaths::readState(), 'node_box_sk');
	}

	/**
	 * Spend a relay or file nonce of a child's: true only when this server had
	 * not seen it and could record it.
	 */
	public static function spendNonce(int $rChildID, string $rNonce, int $rTsMs): bool {
		if (self::$rNonceSource !== null) {
			return (self::$rNonceSource)($rChildID, $rNonce, $rTsMs);
		}
		if (strlen($rNonce) !== 16) {
			return false;
		}
		if (self::main()) {
			if (!class_exists(NonceStore::class)) {
				return false;
			}
			try {
				return NonceStore::claim('relay:' . $rChildID, $rNonce, $rTsMs);
			} catch (\Throwable) {
				return false;
			}
		}
		return AgentDataPlane::nonceFresh(bin2hex($rNonce)) === true;
	}

	/**
	 * `X-XCVM-File-Digest` for a chunk this server serves: MAIN's is signed by
	 * `xcvm_core` (tag `dig`), a load balancer's by its agent with the node
	 * key. Null when it cannot be signed: the caller then serves nothing.
	 */
	public static function signDigest(string $rTid, int $rOwnerID, int $rOffset, int $rTotal, string $rBytes, int $rIat): ?string {
		$rSha = hash('sha256', $rBytes);
		if (self::main() || self::$rSigner !== null) {
			try {
				$rDoc = FileDigest::document($rTid, $rOwnerID, strlen($rBytes), $rSha, $rIat, $rOffset, $rTotal);
				$rSig = self::$rSigner !== null ? (self::$rSigner)($rDoc) : self::crypto()->sign('dig', $rDoc);
			} catch (\Throwable) {
				return null;
			}
			return $rSig === null ? null : FileDigest::header($rDoc, $rSig);
		}
		return AgentDataPlane::fileDigest($rTid, $rOwnerID, strlen($rBytes), $rSha, $rIat, $rOffset, $rTotal);
	}

	/**
	 * Tests: replace the sources. Each null restores its own; $rMain forces
	 * main().
	 *
	 * @param (callable(int): ?array{sid: int, gen: int, state: string, ed_pub: string, dataplane: bool})|null $rNodes
	 * @param (callable(int, string, int): bool)|null $rNonces
	 * @param (callable(string): ?string)|null $rSigner signs a digest document as the owner
	 * @param string|null $rBoxSk this node's box secret key
	 */
	public static function useSources(?callable $rNodes, ?string $rPanelPub, ?callable $rNonces, ?callable $rSigner = null, ?bool $rMain = null, ?string $rBoxSk = null): void {
		self::$rBoxSk = $rBoxSk;
		self::$rNodeSource = $rNodes;
		self::$rPanelPub = $rPanelPub;
		self::$rNonceSource = $rNonces;
		self::$rSigner = $rSigner;
		self::$rMain = $rMain;
		self::$rNodes = null;
	}
}
