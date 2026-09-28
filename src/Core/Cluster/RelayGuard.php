<?php

namespace XcVm\Core\Cluster;

use XcVm\Core\Auth\AuthService;
use XcVm\Core\Cluster\Crypto\RelayAuth;
use XcVm\Core\Cluster\Crypto\Ticket;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Server\ServerRepository;

/**
 * The parent's gate on `/admin/{live,vod,timeshift,thumb}` (ADR 0004, Phase
 * 8): who may pull a stream from this server.
 *
 * - **A relay** carries `X-XCVM-Relay` (a panel-signed `rly` ticket) and
 *   `X-XCVM-Relay-Auth` (the child's node-key proof, per connect). It is
 *   admitted when the ticket verifies under the panel key, names this server
 *   as the parent and the stream asked for, and names a child that the
 *   signed node list has active at the ticket's generation, whose key signed
 *   this very request (method and target) within ±90 s, with a nonce this
 *   server had not seen. Checked at connect only. A request that carries the
 *   headers is judged by them alone: a bad ticket never falls back to the
 *   password.
 * - **The legacy password** (`password=<live_streaming_pass>` from a server's
 *   address) is admitted as before, except from a server whose own DATAPLANE
 *   flow is on: that child pulls through its agent, so a password arriving
 *   from it is not its own.
 *
 * Every check that cannot be made (no panel key, no node list, no nonce
 * window) refuses (DataPlaneTrust).
 */
final class RelayGuard {
	/** The request headers, as PHP names them in $_SERVER. */
	public const TICKET = 'HTTP_X_XCVM_RELAY';
	public const AUTH = 'HTTP_X_XCVM_RELAY_AUTH';

	public const RELAY = 'relay';
	public const PASSWORD = 'password';

	/** @var (callable(): array{0: array<int, array<string, mixed>>, 1: list<string>})|null */
	private static $rServers = null;

	/** Does the request present relay credentials? */
	public static function presented(array $rServer): bool {
		return isset($rServer[self::TICKET]) || isset($rServer[self::AUTH]);
	}

	/**
	 * Admit a request for $rStreamID: RELAY, PASSWORD, or null (refused).
	 *
	 * @param array<string, mixed> $rServer $_SERVER
	 * @param bool $rPasswordOk whether this endpoint takes the legacy password at all
	 */
	public static function admit(int $rStreamID, ?string $rPassword, string $rIP, array $rServer, bool $rPasswordOk = true, ?int $rNowMs = null): ?string {
		if (self::presented($rServer)) {
			return self::relay($rStreamID, $rServer, $rNowMs ?? (int) floor(microtime(true) * 1000)) !== null ? self::RELAY : null;
		}
		if (!$rPasswordOk || !AuthService::secretMatches((string) SettingsManager::get('live_streaming_pass'), $rPassword)) {
			return null;
		}
		return self::passwordAllowed($rIP) ? self::PASSWORD : null;
	}

	/**
	 * The child a relay request comes from (its server id), or null when any
	 * check fails.
	 *
	 * @param array<string, mixed> $rServer $_SERVER
	 */
	public static function relay(int $rStreamID, array $rServer, int $rNowMs): ?int {
		$rWire = $rServer[self::TICKET] ?? null;
		$rHeader = $rServer[self::AUTH] ?? null;
		$rPanel = DataPlaneTrust::panelPub();
		if (!is_string($rWire) || !is_string($rHeader) || $rPanel === null || $rStreamID <= 0 || !defined('SERVER_ID')) {
			return null;
		}
		$rTicket = Ticket::verify($rPanel, 'rly', $rWire, intdiv($rNowMs, 1000));
		if ($rTicket === null || ($rTicket['parent_sid'] ?? null) !== (int) SERVER_ID || ($rTicket['stream_id'] ?? null) !== $rStreamID
			|| !is_int($rTicket['child_sid'] ?? null) || !is_int($rTicket['child_gen'] ?? null)
		) {
			return null;
		}
		$rChild = self::child($rTicket['child_sid'], $rTicket['child_gen']);
		if ($rChild === null) {
			return null;
		}
		$rAuth = RelayAuth::verify($rChild['ed_pub'], $rHeader, $rWire, (string) ($rServer['REQUEST_METHOD'] ?? 'GET'), (string) ($rServer['REQUEST_URI'] ?? ''), $rNowMs);
		if ($rAuth === null || !DataPlaneTrust::spendNonce($rChild['sid'], $rAuth['nonce'], $rAuth['ts_ms'])) {
			return null;
		}
		return $rChild['sid'];
	}

	/**
	 * A child the signed node list has active at $rGen, or null. A revoked
	 * node, one re-enrolled since (a new generation), or one the list does
	 * not name holds no ticket that works.
	 *
	 * @return array{sid: int, gen: int, state: string, ed_pub: string, dataplane: bool}|null
	 */
	public static function child(int $rSid, int $rGen): ?array {
		$rNode = DataPlaneTrust::node($rSid);
		return $rNode !== null && $rNode['state'] === 'active' && $rNode['gen'] === $rGen ? $rNode : null;
	}

	/**
	 * May the legacy password come from $rIP: a server's address (as before),
	 * and not one whose DATAPLANE flow is on?
	 */
	public static function passwordAllowed(string $rIP): bool {
		[$rServers, $rAllowed] = self::$rServers !== null ? (self::$rServers)() : [ServerRepository::getAll(), ServerRepository::getAllowedIPs()];
		if ($rIP === '' || !in_array($rIP, $rAllowed)) {
			return false;
		}
		foreach ($rServers as $rID => $rRow) {
			if ($rIP !== ($rRow['server_ip'] ?? null) && $rIP !== ($rRow['private_ip'] ?? null)) {
				continue;
			}
			$rNode = DataPlaneTrust::node((int) $rID);
			if ($rNode !== null && $rNode['dataplane']) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Tests: another source of the servers and the allowed addresses; null
	 * restores the repository.
	 *
	 * @param (callable(): array{0: array<int, array<string, mixed>>, 1: list<string>})|null $rServers
	 */
	public static function useServers(?callable $rServers): void {
		self::$rServers = $rServers;
	}
}
