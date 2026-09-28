<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\Crypto\ClusterCrypto;
use XcVm\Core\Cluster\Crypto\ClusterRefusedException;
use XcVm\Core\Cluster\Crypto\Enc;
use XcVm\Core\Cluster\Crypto\Seal;
use XcVm\Core\Cluster\Crypto\Ticket;
use XcVm\Core\Cluster\DataPlane;
use XcVm\Core\Cluster\FileTicketServer;
use XcVm\Core\Cluster\StrictQuery;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * MAIN mints the data plane's tickets (ADR 0004, Phase 8): what a node with
 * its DATAPLANE flow on needs to pull a stream from its parent, and the
 * files it reads from other servers, without a password in any URL.
 *
 * ```text
 * tickets = {"files": {"<ref>": "<fil wire>", …} | null, "relay": "<rly wire>" | null} | null
 * rly doc = {child_gen, child_sid, parent_sid, stream_id} + v, typ, tid, iat, exp
 * fil doc = {fetcher_gen, fetcher_sid, file, owner_sid, ref} + v, typ, tid, iat, exp
 * ```
 *
 * - **Where they go.** In the R2 `stream` record's `tickets` slot (a record
 *   sent anew carries the tickets of the moment), and on the `streams` op's
 *   delta path as a refresh (refresh()). A record's ETag is taken with the
 *   slot empty and tickets never bump a version (StreamVersions), so a
 *   refresh changes nothing a node compares: no resync resends the record,
 *   and no stream cache entry, and so no encoder, is rebuilt for it.
 * - **Who gets them.** A relay ticket when the node's own `streams_servers`
 *   row names a parent; a file ticket for each file the stream reads from
 *   another server (its `s:<sid>:<path>` sources: a movie, an episode, a
 *   created channel's items; its subtitles' location). Only for a parent or
 *   an owner that can check one: MAIN, or a node active in the node list
 *   (DataPlane::ticketable, which the node's URL builders use too).
 * - **When.** Each is valid from the start of its epoch (DataPlane::EPOCH,
 *   3 h): a relay ticket DataPlane::RELAY_LIFE, a file ticket
 *   DataPlane::FILE_LIFE. A node asks for the next epoch's as it begins.
 * - **The file's path** is never on the wire: `file` is the path sealed to
 *   the owner (XCVM-SEAL-v1, purpose `file`, context the ref) under its box
 *   key, or by MAIN to itself (`sealLocal`) when MAIN owns it.
 *
 * `rly` and `fil` grant, so without a licence nothing is minted: the record
 * is withheld with its tickets, and a refresh stops where it was.
 */
final class TicketService {
	use DatabaseAware;

	/** Held streams a refresh examines per call. */
	public const MAX_STREAMS = 1000;

	/** @var array{main: int, nodes: array<int, array{gen: int, box: string}>}|null the servers that check tickets, per request */
	private static ?array $rServers = null;

	/** Does this node get tickets: its DATAPLANE flow is on? */
	public static function wanted(array $rNode): bool {
		return ((int) ($rNode['flows'] ?? 0) & NodeRegistry::FLOW_DATAPLANE) !== 0;
	}

	/**
	 * A refresh request as the node sends it on a delta: `{epoch, from}`, the
	 * epoch of the tickets it holds (0: none) and the stream id to go on
	 * from. Null when absent; false when malformed.
	 *
	 * @return array{epoch: int, from: int}|false|null
	 */
	public static function request(mixed $rAsk): array|false|null {
		if ($rAsk === null) {
			return null;
		}
		if (!is_array($rAsk) || !is_int($rAsk['epoch'] ?? null) || !is_int($rAsk['from'] ?? 0) || $rAsk['epoch'] < 0 || ($rAsk['from'] ?? 0) < 0 || ($rAsk['from'] ?? 0) > StreamReplica::MAX_ID) {
			return false;
		}
		return ['epoch' => $rAsk['epoch'], 'from' => (int) ($rAsk['from'] ?? 0)];
	}

	/**
	 * The refresh a delta carries: the tickets of the held streams from
	 * `from` on, at most MAX_STREAMS examined, minted for the current epoch.
	 * Null when the node needs none (its flow is off, or it holds this
	 * epoch's and is not in the middle of a refresh).
	 *
	 * `streams` maps a stream id to its tickets; a held stream that needs
	 * none is left out. `next` is where to go on from, or null once every
	 * held stream was examined. `epoch` is the epoch minted, which the node
	 * holds once it has every page; without a licence it is the node's own
	 * (nothing past the first withheld stream is sent, `next` is null, and
	 * the node asks again at its next poll).
	 *
	 * @param array<string, mixed> $rNode cluster_nodes row
	 * @param array{epoch: int, from: int} $rAsk
	 * @return array{epoch: int, streams: object, next: ?int, withheld?: bool}|null
	 */
	public static function refresh(ClusterCrypto $rCrypto, array $rNode, array $rAsk, int $rNow): ?array {
		$rEpoch = DataPlane::epoch($rNow);
		if (!self::wanted($rNode) || ($rAsk['from'] === 0 && $rAsk['epoch'] >= $rEpoch)) {
			return null;
		}
		$rServerID = (int) $rNode['server_id'];
		$rHeld = StreamReplica::held($rServerID, null, $rAsk['from'], StreamReplica::MAX_ID, self::MAX_STREAMS + 1);
		$rNext = null;
		if (count($rHeld) > self::MAX_STREAMS) {
			$rNext = $rHeld[self::MAX_STREAMS];
			$rHeld = array_slice($rHeld, 0, self::MAX_STREAMS);
		}
		$rOut = [];
		foreach (StreamReplica::data($rServerID, $rHeld) as $rID => $rData) {
			try {
				$rTickets = self::forRecord($rCrypto, $rNode, $rData, $rNow);
			} catch (ClusterRefusedException $rE) {
				if ($rE->reason() !== 'LICENCE') {
					throw $rE;
				}
				return ['epoch' => $rAsk['epoch'], 'streams' => (object) $rOut, 'next' => null, 'withheld' => true];
			}
			if ($rTickets !== null) {
				$rOut[(string) $rID] = $rTickets;
			}
		}
		return ['epoch' => $rEpoch, 'streams' => (object) $rOut, 'next' => $rNext];
	}

	/**
	 * The tickets of one stream record for this node, or null when it needs
	 * none. Throws ClusterRefusedException when a ticket cannot be signed
	 * (LICENCE: the caller withholds the record).
	 *
	 * @param array<string, mixed> $rNode cluster_nodes row
	 * @param array<string, mixed> $rData the record's data (StreamRecords::data)
	 * @return array{files: array<string, string>|null, relay: string|null}|null
	 */
	public static function forRecord(ClusterCrypto $rCrypto, array $rNode, array $rData, int $rNow): ?array {
		if (!self::wanted($rNode)) {
			return null;
		}
		$rServerID = (int) $rNode['server_id'];
		$rGen = (int) $rNode['gen'];
		$rStreamID = (int) ($rData['stream']['id'] ?? 0);
		$rEpoch = DataPlane::epoch($rNow);
		$rIat = $rEpoch * DataPlane::EPOCH;
		$rServers = self::servers();
		$rRelay = null;
		$rParent = (int) ($rData['server']['parent_id'] ?? 0);
		if ($rStreamID > 0 && $rParent > 0 && $rParent !== $rServerID && ($rParent === $rServers['main'] || isset($rServers['nodes'][$rParent]))) {
			$rDoc = Ticket::document('rly', DataPlane::relayTid($rEpoch, $rServerID, $rStreamID), $rIat, $rIat + DataPlane::RELAY_LIFE, [
				'child_sid' => $rServerID, 'child_gen' => $rGen, 'parent_sid' => $rParent, 'stream_id' => $rStreamID,
			]);
			$rRelay = Ticket::wire($rDoc, $rCrypto->sign('rly', $rDoc));
		}
		$rFiles = [];
		foreach (self::files($rData, $rServerID) as [$rOwner, $rPath]) {
			$rRef = DataPlane::ref($rOwner, $rPath);
			if (isset($rFiles[$rRef])) {
				continue;
			}
			if ($rOwner === $rServers['main']) {
				$rFile = DataPlane::SEAL_MAIN . Enc::b64url($rCrypto->sealLocal(FileTicketServer::MAIN_SEAL, $rPath, $rRef));
			} elseif (isset($rServers['nodes'][$rOwner])) {
				$rFile = DataPlane::SEAL_NODE . Enc::b64url(Seal::seal($rServers['nodes'][$rOwner]['box'], DataPlane::SEAL_PURPOSE, $rRef, $rPath));
			} else {
				continue;
			}
			$rDoc = Ticket::document('fil', DataPlane::fileTid($rEpoch, $rServerID, $rRef), $rIat, $rIat + DataPlane::FILE_LIFE, [
				'fetcher_sid' => $rServerID, 'fetcher_gen' => $rGen, 'owner_sid' => $rOwner, 'ref' => $rRef, 'file' => $rFile,
			]);
			$rFiles[$rRef] = Ticket::wire($rDoc, $rCrypto->sign('fil', $rDoc));
		}
		if ($rRelay === null && $rFiles === []) {
			return null;
		}
		ksort($rFiles, SORT_STRING);
		return ['files' => $rFiles === [] ? null : $rFiles, 'relay' => $rRelay];
	}

	/**
	 * The files a stream reads from another server: [owner, path] for each
	 * `s:<sid>:<path>` source and each subtitle whose location is not this
	 * node.
	 *
	 * @param array<string, mixed> $rData
	 * @return list<array{0: int, 1: string}>
	 */
	public static function files(array $rData, int $rServerID): array {
		$rOut = [];
		$rSources = json_decode((string) ($rData['stream']['stream_source'] ?? ''), true);
		foreach (is_array($rSources) ? $rSources : [] as $rSource) {
			if (is_string($rSource) && str_starts_with($rSource, 's:')) {
				$rSplit = explode(':', $rSource, 3);
				if (count($rSplit) === 3 && ctype_digit($rSplit[1]) && (int) $rSplit[1] !== $rServerID && $rSplit[2] !== '') {
					$rOut[] = [(int) $rSplit[1], $rSplit[2]];
				}
			}
		}
		$rSubs = json_decode((string) ($rData['stream']['movie_subtitles'] ?? ''), true);
		$rLocation = is_array($rSubs) ? (int) ($rSubs['location'] ?? 0) : 0;
		if ($rLocation > 0 && $rLocation !== $rServerID && is_array($rSubs['files'] ?? null)) {
			foreach ($rSubs['files'] as $rFile) {
				if (is_string($rFile) && $rFile !== '') {
					$rOut[] = [$rLocation, $rFile];
				}
			}
		}
		return $rOut;
	}

	/**
	 * MAIN's server id and the nodes a ticket may name as parent or owner
	 * (active, with their box key), read once per request.
	 *
	 * @return array{main: int, nodes: array<int, array{gen: int, box: string}>}
	 */
	private static function servers(): array {
		if (self::$rServers !== null) {
			return self::$rServers;
		}
		StrictQuery::run(self::db(), 'streams', 'SELECT `id` FROM `servers` WHERE `is_main` = 1 ORDER BY `id` ASC LIMIT 1;');
		$rMain = (int) (self::db()->get_row()['id'] ?? 0);
		StrictQuery::run(self::db(), 'streams', "SELECT `server_id`, `gen`, `node_box_pub` FROM `cluster_nodes` WHERE `state` = 'active';");
		$rNodes = [];
		foreach (self::db()->get_rows() ?: [] as $rRow) {
			if (strlen((string) $rRow['node_box_pub']) === 32) {
				$rNodes[(int) $rRow['server_id']] = ['gen' => (int) $rRow['gen'], 'box' => (string) $rRow['node_box_pub']];
			}
		}
		return self::$rServers = ['main' => $rMain, 'nodes' => $rNodes];
	}

	/** Tests, and a long-lived process: read the servers again at the next mint. */
	public static function forget(): void {
		self::$rServers = null;
	}
}
