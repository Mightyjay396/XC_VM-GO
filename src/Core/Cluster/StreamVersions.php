<?php

namespace XcVm\Core\Cluster;

use XcVm\Core\Events\ListensTo;
use XcVm\Core\Events\Stream\StreamArgumentsChangedEvent;
use XcVm\Core\Events\Stream\StreamsChangedEvent;
use XcVm\Core\Events\Stream\StreamsDeletedEvent;
use XcVm\Core\Events\Stream\TranscodeProfileSavedEvent;
use XcVm\Domain\Cluster\StreamPush;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * The versions of the node replica's R2 `streams` section (plan, section 9,
 * "Change detection"): `cluster_stream_ver` holds a row per server and stream
 * the server holds or held, with the version at which MAIN last changed what
 * that server needs to run or serve the stream. No triggers: MAIN's writers
 * of a stream's desired configuration dispatch an event (StreamsChangedEvent,
 * StreamsDeletedEvent, TranscodeProfileSavedEvent,
 * StreamArgumentsChangedEvent), and bump() stamps the stream anew for every
 * server that holds it now or held it before. What nodes write back (pids,
 * status, codecs, progress, `streams_servers.updated`) never bumps anything.
 *
 * A server holds a stream (ReplicaSections, `stream` records) when it is
 * assigned it (`streams_servers`), records its TV archive or thumbnails
 * (`tv_archive_server_id`, `vframes_server_id`), or has a recording of it
 * scheduled (`recordings.source_id`). A row stays after the server stops
 * holding the stream, so the change reaches the node as a removal.
 *
 * ```text
 * cluster_meta stream_ver              the newest version handed out (1 before any change)
 * cluster_meta stream_ver_floor        a node whose cursor is below it checks every stream again
 * cluster_meta stream_ver_floor.<sid>  the same for one node: its versions pruned below it
 * ```
 *
 * One bump takes as many versions as it has streams, in one transaction that
 * holds the counter's row, so every stream of a bump has its own version and
 * bumps commit in the order of their versions. A node that has read a
 * version has thus read every version below it, and its cursor is one
 * number. A change to every stream at once (reset()) raises the floor
 * instead of stamping each one.
 *
 * In Core, not Domain\Cluster: the listener is registered at boot on every
 * node (ContainerPopulateStage, and MAIN's cluster API), and a legacy load
 * balancer still writes MAIN's database for its recordings. Recording never
 * fails the change itself: a version that could not be written leaves the
 * change to the section hashes the agent sends every 5 minutes. The one
 * exception is a change dispatched inside the writer's open transaction:
 * there a failure throws to the writer, for a deadlock may have rolled back
 * its whole transaction (transaction()). A writer that must not fail for it
 * dispatches after its commit, as EventIngest does.
 */
final class StreamVersions {
	/** cluster_meta: the newest version handed out. */
	public const META_HEAD = 'stream_ver';

	/** cluster_meta: nodes whose cursor is below it check every stream again. */
	public const META_FLOOR = 'stream_ver_floor';

	/** The head before any change: a node that synced then holds 1, never 0 (nothing held). */
	public const START = 1;

	/** Streams per statement. */
	public const CHUNK = 500;

	/**
	 * Stamp these streams anew for every server that holds them or held them.
	 *
	 * @param list<int|string> $rStreamIDs
	 * @return int the newest version handed out; 0 when nothing was recorded
	 */
	public static function bump(array $rStreamIDs, ?object $rDb = null): int {
		$rIDs = array_values(array_unique(array_filter(array_map('intval', $rStreamIDs), static fn(int $rID): bool => $rID > 0)));
		if ($rIDs === []) {
			return 0;
		}
		sort($rIDs);
		$rServers = [];
		$rHi = self::transaction($rDb, static function (object $rDb, bool $rOwn) use ($rIDs, &$rServers): int {
			$rHi = self::advance($rDb, count($rIDs), $rOwn);
			$rVer = array_combine($rIDs, range($rHi - count($rIDs) + 1, $rHi));
			$rNow = time();
			foreach (array_chunk($rIDs, self::CHUNK) as $rChunk) {
				foreach (array_chunk(self::holders($rDb, $rChunk), self::CHUNK) as $rPairs) {
					$rArgs = [];
					foreach ($rPairs as [$rServerID, $rStreamID]) {
						array_push($rArgs, $rServerID, $rStreamID, $rVer[$rStreamID], $rNow);
						$rServers[$rServerID] = $rServerID;
					}
					self::run($rDb, 'REPLACE INTO `cluster_stream_ver` (`server_id`, `stream_id`, `ver`, `updated_at`) VALUES ' . implode(', ', array_fill(0, count($rPairs), '(?, ?, ?, ?)')) . ';', ...$rArgs);
				}
			}
			return $rHi;
		});
		// MAIN wakes those nodes when the request ends (StreamPush); a node's
		// build has no StreamPush and leaves it to their next delta.
		if ($rHi > 0 && class_exists(StreamPush::class)) {
			StreamPush::changed(array_values($rServers));
		}
		return $rHi;
	}

	/**
	 * Every stream changed at once (the writer does not know which): the floor
	 * rises to a new version, and every node checks all its streams again.
	 *
	 * @return int the new floor; 0 when it could not be recorded
	 */
	public static function reset(?object $rDb = null): int {
		$rHi = self::transaction($rDb, static function (object $rDb, bool $rOwn): int {
			$rHi = self::advance($rDb, 1, $rOwn);
			self::put($rDb, self::META_FLOOR, $rHi);
			return $rHi;
		});
		if ($rHi > 0 && class_exists(StreamPush::class)) {
			StreamPush::changedAll();
		}
		return $rHi;
	}

	/**
	 * Give every server that holds a stream now, and has no row for it, its
	 * row at version 0, as migration 047 seeds an install's. For a writer
	 * that adds holders without naming them (a panel migration's import):
	 * taking the stream off one later then reaches its node as a removal.
	 *
	 * @return bool false when it could not all be recorded
	 */
	public static function seedHolders(?object $rDb = null): bool {
		try {
			$rDb ??= DatabaseFactory::get();
			if (!is_object($rDb)) {
				return false;
			}
			foreach ([
				['streams_servers', '`server_id`', '`stream_id`'],
				['streams', '`tv_archive_server_id`', '`id`'],
				['streams', '`vframes_server_id`', '`id`'],
				['recordings', '`source_id`', '`stream_id`'],
			] as [$rTable, $rServer, $rStream]) {
				self::run($rDb, 'INSERT INTO `cluster_stream_ver` (`server_id`, `stream_id`, `ver`, `updated_at`) SELECT DISTINCT h.' . $rServer . ', h.' . $rStream . ', 0, ? FROM `' . $rTable . '` h WHERE h.' . $rServer . ' > 0 AND h.' . $rStream . ' > 0 AND NOT EXISTS (SELECT 1 FROM `cluster_stream_ver` v WHERE v.`server_id` = h.' . $rServer . ' AND v.`stream_id` = h.' . $rStream . ');', time());
			}
			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	/** The newest version handed out; a failed read throws. */
	public static function head(?object $rDb = null): int {
		return max(self::START, self::meta($rDb ?? DatabaseFactory::get(), self::META_HEAD) ?? self::START);
	}

	/**
	 * A node's floor: a node whose cursor is below it checks every stream
	 * again. The higher of every node's (reset()) and this node's own (its
	 * versions pruned); server 0 reads the first alone. A failed read throws.
	 */
	public static function floor(int $rServerID = 0, ?object $rDb = null): int {
		$rDb ??= DatabaseFactory::get();
		$rFloor = self::meta($rDb, self::META_FLOOR) ?? 0;
		return $rServerID > 0 ? max($rFloor, self::meta($rDb, self::META_FLOOR . '.' . $rServerID) ?? 0) : $rFloor;
	}

	/**
	 * Raise a node's floor (server 0: every node's), never lower it: before
	 * rows below it are pruned. A failed write throws.
	 */
	public static function raiseFloor(int $rVer, int $rServerID = 0, ?object $rDb = null): void {
		$rDb ??= DatabaseFactory::get();
		$rName = $rServerID > 0 ? self::META_FLOOR . '.' . $rServerID : self::META_FLOOR;
		if ($rVer > (self::meta($rDb, $rName) ?? 0)) {
			self::put($rDb, $rName, $rVer);
		}
	}

	#[ListensTo(StreamsChangedEvent::class)]
	public static function onStreamsChanged(StreamsChangedEvent $rEvent): void {
		$rEvent->all ? self::reset() : self::bump($rEvent->streamIds);
	}

	#[ListensTo(StreamsDeletedEvent::class)]
	public static function onStreamsDeleted(StreamsDeletedEvent $rEvent): void {
		self::bump($rEvent->streamIds);
	}

	#[ListensTo(TranscodeProfileSavedEvent::class)]
	public static function onProfileSaved(TranscodeProfileSavedEvent $rEvent): void {
		self::bump(self::ids('SELECT `id` FROM `streams` WHERE `transcode_profile_id` = ?;', $rEvent->profileId));
	}

	#[ListensTo(StreamArgumentsChangedEvent::class)]
	public static function onArgumentsChanged(StreamArgumentsChangedEvent $rEvent): void {
		$rKeys = array_values(array_filter($rEvent->argumentKeys, 'is_string'));
		if ($rKeys === []) {
			return;
		}
		self::bump(self::ids('SELECT DISTINCT `t1`.`stream_id` AS `id` FROM `streams_options` t1 INNER JOIN `streams_arguments` t2 ON t2.`id` = t1.`argument_id` WHERE t2.`argument_key` IN (' . implode(', ', array_fill(0, count($rKeys), '?')) . ');', ...$rKeys));
	}

	/**
	 * Every server that holds one of these streams now, or held it at its last
	 * bump (its row): [server_id, stream_id] pairs.
	 *
	 * @param list<int> $rIDs
	 * @return list<array{0: int, 1: int}>
	 */
	private static function holders(object $rDb, array $rIDs): array {
		$rIn = implode(',', $rIDs);
		$rPairs = [];
		foreach ([
			'SELECT `server_id` AS `s`, `stream_id` AS `i` FROM `streams_servers` WHERE `stream_id` IN (' . $rIn . ');',
			'SELECT `tv_archive_server_id` AS `s`, `id` AS `i` FROM `streams` WHERE `id` IN (' . $rIn . ') AND `tv_archive_server_id` > 0;',
			'SELECT `vframes_server_id` AS `s`, `id` AS `i` FROM `streams` WHERE `id` IN (' . $rIn . ') AND `vframes_server_id` > 0;',
			'SELECT `source_id` AS `s`, `stream_id` AS `i` FROM `recordings` WHERE `stream_id` IN (' . $rIn . ') AND `source_id` > 0;',
			'SELECT `server_id` AS `s`, `stream_id` AS `i` FROM `cluster_stream_ver` WHERE `stream_id` IN (' . $rIn . ');',
		] as $rSql) {
			self::run($rDb, $rSql);
			foreach ($rDb->get_rows() ?: [] as $rRow) {
				$rServerID = (int) ($rRow['s'] ?? 0);
				$rStreamID = (int) ($rRow['i'] ?? 0);
				if ($rServerID > 0 && $rStreamID > 0) {
					$rPairs[$rServerID . ':' . $rStreamID] = [$rServerID, $rStreamID];
				}
			}
		}
		return array_values($rPairs);
	}

	/**
	 * Take $rCount versions: the counter's row is held until the transaction
	 * ends, so bumps commit in the order of their versions. A missing counter
	 * starts past every version a row holds, as migration 047 starts it: a
	 * version below a node's cursor would never reach it by a delta.
	 *
	 * Two bumps can both find it missing, and the second's INSERT fails.
	 * Inside the caller's transaction ($rOwn false) that failure throws
	 * instead of being taken for the other bump's: it may be a deadlock
	 * that rolled the caller's transaction back (transaction()).
	 *
	 * @return int the highest of them
	 */
	private static function advance(object $rDb, int $rCount, bool $rOwn): int {
		self::run($rDb, 'UPDATE `cluster_meta` SET `value` = CAST(`value` AS UNSIGNED) + ?, `updated_at` = ? WHERE `name` = ?;', $rCount, time(), self::META_HEAD);
		if ($rDb->num_rows() < 1) {
			self::run($rDb, 'SELECT MAX(`ver`) AS `ver` FROM `cluster_stream_ver`;');
			$rStart = max(self::START, (int) ($rDb->get_row()['ver'] ?? 0));
			if (!$rDb->query('INSERT INTO `cluster_meta` (`name`, `value`, `updated_at`) VALUES (?, ?, ?);', self::META_HEAD, (string) ($rStart + $rCount), time())) {
				if (!$rOwn) {
					throw new \RuntimeException('stream versions: the counter could not be created');
				}
				// Another bump created the row first.
				self::run($rDb, 'UPDATE `cluster_meta` SET `value` = CAST(`value` AS UNSIGNED) + ?, `updated_at` = ? WHERE `name` = ?;', $rCount, time(), self::META_HEAD);
			}
		}
		$rHi = self::meta($rDb, self::META_HEAD);
		if ($rHi === null || $rHi <= self::START) {
			throw new \RuntimeException('stream versions: the counter did not advance');
		}
		return $rHi;
	}

	/** A cluster_meta integer; null when the row is missing, a failed read throws. */
	private static function meta(object $rDb, string $rName): ?int {
		self::run($rDb, 'SELECT `value` FROM `cluster_meta` WHERE `name` = ?;', $rName);
		$rRow = $rDb->get_row();
		return is_array($rRow) && isset($rRow['value']) && is_numeric($rRow['value']) ? (int) $rRow['value'] : null;
	}

	private static function put(object $rDb, string $rName, int $rValue): void {
		self::run($rDb, 'DELETE FROM `cluster_meta` WHERE `name` = ?;', $rName);
		self::run($rDb, 'INSERT INTO `cluster_meta` (`name`, `value`, `updated_at`) VALUES (?, ?, ?);', $rName, (string) $rValue, time());
	}

	/**
	 * The stream ids a query returns (column `id`); none when it fails.
	 *
	 * @return list<int>
	 */
	private static function ids(string $rSql, mixed ...$rArgs): array {
		try {
			$rDb = DatabaseFactory::get();
			if (!is_object($rDb) || !$rDb->query($rSql, ...$rArgs)) {
				return [];
			}
			return array_map(static fn(array $rRow): int => (int) $rRow['id'], $rDb->get_rows() ?: []);
		} catch (\Throwable) {
			return [];
		}
	}

	/**
	 * Run $rWork in a transaction of its own; anything that fails rolls it
	 * back and records nothing (0).
	 *
	 * Inside the caller's open transaction it runs in that one, and a failure
	 * throws to the caller instead: a statement that failed there may be a
	 * deadlock, which rolled back the caller's whole transaction. Swallowed,
	 * the caller's next statements would autocommit as if its earlier ones
	 * had been kept (an event batch's cursor, ADR 0004).
	 *
	 * @param callable(object, bool): int $rWork given the connection, and false inside the caller's transaction
	 */
	private static function transaction(?object $rDb, callable $rWork): int {
		try {
			$rDb ??= DatabaseFactory::get();
		} catch (\Throwable) {
			return 0;
		}
		if (!is_object($rDb)) {
			return 0;
		}
		if (method_exists($rDb, 'isInTransaction') && $rDb->isInTransaction()) {
			return $rWork($rDb, false); // the caller's transaction: its failure is the caller's
		}
		try {
			$rOwn = method_exists($rDb, 'beginTransaction') && $rDb->beginTransaction();
			try {
				$rOut = $rWork($rDb, true);
				if ($rOwn && !$rDb->commit()) {
					throw new \RuntimeException('stream versions: the commit failed');
				}
				return $rOut;
			} catch (\Throwable $rE) {
				if ($rOwn) {
					$rDb->rollback();
				}
				throw $rE;
			}
		} catch (\Throwable) {
			return 0;
		}
	}

	/** Run a statement; a failed one throws, never a silent partial record. */
	private static function run(object $rDb, string $rSql, mixed ...$rArgs): void {
		if (!$rDb->query($rSql, ...$rArgs)) {
			throw new \RuntimeException('stream versions: a statement failed');
		}
	}
}
