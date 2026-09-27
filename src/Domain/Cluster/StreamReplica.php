<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\Crypto\ClusterCrypto;
use XcVm\Core\Cluster\Crypto\ClusterRefusedException;
use XcVm\Core\Cluster\ReplicaSections;
use XcVm\Core\Cluster\StreamVersions;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * The node replica's R2 `streams` section on MAIN (plan, section 9): one
 * `stream` record per stream a node holds (ReplicaSections), panel-signed
 * (`rep`) and sealed to the node like the whole sections, but never sent
 * whole. The `streams` op serves it two ways:
 *
 * - **Delta** (delta()): what changed past the node's cursor, by the
 *   versions StreamVersions stamps in `cluster_stream_ver`. A row whose
 *   stream the node still holds is its record at the row's version; any
 *   other is a removal. The cursor is the version of the last row served:
 *   bumps commit in the order of their versions, so the node has everything
 *   up to it. A cursor of 0 (nothing held), below the node's floor (every
 *   stream changed at once, or versions pruned) or above the head (MAIN's
 *   versions went back) is answered `full`: the node checks every stream.
 * - **Resync** (resync()): the section hashes. The node names the ETag of
 *   each stream it holds in a range of ids; MAIN compares them with every
 *   stream the node holds in that range and answers only the records that
 *   differ, and the removals. It is the safety net for what no version
 *   marks (a writer that dispatches nothing, a restore), every 5 minutes,
 *   and how a node takes the whole section at first (`full`).
 *
 * A record names the node, its generation and the stream, so it opens and
 * verifies only there; its ETag is the SHA-256 of its canonical data, as a
 * whole section's. It grants (a stream to run), so without a licence it is
 * not signed: it is left out and counted in `withheld`, the removals still
 * go, and a delta's cursor stops before it. Every read throws when it fails,
 * so the op answers `503 DB` instead of signing a partial section.
 */
final class StreamReplica {
	use DatabaseAware;

	/**
	 * What an agent says at hello (`features`) when it keeps the R2 streams
	 * section: only those agents are served the op.
	 */
	public const FEATURE = 'streams';

	/** Records per reply; and at most MAX_BYTES of them (base64), at least one. */
	public const MAX_RECORDS = 200;
	public const MAX_BYTES = 4194304;

	/** Version rows a delta reads per call. */
	public const MAX_ROWS = 1000;

	/** Streams a resync compares per call, and the hashes a request may carry. */
	public const MAX_EXAMINE = 1000;
	public const MAX_HASHES = 2000;

	/** The highest stream id (int(11)). */
	public const MAX_ID = 2147483647;

	/** A version row no node holds is kept this long; then it goes, the node's floor raised first. */
	public const KEEP_DAYS = 7;

	/** Rows cron:cluster looks at per run. */
	public const PRUNE_ROWS = 10000;

	/**
	 * Is the op served to this node: its STREAMS flow is on, and its agent
	 * said FEATURE at hello (an older agent is never sent the section).
	 *
	 * @param array<string, mixed> $rNode
	 * @return string|null null when served, else what is missing ('flow' or 'feature')
	 */
	public static function refused(array $rNode): ?string {
		if (((int) ($rNode['flows'] ?? 0) & NodeRegistry::FLOW_STREAMS) === 0) {
			return 'flow';
		}
		return in_array(self::FEATURE, explode(',', (string) ($rNode['features'] ?? '')), true) ? null : 'feature';
	}

	/**
	 * A resync request as the node sends it: `{from, to, hashes: {"<id>": etag}}`,
	 * ids within from..to, at most MAX_HASHES. Null when malformed.
	 *
	 * @return array{from: int, to: int, hashes: array<int, string>}|null
	 */
	public static function resyncRequest(mixed $rAsk): ?array {
		if (!is_array($rAsk) || !is_int($rAsk['from'] ?? null) || !is_int($rAsk['to'] ?? null) || !is_array($rAsk['hashes'] ?? null)) {
			return null;
		}
		[$rFrom, $rTo, $rHashes] = [$rAsk['from'], $rAsk['to'], $rAsk['hashes']];
		if ($rFrom < 0 || $rTo < $rFrom || $rTo > self::MAX_ID || count($rHashes) > self::MAX_HASHES) {
			return null;
		}
		$rOut = [];
		foreach ($rHashes as $rID => $rEtag) {
			if (!is_int($rID) || $rID < $rFrom || $rID > $rTo || !is_string($rEtag) || !preg_match('/^[0-9a-f]{64}$/', $rEtag)) {
				return null;
			}
			$rOut[$rID] = $rEtag;
		}
		return ['from' => $rFrom, 'to' => $rTo, 'hashes' => $rOut];
	}

	/**
	 * What changed past the node's cursor.
	 *
	 * @param array<string, mixed> $rNode cluster_nodes row
	 * @return array{ver: int, head: int, more: bool, full?: bool, streams: list<array{id: int, ver: int, etag: string, sealed: string}>, removed: list<int>, withheld?: int}
	 */
	public static function delta(ClusterCrypto $rCrypto, array $rNode, int $rSince): array {
		$rServerID = (int) $rNode['server_id'];
		$rHead = StreamVersions::head(self::db());
		if ($rSince <= 0 || $rSince < StreamVersions::floor($rServerID, self::db()) || $rSince > $rHead) {
			return ['ver' => $rSince, 'head' => $rHead, 'more' => false, 'full' => true, 'streams' => [], 'removed' => []];
		}
		self::read('SELECT `stream_id`, `ver` FROM `cluster_stream_ver` WHERE `server_id` = ? AND `ver` > ? ORDER BY `ver` ASC LIMIT ' . self::MAX_ROWS . ';', $rServerID, $rSince);
		$rRows = array_map(static fn(array $rRow): array => [(int) $rRow['stream_id'], (int) $rRow['ver']], self::db()->get_rows() ?: []);
		$rData = self::data($rServerID, self::held($rServerID, array_column($rRows, 0)));
		$rPage = self::page($rCrypto, $rNode, $rRows, $rData, null);
		$rDone = $rPage['done'];
		$rOut = [
			'ver' => $rDone > 0 ? $rRows[$rDone - 1][1] : $rSince, 'head' => $rHead,
			'more' => $rDone < count($rRows) ? !$rPage['withheld'] : count($rRows) >= self::MAX_ROWS,
			'streams' => $rPage['streams'], 'removed' => $rPage['removed'],
		];
		return $rOut + ($rPage['withheld'] > 0 ? ['withheld' => $rPage['withheld']] : []);
	}

	/**
	 * The section hashes: the records of the streams the node holds in
	 * from..to whose ETag differs from the one it names, and the removals of
	 * those it names that it does not hold. `next` is where to go on from
	 * when the reply could not cover the range, else null. The node's cursor
	 * is left as it was.
	 *
	 * @param array<string, mixed> $rNode cluster_nodes row
	 * @param array<int, string> $rHashes stream id => the ETag the node holds
	 * @return array{ver: int, head: int, next: ?int, streams: list<array{id: int, ver: int, etag: string, sealed: string}>, removed: list<int>, withheld?: int}
	 */
	public static function resync(ClusterCrypto $rCrypto, array $rNode, int $rSince, int $rFrom, int $rTo, array $rHashes): array {
		$rServerID = (int) $rNode['server_id'];
		$rHead = StreamVersions::head(self::db());
		$rHeld = self::held($rServerID, null, $rFrom, $rTo, self::MAX_EXAMINE + 1);
		$rNext = null;
		if (count($rHeld) > self::MAX_EXAMINE) {
			$rHeld = array_slice($rHeld, 0, self::MAX_EXAMINE);
			$rNext = (int) end($rHeld) + 1;
		}
		$rBound = $rNext === null ? $rTo : $rNext - 1;
		$rIDs = array_merge($rHeld, array_filter(array_keys($rHashes), static fn(int $rID): bool => $rID <= $rBound));
		$rIDs = array_values(array_unique($rIDs));
		sort($rIDs);
		$rVers = self::versions($rServerID, $rHeld);
		$rItems = array_map(static fn(int $rID): array => [$rID, $rVers[$rID] ?? 0], $rIDs);
		$rPage = self::page($rCrypto, $rNode, $rItems, self::data($rServerID, $rHeld), $rHashes);
		if ($rPage['done'] < count($rItems)) {
			$rNext = $rItems[$rPage['done']][0];
		}
		$rOut = ['ver' => $rSince, 'head' => $rHead, 'next' => $rNext, 'streams' => $rPage['streams'], 'removed' => $rPage['removed']];
		return $rOut + ($rPage['withheld'] > 0 ? ['withheld' => $rPage['withheld']] : []);
	}

	/**
	 * The records of a page, in the order given: a stream the node holds is
	 * sent (unless it names its ETag in $rHave), one it no longer holds is a
	 * removal (in a resync, only one it names). `done` counts the items
	 * handled; the page stops at the first record past the reply's size, and
	 * a delta's at the first one that cannot be signed without a licence.
	 *
	 * @param list<array{0: int, 1: int}> $rItems [stream id, version]
	 * @param array<int, array<string, mixed>> $rData the held streams' data
	 * @param array<int, string>|null $rHave the node's ETags (a resync), null for a delta
	 * @return array{streams: list<array{id: int, ver: int, etag: string, sealed: string}>, removed: list<int>, done: int, withheld: int}
	 */
	private static function page(ClusterCrypto $rCrypto, array $rNode, array $rItems, array $rData, ?array $rHave): array {
		$rStreams = [];
		$rRemoved = [];
		$rBytes = 0;
		$rWithheld = 0;
		$rDone = 0;
		foreach ($rItems as [$rID, $rVer]) {
			if (!isset($rData[$rID])) {
				if ($rHave === null || isset($rHave[$rID])) {
					$rRemoved[] = $rID;
				}
				$rDone++;
				continue;
			}
			$rEtag = ReplicaBuilder::etag($rData[$rID]);
			if ($rHave !== null && ($rHave[$rID] ?? '') === $rEtag) {
				$rDone++;
				continue;
			}
			if (count($rStreams) >= self::MAX_RECORDS) {
				break;
			}
			$rDoc = [
				'v' => 1, 'section' => ReplicaSections::STREAM, 'node' => (string) $rNode['node_uuid'], 'gen' => (int) $rNode['gen'],
				'stream_id' => $rID, 'ver' => $rVer, 'etag' => $rEtag, 'iat' => ClusterClock::now(), 'data' => $rData[$rID],
			];
			try {
				$rSealed = base64_encode(ReplicaBuilder::record($rCrypto, $rNode, 'rep', ReplicaBuilder::json($rDoc)));
			} catch (ClusterRefusedException $rE) {
				if ($rE->reason() !== 'LICENCE') {
					throw $rE;
				}
				// A record grants: left out, and a delta's cursor stops before it.
				$rWithheld++;
				if ($rHave === null) {
					break;
				}
				$rDone++;
				continue;
			}
			if ($rStreams !== [] && $rBytes + strlen($rSealed) > self::MAX_BYTES) {
				break;
			}
			$rBytes += strlen($rSealed);
			$rStreams[] = ['id' => $rID, 'ver' => $rVer, 'etag' => $rEtag, 'sealed' => $rSealed];
			$rDone++;
		}
		return ['streams' => $rStreams, 'removed' => $rRemoved, 'done' => $rDone, 'withheld' => $rWithheld];
	}

	/**
	 * The streams a server holds: assigned to it, its TV archive or
	 * thumbnails recorded there, or a recording of it scheduled there. By
	 * id ($rIDs), or in from..to, the lowest $rLimit ids at most, ascending.
	 *
	 * @param list<int>|null $rIDs
	 * @return list<int>
	 */
	public static function held(int $rServerID, ?array $rIDs, int $rFrom = 0, int $rTo = self::MAX_ID, ?int $rLimit = null): array {
		if ($rIDs !== null) {
			$rIDs = array_values(array_unique(array_map('intval', $rIDs)));
			if ($rIDs === []) {
				return [];
			}
			$rWhere = ' IN (' . implode(',', $rIDs) . ')';
			$rArgs = [];
		} else {
			$rWhere = ' BETWEEN ? AND ?';
			$rArgs = [$rFrom, $rTo];
		}
		// Each way of holding one, cut at the limit: every id up to the
		// limit-th lowest of them all is then among what they return.
		$rLimited = static fn(string $rColumn): string => $rLimit === null ? ';' : ' ORDER BY `' . $rColumn . '` ASC LIMIT ' . max(1, $rLimit) . ';';
		$rOut = [];
		foreach ([
			['SELECT `stream_id` AS `id` FROM `streams_servers` WHERE `server_id` = ? AND `stream_id`' . $rWhere . $rLimited('stream_id'), [$rServerID]],
			['SELECT `id` FROM `streams` WHERE (`tv_archive_server_id` = ? OR `vframes_server_id` = ?) AND `id`' . $rWhere . $rLimited('id'), [$rServerID, $rServerID]],
			['SELECT `stream_id` AS `id` FROM `recordings` WHERE `source_id` = ? AND `stream_id`' . $rWhere . $rLimited('stream_id'), [$rServerID]],
		] as [$rSql, $rFirst]) {
			self::read($rSql, ...$rFirst, ...$rArgs);
			foreach (self::db()->get_rows() ?: [] as $rRow) {
				$rOut[(int) $rRow['id']] = true;
			}
		}
		$rOut = array_keys($rOut);
		sort($rOut);
		return $rLimit === null ? $rOut : array_slice($rOut, 0, max(1, $rLimit));
	}

	/**
	 * The records' data of these held streams, those whose `streams` row
	 * exists (ReplicaSections, `stream` records), in canonical form.
	 *
	 * @param list<int> $rIDs
	 * @return array<int, array<string, mixed>> stream id => data
	 */
	public static function data(int $rServerID, array $rIDs): array {
		if ($rIDs === []) {
			return [];
		}
		$rIn = implode(',', array_map('intval', $rIDs));
		self::read('SELECT `' . implode('`, `', array_keys(ReplicaSections::STREAM_FIELDS)) . '` FROM `streams` WHERE `id` IN (' . $rIn . ') ORDER BY `id`;');
		$rStreams = self::db()->get_rows() ?: [];
		if ($rStreams === []) {
			return [];
		}
		self::read('SELECT * FROM `streams_types`;');
		$rTypes = [];
		foreach (self::db()->get_rows() ?: [] as $rRow) {
			$rTypes[(int) $rRow['type_id']] = ReplicaSections::typed($rRow, ReplicaSections::STREAM_TYPE_FIELDS);
		}
		$rProfileIDs = array_values(array_unique(array_filter(array_map(static fn(array $rRow): int => (int) $rRow['transcode_profile_id'], $rStreams))));
		$rProfiles = [];
		if ($rProfileIDs !== []) {
			self::read('SELECT * FROM `profiles` WHERE `profile_id` IN (' . implode(',', $rProfileIDs) . ');');
			foreach (self::db()->get_rows() ?: [] as $rRow) {
				$rProfiles[(int) $rRow['profile_id']] = ReplicaSections::typed($rRow, ReplicaSections::PROFILE_FIELDS);
			}
		}
		$rOptionColumns = array_merge(array_map(static fn(string $rColumn): string => 't1.`' . $rColumn . '`', array_keys(ReplicaSections::OPTION_FIELDS)), array_map(static fn(string $rColumn): string => 't2.`' . $rColumn . '`', array_keys(ReplicaSections::ARGUMENT_FIELDS)));
		self::read('SELECT t1.`stream_id`, ' . implode(', ', $rOptionColumns) . ' FROM `streams_options` t1 INNER JOIN `streams_arguments` t2 ON t2.`id` = t1.`argument_id` WHERE t1.`stream_id` IN (' . $rIn . ') ORDER BY t1.`stream_id`, t1.`argument_id`, t1.`id`;');
		$rOptions = [];
		foreach (self::db()->get_rows() ?: [] as $rRow) {
			$rOptions[(int) $rRow['stream_id']][] = ReplicaSections::typed($rRow, ReplicaSections::OPTION_FIELDS + ReplicaSections::ARGUMENT_FIELDS);
		}
		self::read('SELECT `' . implode('`, `', array_keys(ReplicaSections::STREAM_SERVER_FIELDS)) . '` FROM `streams_servers` WHERE `server_id` = ? AND `stream_id` IN (' . $rIn . ') ORDER BY `server_stream_id`;', $rServerID);
		$rServers = [];
		foreach (self::db()->get_rows() ?: [] as $rRow) {
			$rServers[(int) $rRow['stream_id']] ??= ReplicaSections::typed($rRow, ReplicaSections::STREAM_SERVER_FIELDS);
		}
		self::read('SELECT `stream_id`, `server_id` FROM `streams_servers` WHERE `parent_id` = ? AND `stream_id` IN (' . $rIn . ') ORDER BY `stream_id`, `server_id`;', $rServerID);
		$rChildren = [];
		foreach (self::db()->get_rows() ?: [] as $rRow) {
			$rChildren[(int) $rRow['stream_id']][] = (int) $rRow['server_id'];
		}
		self::read('SELECT `' . implode('`, `', array_keys(ReplicaSections::RECORDING_FIELDS)) . '` FROM `recordings` WHERE `source_id` = ? AND `stream_id` IN (' . $rIn . ') ORDER BY `id`;', $rServerID);
		$rRecordings = [];
		foreach (self::db()->get_rows() ?: [] as $rRow) {
			$rRecordings[(int) $rRow['stream_id']][] = ReplicaSections::typed($rRow, ReplicaSections::RECORDING_FIELDS);
		}
		$rOut = [];
		foreach ($rStreams as $rRow) {
			$rID = (int) $rRow['id'];
			$rStream = ReplicaSections::typed($rRow, ReplicaSections::STREAM_FIELDS);
			$rOut[$rID] = ReplicaBuilder::canonical([
				'stream' => $rStream,
				'type' => $rTypes[(int) $rStream['type']] ?? null,
				'profile' => $rProfiles[(int) $rStream['transcode_profile_id']] ?? null,
				'options' => $rOptions[$rID] ?? [],
				'server' => $rServers[$rID] ?? null,
				'children' => array_values(array_unique($rChildren[$rID] ?? [])),
				'recordings' => $rRecordings[$rID] ?? [],
				// Phase 8: the relay and file tickets a node pulls this stream with.
				'tickets' => null,
			]);
		}
		return $rOut;
	}

	/**
	 * The versions of these streams for the server (0 for one never stamped).
	 *
	 * @param list<int> $rIDs
	 * @return array<int, int>
	 */
	private static function versions(int $rServerID, array $rIDs): array {
		if ($rIDs === []) {
			return [];
		}
		self::read('SELECT `stream_id`, `ver` FROM `cluster_stream_ver` WHERE `server_id` = ? AND `stream_id` IN (' . implode(',', array_map('intval', $rIDs)) . ');', $rServerID);
		$rOut = [];
		foreach (self::db()->get_rows() ?: [] as $rRow) {
			$rOut[(int) $rRow['stream_id']] = (int) $rRow['ver'];
		}
		return $rOut;
	}

	/**
	 * Drop the version rows of streams their server no longer holds, once
	 * they are KEEP_DAYS old (cron:cluster). Each server's floor is raised to
	 * the newest version it loses first, so a node whose cursor is below it
	 * (it may not have seen that removal) checks every stream again.
	 *
	 * @return int the rows dropped
	 */
	public static function prune(?int $rNow = null): int {
		$rBefore = ($rNow ?? time()) - self::KEEP_DAYS * 86400;
		self::read('SELECT `server_id`, `stream_id`, `ver` FROM `cluster_stream_ver` WHERE `updated_at` < ? ORDER BY `updated_at` ASC LIMIT ' . self::PRUNE_ROWS . ';', $rBefore);
		$rByServer = [];
		foreach (self::db()->get_rows() ?: [] as $rRow) {
			$rByServer[(int) $rRow['server_id']][(int) $rRow['stream_id']] = (int) $rRow['ver'];
		}
		$rDropped = 0;
		foreach ($rByServer as $rServerID => $rVers) {
			$rGone = array_diff_key($rVers, array_flip(self::held($rServerID, array_keys($rVers))));
			if ($rGone === []) {
				continue;
			}
			StreamVersions::raiseFloor(max($rGone), $rServerID, self::db());
			// Only rows not stamped again since they were read.
			self::read('DELETE FROM `cluster_stream_ver` WHERE `server_id` = ? AND `updated_at` < ? AND `stream_id` IN (' . implode(',', array_keys($rGone)) . ');', $rServerID, $rBefore);
			$rDropped += count($rGone);
		}
		return $rDropped;
	}

	/** Run one of the section's statements: a failed one throws, never an empty result. */
	private static function read(string $rQuery, mixed ...$rArgs): void {
		if (self::db()->query($rQuery, ...$rArgs) === false) {
			throw new \RuntimeException('streams: a read failed');
		}
	}
}
