<?php

namespace XcVm\Core\Cluster;

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
 * In Core: `queue`, `cron:vod` and the created-channel builder ship to LBs.
 */
final class QueueSink {
	/** Queue kinds, as the table stores them. */
	public const TYPES = ['movie', 'channel'];

	/** Most rows MAIN returns for one claim, whatever the caller asks. */
	public const MAX_CLAIM = 200;

	/**
	 * Add work for a node: movies replace what is queued for the same stream,
	 * channels are left alone when already queued (as the SQL did).
	 *
	 * @param list<int> $rStreamIDs
	 * @return bool False when it was not queued (mode 2 without the flow).
	 */
	public static function enqueue(string $rType, array $rStreamIDs, int $rServerID, ?object $rDb = null): bool {
		$rStreamIDs = array_values(array_unique(array_filter(array_map('intval', $rStreamIDs), static fn(int $rID): bool => $rID > 0)));
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
		$rPlaceholders = implode(',', array_fill(0, count($rStreamIDs), '?'));
		if ($rType === 'movie') {
			$rDb->query('DELETE FROM `queue` WHERE `stream_id` IN (' . $rPlaceholders . ') AND `server_id` = ?;', ...[...$rStreamIDs, $rServerID]);
		} else {
			$rDb->query('SELECT `stream_id` FROM `queue` WHERE `stream_id` IN (' . $rPlaceholders . ') AND `server_id` = ?;', ...[...$rStreamIDs, $rServerID]);
			$rHave = array_map(static fn(array $rRow): int => (int) $rRow['stream_id'], $rDb->get_rows() ?: []);
			$rStreamIDs = array_values(array_diff($rStreamIDs, $rHave));
			if ($rStreamIDs === []) {
				return true;
			}
		}

		$rNow = time();
		$rValues = implode(',', array_fill(0, count($rStreamIDs), '(?, ?, ?, ?)'));
		$rArgs = [];
		foreach ($rStreamIDs as $rStreamID) {
			array_push($rArgs, $rType, $rStreamID, $rServerID, $rNow);
		}
		return (bool) $rDb->query('INSERT INTO `queue`(`type`, `stream_id`, `server_id`, `added`) VALUES ' . $rValues . ';', ...$rArgs);
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
		$rRunning = $rPending = [];
		if ($rDb->query('SELECT `id`, `pid` FROM `queue` WHERE `server_id` = ? AND `pid` IS NOT NULL AND `type` = ? ORDER BY `added` ASC;', SERVER_ID, $rType)) {
			foreach ($rDb->get_rows() ?: [] as $rRow) {
				$rRunning[] = ['id' => (int) $rRow['id'], 'pid' => (int) $rRow['pid']];
			}
		}
		if ($rLimit > 0 && $rDb->query('SELECT `id`, `stream_id` FROM `queue` WHERE `server_id` = ? AND `pid` IS NULL AND `type` = ? ORDER BY `added` ASC LIMIT ' . $rLimit . ';', SERVER_ID, $rType)) {
			foreach ($rDb->get_rows() ?: [] as $rRow) {
				$rPending[] = ['id' => (int) $rRow['id'], 'stream_id' => (int) $rRow['stream_id']];
			}
		}
		return ['running' => $rRunning, 'pending' => $rPending];
	}

	/**
	 * Record what started (`id => pid`) and drop what finished or failed.
	 *
	 * @param array<int, int> $rPids
	 * @param list<int>       $rDelete
	 */
	public static function update(array $rPids, array $rDelete, ?object $rDb = null): bool {
		$rPids = array_filter($rPids, static fn($rPid, $rID): bool => (int) $rID > 0 && (int) $rPid > 0, ARRAY_FILTER_USE_BOTH);
		$rDelete = array_values(array_unique(array_filter(array_map('intval', $rDelete), static fn(int $rID): bool => $rID > 0)));
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
		foreach ($rPids as $rID => $rPid) {
			$rDb->query('UPDATE `queue` SET `pid` = ? WHERE `id` = ? AND `server_id` = ?;', (int) $rPid, (int) $rID, SERVER_ID);
		}
		if ($rDelete !== []) {
			$rDb->query('DELETE FROM `queue` WHERE `id` IN (' . implode(',', $rDelete) . ') AND `server_id` = ?;', SERVER_ID);
		}
		return true;
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
