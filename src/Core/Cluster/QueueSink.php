<?php

namespace XcVm\Core\Cluster;

use XcVm\Domain\Cluster\StreamPush;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * The encoding queue (`queue`), which is MAIN's table: movies to encode and
 * created channels to build, one row per stream and server.
 *
 * A legacy node reads and writes the table over its own database connection,
 * as before. A node whose CONTENT flow is on asks MAIN through its agent
 * instead (plan, Phase 5; the ops ClusterPool::INGEST_OPS names):
 *
 * ```text
 * queue_enqueue  {type, stream_ids}          add work for this node
 * queue_claim    {type, limit}               what is running, and what is waiting
 * queue_update   {pids: {id: pid}, delete}   after starting or losing a job
 * ```
 *
 * MAIN keys every one of them to the calling node, so a node can neither read
 * nor touch another's work. Without the flow a node in mode 2 does nothing:
 * it has no database, and false tells the caller the work was not queued.
 *
 * The SQL itself is here, and only here (the *Rows() methods): the node's
 * database path runs it keyed to its own server, and MAIN's half
 * (Domain\Cluster\NodeQueue, which serves the three ops) keyed to the
 * authenticated node.
 *
 * In Core: `queue`, `cron:vod` and the created-channel builder ship to LBs.
 */
final class QueueSink {
	/** Queue kinds, as the table stores them. */
	public const TYPES = ['movie', 'channel'];

	/** Most rows MAIN returns for one claim, whatever the caller asks. */
	public const MAX_CLAIM = 200;

	/** Under SIGNALS_TMP_PATH: MAIN queued work for this node (`queue.poke`), so the daemon's pass comes now. */
	public const POKE = 'queue_poke';

	/**
	 * Add work for a node: movies replace what is queued for the same stream,
	 * channels are left alone when already queued (as the SQL did).
	 *
	 * @param list<int> $rStreamIDs
	 * @return bool False when it was not queued (mode 2 without the flow).
	 */
	public static function enqueue(string $rType, array $rStreamIDs, int $rServerID, ?object $rDb = null): bool {
		$rStreamIDs = self::ids($rStreamIDs);
		if ($rStreamIDs === [] || !in_array($rType, self::TYPES, true)) {
			return false;
		}

		// Only this node's own work goes through the agent: MAIN keys the op to
		// the caller, so queueing onto another server stays a database write.
		if ($rServerID === (int) SERVER_ID && NodeFlows::on(NodeFlows::CONTENT)) {
			// One try: the callers are crons and admin actions that come round
			// again (a movie re-queued replaces its row, a channel already queued
			// is left alone), and a cron must not sit out an agent's retry waits.
			return AgentClient::main('queue_enqueue', ['type' => $rType, 'stream_ids' => $rStreamIDs]) !== null;
		}
		if (NodeRole::refusesConnects()) {
			return false;
		}

		$rDb ??= DatabaseFactory::get();
		$rQueued = self::insertRows($rDb, $rServerID, $rType, $rStreamIDs)[1];
		// Work MAIN queued onto another server: that node's daemon is poked when
		// the request ends (StreamPush), rather than finding it at its next pass.
		if ($rQueued && $rServerID !== (int) SERVER_ID && class_exists(StreamPush::class)) {
			StreamPush::queued([$rServerID]);
		}
		return $rQueued;
	}

	/**
	 * The queue daemon's wait between passes: $rSeconds, or less once MAIN
	 * pokes this node (`queue.poke`, which drops POKE).
	 */
	public static function waitPoke(int $rSeconds, string $rDir = SIGNALS_TMP_PATH): void {
		$rFile = $rDir . self::POKE;
		$rUntil = microtime(true) + $rSeconds;
		while (microtime(true) < $rUntil) {
			if (@unlink($rFile)) {
				return;
			}
			usleep(250000);
		}
	}

	/**
	 * This node's queue for one kind: the rows already started (so the daemon
	 * can check their processes) and the rows waiting, newest last.
	 *
	 * @return array{running: list<array{id: int, pid: int}>, pending: list<array{id: int, stream_id: int}>}|null
	 *         Null when the queue could not be read (MAIN unreachable, or mode 2 without the flow).
	 */
	public static function claim(string $rType, int $rLimit, ?object $rDb = null): ?array {
		if (!in_array($rType, self::TYPES, true)) {
			return null;
		}
		$rLimit = max(0, min(self::MAX_CLAIM, $rLimit));

		if (NodeFlows::on(NodeFlows::CONTENT)) {
			$rOut = AgentClient::main('queue_claim', ['type' => $rType, 'limit' => $rLimit]);
			return $rOut === null ? null : self::shape($rOut);
		}
		if (NodeRole::refusesConnects()) {
			return null;
		}

		$rDb ??= DatabaseFactory::get();
		return self::claimRows($rDb, (int) SERVER_ID, $rType, $rLimit);
	}

	/**
	 * Record what started (`id => pid`) and drop what finished or failed.
	 *
	 * @param array<int, int> $rPids
	 * @param list<int>       $rDelete
	 */
	public static function update(array $rPids, array $rDelete, ?object $rDb = null): bool {
		$rPids = array_filter($rPids, static fn($rPid, $rID): bool => (int) $rID > 0 && (int) $rPid > 0, ARRAY_FILTER_USE_BOTH);
		$rDelete = self::ids($rDelete);
		if ($rPids === [] && $rDelete === []) {
			return true;
		}

		if (NodeFlows::on(NodeFlows::CONTENT)) {
			// Retried, unlike enqueue: a pid MAIN never records is a row the
			// daemon claims again next pass, which encodes the same stream twice.
			$rBody = ['pids' => (object) array_map('intval', $rPids), 'delete' => $rDelete];
			return AgentClient::mainRetrying('queue_update', $rBody) !== null;
		}
		if (NodeRole::refusesConnects()) {
			return false;
		}

		$rDb ??= DatabaseFactory::get();
		self::updateRows($rDb, (int) SERVER_ID, $rPids, $rDelete);
		return true;
	}

	/**
	 * Stream or row ids as the queue takes them: ints above 0, each once.
	 *
	 * @param array<mixed> $rIDs
	 * @return list<int>
	 */
	public static function ids(array $rIDs): array {
		return array_values(array_unique(array_filter(array_map('intval', $rIDs), static fn(int $rID): bool => $rID > 0)));
	}

	/**
	 * enqueue()'s SQL, for one server's rows: a movie replaces what is queued
	 * for the same stream, a channel already queued is left alone.
	 *
	 * @param list<int> $rStreamIDs Through ids(), not empty, of a type in TYPES.
	 * @return array{int, bool} How many rows were new (and so inserted), and
	 *                          whether the INSERT went through (true when none was new).
	 */
	public static function insertRows(object $rDb, int $rServerID, string $rType, array $rStreamIDs): array {
		$rPlaceholders = implode(',', array_fill(0, count($rStreamIDs), '?'));
		if ($rType === 'movie') {
			$rDb->query('DELETE FROM `queue` WHERE `stream_id` IN (' . $rPlaceholders . ') AND `server_id` = ?;', ...[...$rStreamIDs, $rServerID]);
		} else {
			$rDb->query('SELECT `stream_id` FROM `queue` WHERE `stream_id` IN (' . $rPlaceholders . ') AND `server_id` = ?;', ...[...$rStreamIDs, $rServerID]);
			$rHave = array_map(static fn(array $rRow): int => (int) $rRow['stream_id'], $rDb->get_rows() ?: []);
			$rStreamIDs = array_values(array_diff($rStreamIDs, $rHave));
			if ($rStreamIDs === []) {
				return [0, true];
			}
		}

		$rNow = time();
		$rValues = implode(',', array_fill(0, count($rStreamIDs), '(?, ?, ?, ?)'));
		$rArgs = [];
		foreach ($rStreamIDs as $rStreamID) {
			array_push($rArgs, $rType, $rStreamID, $rServerID, $rNow);
		}
		$rOk = (bool) $rDb->query('INSERT INTO `queue`(`type`, `stream_id`, `server_id`, `added`) VALUES ' . $rValues . ';', ...$rArgs);
		return [count($rStreamIDs), $rOk];
	}

	/**
	 * claim()'s SQL, for one server's rows: what runs, and at most $rLimit
	 * (capped at MAX_CLAIM) waiting, oldest first. A read that fails counts
	 * as no rows.
	 *
	 * @return array{running: list<array{id: int, pid: int}>, pending: list<array{id: int, stream_id: int}>}
	 */
	public static function claimRows(object $rDb, int $rServerID, string $rType, int $rLimit): array {
		$rLimit = max(0, min(self::MAX_CLAIM, $rLimit));
		$rRunning = $rPending = [];
		if ($rDb->query('SELECT `id`, `pid` FROM `queue` WHERE `server_id` = ? AND `pid` IS NOT NULL AND `type` = ? ORDER BY `added` ASC;', $rServerID, $rType)) {
			foreach ($rDb->get_rows() ?: [] as $rRow) {
				$rRunning[] = ['id' => (int) $rRow['id'], 'pid' => (int) $rRow['pid']];
			}
		}
		if ($rLimit > 0 && $rDb->query('SELECT `id`, `stream_id` FROM `queue` WHERE `server_id` = ? AND `pid` IS NULL AND `type` = ? ORDER BY `added` ASC LIMIT ' . $rLimit . ';', $rServerID, $rType)) {
			foreach ($rDb->get_rows() ?: [] as $rRow) {
				$rPending[] = ['id' => (int) $rRow['id'], 'stream_id' => (int) $rRow['stream_id']];
			}
		}
		return ['running' => $rRunning, 'pending' => $rPending];
	}

	/**
	 * update()'s SQL, for one server's rows: record each `id => pid` (both
	 * above 0), then drop the $rDelete rows. Another server's rows are not
	 * touched.
	 *
	 * @param array<mixed, mixed> $rPids
	 * @param array<mixed>        $rDelete
	 * @return int How many pids were recorded, plus how many rows were named to drop.
	 */
	public static function updateRows(object $rDb, int $rServerID, array $rPids, array $rDelete): int {
		$rDone = 0;
		foreach ($rPids as $rID => $rPid) {
			$rID = (int) $rID;
			$rPid = (int) $rPid;
			if ($rID > 0 && $rPid > 0 && $rDb->query('UPDATE `queue` SET `pid` = ? WHERE `id` = ? AND `server_id` = ?;', $rPid, $rID, $rServerID)) {
				$rDone++;
			}
		}

		$rIDs = self::ids($rDelete);
		if ($rIDs !== []) {
			$rDb->query('DELETE FROM `queue` WHERE `id` IN (' . implode(',', $rIDs) . ') AND `server_id` = ?;', $rServerID);
			$rDone += count($rIDs);
		}
		return $rDone;
	}

	/**
	 * MAIN's answer, in the shape claim() promises: anything else is dropped
	 * rather than handed to the daemon as a stream id.
	 *
	 * @param array<string, mixed> $rOut
	 * @return array{running: list<array{id: int, pid: int}>, pending: list<array{id: int, stream_id: int}>}
	 */
	private static function shape(array $rOut): array {
		$rRunning = $rPending = [];
		foreach ((array) ($rOut['running'] ?? []) as $rRow) {
			if (is_array($rRow) && (int) ($rRow['id'] ?? 0) > 0 && (int) ($rRow['pid'] ?? 0) > 0) {
				$rRunning[] = ['id' => (int) $rRow['id'], 'pid' => (int) $rRow['pid']];
			}
		}
		foreach ((array) ($rOut['pending'] ?? []) as $rRow) {
			if (is_array($rRow) && (int) ($rRow['id'] ?? 0) > 0 && (int) ($rRow['stream_id'] ?? 0) > 0) {
				$rPending[] = ['id' => (int) $rRow['id'], 'stream_id' => (int) $rRow['stream_id']];
			}
		}
		return ['running' => $rRunning, 'pending' => $rPending];
	}
}
