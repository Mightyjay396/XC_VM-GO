<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\Crypto\ClusterCrypto;
use XcVm\Core\Cluster\Crypto\ClusterCryptoFactory;
use XcVm\Core\Cluster\NodeActions;
use XcVm\Core\Cluster\NodeCorePin;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * MAIN's side of pinning its panel key in each node's `xcvm_core`
 * (`core.pin`, the extension's ADR-002 "Pin"; ADR 0004, Phase 9): until a
 * node is pinned, its extension cannot judge a lease (`NOT_PINNED`), so its
 * fence and `license_valid()` fall back to the agent's file.
 *
 * A pin is `cluster_pack($install_id)`: an XCVT blob only that install opens,
 * for an hour, licence-gated. So MAIN records each node's install_id
 * (`cluster_nodes.install_id`, migration 053): the SSH install reads it, and a
 * node's root reports it.
 *
 * - **At an SSH install** LbInstallFlow::provisionCluster records the
 *   install_id, packs and pins over its own verified session, and records the
 *   pin here (recorded()).
 * - **Every other node** — enrolled by code, enrolled before this release, or
 *   whose MAIN's root changed — is pinned over the cluster API once it takes
 *   root commands (COMMANDS on, root's pin in place): cron:cluster (offer())
 *   or the Cluster Nodes page (request()) sends `node.root pin_core` with the
 *   pin when the install_id is known; otherwise without one, and root answers
 *   with its install_id, which the first ack of that answer (acked()) records
 *   before it sends the pin. Root pins only to the key its own pin trusts
 *   (NodeCorePin); the ack reports the pinned key's SHA-256, which is
 *   recorded when it is this panel's.
 *
 * Pin state lives in `cluster_meta` (`core_pin:<server_id>` = {gen, fp,
 * pinned_at, tried_at}): a re-enrolled node (a new generation) and a new panel
 * root (another fp) both read as not pinned and are offered again. A node that
 * failed is offered again after RETRY_SEC. packFor() is the same blob for
 * other senders (a panel key rotation re-pinning the fleet).
 *
 * @package XC_VM_Domain_Cluster
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class CorePins {
	use DatabaseAware;

	/** How long a node that did not end pinned waits before it is offered again. */
	public const RETRY_SEC = 3600;

	/** Nodes offered per cron:cluster pass. */
	public const PER_PASS = 20;

	private const META = 'core_pin:';

	/**
	 * The SHA-256 (hex) of this panel's signing key, as MAIN recorded it at
	 * cluster:init (ClusterMeta), or null before.
	 */
	public static function panelFp(): ?string {
		$rPub = base64_decode((string) ClusterMeta::get('panel_sign_pub'), true);
		return is_string($rPub) && strlen($rPub) === 32 ? hash('sha256', $rPub) : null;
	}

	/**
	 * A node's pin as MAIN knows it.
	 *
	 * @return array{gen: int, fp: string, pinned_at: int|null, tried_at: int|null}|null
	 */
	public static function state(int $rServerID): ?array {
		$rDoc = json_decode((string) ClusterMeta::get(self::META . $rServerID), true);
		if (!is_array($rDoc)) {
			return null;
		}
		return [
			'gen' => (int) ($rDoc['gen'] ?? 0),
			'fp' => (string) ($rDoc['fp'] ?? ''),
			'pinned_at' => isset($rDoc['pinned_at']) ? (int) $rDoc['pinned_at'] : null,
			'tried_at' => isset($rDoc['tried_at']) ? (int) $rDoc['tried_at'] : null,
		];
	}

	/**
	 * Is this node (its current generation) pinned to this panel's key?
	 *
	 * @param array<string, mixed> $rNode cluster_nodes row
	 */
	public static function current(array $rNode, ?string $rFp = null): bool {
		$rFp ??= self::panelFp();
		$rState = self::state((int) $rNode['server_id']);
		return $rFp !== null && $rState !== null && $rState['pinned_at'] !== null && $rState['gen'] === (int) $rNode['gen'] && hash_equals($rFp, $rState['fp']);
	}

	/** Record a pin the node reported for its current generation. */
	public static function recorded(int $rServerID, string $rFp, string $rActor = 'node'): bool {
		$rNode = NodeRegistry::byServer($rServerID);
		$rPanel = self::panelFp();
		if ($rNode === null || $rPanel === null || !hash_equals($rPanel, $rFp)) {
			return false;
		}
		self::write($rServerID, ['gen' => (int) $rNode['gen'], 'fp' => $rFp, 'pinned_at' => ClusterClock::now(), 'tried_at' => self::state($rServerID)['tried_at'] ?? null]);
		ClusterAudit::log('node.core_pinned', $rServerID, ['fp' => substr($rFp, 0, 16)], $rActor);
		return true;
	}

	/** An install_id as the extension makes them (8–64 of `[0-9A-Za-z-]`). */
	public static function validInstallId(mixed $rID): bool {
		return is_string($rID) && preg_match('/^[0-9A-Za-z-]{8,64}\z/', $rID) === 1;
	}

	/** The node's recorded install_id, or null (unknown, or a table before migration 053). */
	public static function installId(int $rServerID): ?string {
		try {
			self::db()->query('SELECT `install_id` FROM `cluster_nodes` WHERE `server_id` = ?;', $rServerID);
			$rID = self::db()->num_rows() > 0 ? self::db()->get_row()['install_id'] : null;
		} catch (\Throwable) {
			return null;
		}
		return self::validInstallId($rID) ? (string) $rID : null;
	}

	/** Record the node's install_id (SSH install, or root's report). */
	public static function rememberInstallId(int $rServerID, string $rID): bool {
		if (!self::validInstallId($rID)) {
			return false;
		}
		if (self::installId($rServerID) !== $rID) {
			NodeRegistry::update($rServerID, ['install_id' => $rID]);
		}
		return true;
	}

	/**
	 * The pin blob for this node (`cluster_pack` for its recorded install_id),
	 * or null while its install_id is unknown. Throws ClusterRefusedException
	 * when the extension refuses (no licence, its clock).
	 */
	public static function packFor(ClusterCrypto $rCrypto, int $rServerID): ?string {
		$rID = self::installId($rServerID);
		return $rID === null ? null : $rCrypto->pack($rID);
	}

	/**
	 * cron:cluster: offer the pin to nodes that take root commands and are not
	 * pinned to this panel's key, at most PER_PASS, each at most once per
	 * RETRY_SEC. Returns how many were sent a command.
	 *
	 * @param (callable(int, ?string): bool)|null $rSend tests: queue `pin_core` (with the blob, or without)
	 * @param (callable(string): string)|null $rPack tests: `cluster_pack`
	 */
	public static function offer(?callable $rSend = null, ?int $rNow = null, ?callable $rPack = null): int {
		$rFp = self::panelFp();
		if ($rFp === null) {
			return 0;
		}
		$rNow ??= ClusterClock::now();
		self::db()->query("SELECT * FROM `cluster_nodes` WHERE `state` = 'active' ORDER BY `server_id` ASC;");
		$rSent = 0;
		foreach (self::db()->get_rows() ?: [] as $rNode) {
			if ($rSent >= self::PER_PASS) {
				break;
			}
			$rServerID = (int) $rNode['server_id'];
			if (!CommandBus::acceptsRoot($rNode) || self::current($rNode, $rFp)) {
				continue;
			}
			$rState = self::state($rServerID);
			if ($rState !== null && $rState['gen'] === (int) $rNode['gen'] && $rState['tried_at'] !== null && $rNow - $rState['tried_at'] < self::RETRY_SEC) {
				continue;
			}
			self::sendDue($rNode, 'cron', $rNow, $rSend, $rPack);
			$rSent++;
		}
		return $rSent;
	}

	/**
	 * Send now (the Cluster Nodes page's action), whatever the retry wait:
	 * only to a node that takes root commands. Null when queued, else the
	 * message key of why not.
	 *
	 * @param (callable(int, ?string): bool)|null $rSend
	 * @param (callable(string): string)|null $rPack
	 */
	public static function request(int $rServerID, string $rActor = 'admin', ?callable $rSend = null, ?callable $rPack = null): ?string {
		$rNode = NodeRegistry::byServer($rServerID);
		if ($rNode === null || $rNode['state'] !== 'active') {
			return 'cluster_not_enrolled';
		}
		if (!CommandBus::acceptsRoot($rNode)) {
			return 'cluster_pin_core_no_root';
		}
		if (self::panelFp() === null) {
			return 'cluster_pin_core_no_root_key';
		}
		return self::sendDue($rNode, $rActor, ClusterClock::now(), $rSend, $rPack) ? null : 'cluster_pin_core_not_queued';
	}

	/**
	 * A `node.root` command's first ack (ClusterApi). For `pin_core`: an
	 * answer with the node's install_id records it and sends the pin; an
	 * answer with the pinned key's SHA-256 is recorded when it is this
	 * panel's. True when the node ended pinned to this panel's key.
	 *
	 * @param (callable(string): string)|null $rPack tests: `cluster_pack`
	 * @param (callable(int, ?string): bool)|null $rSend tests: queue `pin_core`
	 */
	public static function acked(int $rServerID, string $rCmdID, bool $rOk, string $rResult, ?callable $rPack = null, ?callable $rSend = null): bool {
		self::db()->query('SELECT `type`, `payload` FROM `cluster_commands` WHERE `server_id` = ? AND `cmd_id` = ?;', $rServerID, $rCmdID);
		$rRow = self::db()->num_rows() > 0 ? self::db()->get_raw_row() : null;
		$rDoc = $rRow !== null && $rRow['type'] === 'node.root' ? json_decode((string) $rRow['payload'], true) : null;
		if (!is_array($rDoc) || ($rDoc['action'] ?? null) !== NodeCorePin::ACTION) {
			return false;
		}
		$rStep = array_key_exists('blob', (array) ($rDoc['args'] ?? [])) ? 'pin' : 'install_id';
		if (!$rOk) {
			ClusterAudit::log('node.core_pin_failed', $rServerID, ['step' => $rStep, 'result' => mb_substr($rResult, 0, 300)], 'node');
			if ($rStep === 'pin' && str_contains($rResult, 'CRYPTO')) {
				// Not this install's blob (the machine was replaced, or the blob
				// outlived its hour): ask the node for its install_id again.
				NodeRegistry::update($rServerID, ['install_id' => null]);
			}
			return false;
		}
		$rCore = NodeCorePin::outcome($rResult);
		$rFp = self::panelFp();
		if ($rCore === null || $rFp === null) {
			return false; // root had not finished ({"queued": true}); cron offers it again
		}
		if (self::validInstallId($rCore['install_id'] ?? null)) {
			self::rememberInstallId($rServerID, (string) $rCore['install_id']);
		}
		if (is_string($rCore['pinned'] ?? null) && hash_equals($rFp, $rCore['pinned'])) {
			return self::recorded($rServerID, $rCore['pinned']);
		}
		if ($rStep === 'pin') {
			ClusterAudit::log('node.core_pin_failed', $rServerID, ['step' => 'pin', 'result' => 'pinned another key'], 'node');
			return false;
		}
		$rNode = NodeRegistry::byServer($rServerID);
		if ($rNode === null || self::installId($rServerID) === null) {
			ClusterAudit::log('node.core_pin_failed', $rServerID, ['step' => 'install_id', 'result' => 'no install_id'], 'node');
			return false;
		}
		self::sendDue($rNode, 'system', ClusterClock::now(), $rSend, $rPack);
		return false;
	}

	/**
	 * Send the step that is due: the pin when the node's install_id is known,
	 * else the question for it. Records the attempt and audits it. True when a
	 * command was queued.
	 *
	 * @param array<string, mixed> $rNode cluster_nodes row
	 * @param (callable(int, ?string): bool)|null $rSend
	 * @param (callable(string): string)|null $rPack
	 */
	private static function sendDue(array $rNode, string $rActor, int $rNow, ?callable $rSend, ?callable $rPack): bool {
		$rServerID = (int) $rNode['server_id'];
		$rSend ??= static fn(int $rSid, ?string $rBlob): bool => NodeActions::pinCore($rSid, $rBlob);
		$rPack ??= static fn(string $rInstallID): string => ClusterCryptoFactory::create()->pack($rInstallID);
		$rID = self::installId($rServerID);
		self::write($rServerID, ['gen' => (int) $rNode['gen'], 'fp' => '', 'pinned_at' => null, 'tried_at' => $rNow]);
		try {
			$rQueued = (bool) $rSend($rServerID, $rID === null ? null : $rPack($rID));
		} catch (\Throwable $rE) {
			ClusterAudit::log('node.core_pin_failed', $rServerID, ['step' => 'pack', 'result' => mb_substr($rE->getMessage(), 0, 300)], 'system');
			return false;
		}
		ClusterAudit::log('node.core_pin', $rServerID, ['step' => $rID === null ? 'install_id' : 'pin', 'queued' => $rQueued], $rActor);
		return $rQueued;
	}

	/** @param array{gen: int, fp: string, pinned_at: int|null, tried_at: int|null} $rState */
	private static function write(int $rServerID, array $rState): void {
		ClusterMeta::set(self::META . $rServerID, (string) json_encode($rState));
	}
}
