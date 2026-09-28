<?php

namespace XcVm\Core\Cluster;

/**
 * The data plane without bearer credentials (ADR 0004, Phase 8; plan,
 * section 7, "Local socket and data plane"): how a node's PHP names what it
 * pulls from another server once its DATAPLANE flow is on.
 *
 * ```text
 * relay  http://127.0.0.1:31290/relay/<k>/<stream id>.ts[?prebuffer=1]
 * file   http://127.0.0.1:31290/xfile/<k>/<ref>[.<ext>]
 * ```
 *
 * The node's agent listens there (relayproxy.go) and signs each upstream
 * connect: it picks the stream's current relay ticket, or the file ticket
 * whose `ref` this is, from the R2 streams section, and adds the node key's
 * `X-XCVM-Relay-Auth` (or `X-XCVM-File-Auth`). `k` is the loopback key the
 * agent keeps beside its state (`relay.key`, 0600): it never leaves the host
 * and unlocks only that listener, so a local user who reads an encoder's
 * command line holds nothing replayable. Neither URL changes when a ticket
 * does, so a refresh restarts no encoder.
 *
 * A server that cannot check a ticket keeps the legacy URL, password and all:
 * the parent or owner must be MAIN, or a node active in the signed node list
 * (the `servers` section's `nodes`). MAIN mints tickets for the same servers
 * only (TicketService), so both sides agree without asking each other.
 *
 * The ticket timing lives here because both sides compute it: MAIN mints
 * relay tickets valid RELAY_LIFE and file tickets FILE_LIFE from the start
 * of the EPOCH they are minted in, and a node asks for new ones at each new
 * epoch. The relay's refresh therefore always has hours to spare, and a file
 * ticket at least EPOCH.
 */
final class DataPlane {
	public const HOST = '127.0.0.1';
	public const PORT = 31290;

	/** The agent's loopback key, relative to the config directory. */
	public const KEY_FILE = AgentPaths::DIR . 'relay.key';

	/** A node asks for fresh tickets once per epoch (3 h). */
	public const EPOCH = 10800;

	/** A relay ticket's lifetime from its epoch's start (at most Ticket::KINDS' 24 h). */
	public const RELAY_LIFE = 86400;

	/** A file ticket's lifetime from its epoch's start (at most Ticket::KINDS' 6 h). */
	public const FILE_LIFE = 21600;

	/** A file ticket's owner-sealed path: to the owner node's box key, or sealed by MAIN to itself. */
	public const SEAL_PURPOSE = 'file';
	public const SEAL_NODE = 'n.';
	public const SEAL_MAIN = 'm.';

	private static ?string $rKeyFile = null;

	/** Does this node pull through its agent (its own DATAPLANE flow)? MAIN has no flows. */
	public static function on(): bool {
		return NodeFlows::on(NodeFlows::DATAPLANE);
	}

	/** The agent's loopback key, or null while it has written none. */
	public static function key(): ?string {
		$rKey = trim((string) @file_get_contents(self::$rKeyFile ?? AgentPaths::file(self::KEY_FILE)));
		return preg_match('/^[A-Za-z0-9_-]{22,64}\z/', $rKey) ? $rKey : null;
	}

	/** Tests: another key file; null restores the agent's. */
	public static function useKeyFile(?string $rPath): void {
		self::$rKeyFile = $rPath;
	}

	/** The epoch $rNow (unix seconds) is in. */
	public static function epoch(int $rNow): int {
		return intdiv($rNow, self::EPOCH);
	}

	/** A relay ticket's id: one per epoch, child and stream. */
	public static function relayTid(int $rEpoch, int $rChildSid, int $rStreamID): string {
		return 'r' . $rEpoch . '-' . $rChildSid . '-' . $rStreamID;
	}

	/** A file ticket's id: one per epoch, fetcher and file. */
	public static function fileTid(int $rEpoch, int $rFetcherSid, string $rRef): string {
		return 'f' . $rEpoch . '-' . $rFetcherSid . '-' . $rRef;
	}

	/**
	 * A file's stable name on the loopback: the same for every ticket that
	 * names it, so a URL an encoder holds outlives the ticket it was built
	 * with. It says nothing about the path.
	 */
	public static function ref(int $rOwnerSid, string $rPath): string {
		return substr(hash('sha256', 'xcvm-file-ref-v1' . pack('N', $rOwnerSid) . pack('N', strlen($rPath)) . $rPath), 0, 32);
	}

	/**
	 * Can $rServerID check a ticket: MAIN, or a node active in the signed
	 * node list? Null when the list cannot be read (the caller keeps the
	 * legacy URL, as before the data plane).
	 *
	 * @param array<int, array<string, mixed>> $rServers ServerRepository::getAll()
	 */
	public static function ticketable(array $rServers, int $rServerID): bool {
		if (!empty($rServers[$rServerID]['is_main'])) {
			return true;
		}
		$rNode = DataPlaneTrust::node($rServerID);
		return $rNode !== null && $rNode['state'] === 'active';
	}

	/**
	 * The URL a child pulls a stream from its parent with: the loopback relay
	 * with DATAPLANE on (and a parent that checks tickets), else the legacy
	 * `/admin/live` with the stream secret.
	 *
	 * @param array<int, array<string, mixed>> $rServers ServerRepository::getAll()
	 */
	public static function relayUrl(array $rServers, int $rParentID, int $rStreamID, string $rPassword, bool $rPrebuffer = false): string {
		if (self::on() && self::ticketable($rServers, $rParentID)) {
			return 'http://' . self::HOST . ':' . self::PORT . '/relay/' . (self::key() ?? 'none') . '/' . $rStreamID . '.ts' . ($rPrebuffer ? '?prebuffer=1' : '');
		}
		$rSelf = defined('SERVER_ID') ? ($rServers[SERVER_ID] ?? []) : [];
		$rParent = $rServers[$rParentID] ?? [];
		$rLoopURL = !is_null($rSelf['private_url_ip'] ?? null) && !is_null($rParent['private_url_ip'] ?? null) ? $rParent['private_url_ip'] : ($rParent['public_url_ip'] ?? '');
		return $rLoopURL . 'admin/live?stream=' . $rStreamID . '&password=' . urlencode($rPassword) . '&extension=ts' . ($rPrebuffer ? '&prebuffer=1' : '');
	}

	/**
	 * The URL a node reads a file another server holds with (a movie's
	 * source, its subtitles, a created channel's item): the loopback `/xfile`
	 * with DATAPLANE on (and an owner that checks tickets), else the legacy
	 * `getFile` of its `/api`.
	 *
	 * @param array<int, array<string, mixed>> $rServers ServerRepository::getAll()
	 */
	public static function fileUrl(array $rServers, int $rOwnerID, string $rPath): string {
		if (self::on() && self::ticketable($rServers, $rOwnerID)) {
			$rExt = strtolower((string) pathinfo($rPath, PATHINFO_EXTENSION));
			return 'http://' . self::HOST . ':' . self::PORT . '/xfile/' . (self::key() ?? 'none') . '/' . self::ref($rOwnerID, $rPath) . (preg_match('/^[a-z0-9]{1,8}\z/', $rExt) ? '.' . $rExt : '');
		}
		return ($rServers[$rOwnerID]['api_url'] ?? '') . '&action=getFile&filename=' . urlencode($rPath);
	}
}
