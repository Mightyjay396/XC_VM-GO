<?php

namespace XcVm\Core\Cluster;

use XcVm\Core\Process\ProcessRunner;
use XcVm\Streaming\Fanout\FanoutClient;

/**
 * Cache jobs: the work MAIN, or a node's own code, leaves to a node's PHP
 * besides kills and root actions: remove a closed viewer's connection file,
 * drop a viewer the fanout serves, delete a movie's files, rebuild stream
 * or line caches (MAIN's cron:cache_engine). SignalDispatcher::cache queues
 * them. Where a node's signals daemon reads MAIN's `signals` table they are
 * rows (`cache` = 1, the job as `custom_data`) it runs. A node in mode 2
 * reads none (its connects are refused): MAIN sends its jobs as signed
 * `node.cache {jobs}` commands, which cluster:exec runs, and the node runs
 * its own jobs where they are queued (SignalDispatcher).
 *
 * In Core: MAIN builds the commands it sends with commands(), and nodes run
 * them.
 */
final class CacheJobs {
	/**
	 * The cache jobs a node runs, each by what names its target: `id` as
	 * one id or a list of them, or `uuid`, a connection's.
	 */
	public const TYPES = [
		'update_stream' => 'id', 'update_line' => 'id', 'delete_vod' => 'id',
		'update_streams' => 'ids', 'update_lines' => 'ids', 'delete_vods' => 'ids',
		'delete_con' => 'uuid', 'drop_con' => 'uuid',
	];

	/** MAIN's cache rebuilds: cron:cache_engine, which only MAIN's build has. */
	public const REBUILDS = ['update_stream', 'update_line', 'update_streams', 'update_lines'];

	/**
	 * Most targets one `node.cache` command names (targets()): its jobs run
	 * in one cluster:exec, which the agent gives a minute, and a delete
	 * removes each id's files.
	 */
	public const MAX = 500;

	/**
	 * A job in the one form a `node.cache` command carries it: `type` from
	 * TYPES, then `id` (an integer ≥ 1, or a non-empty list of them) or
	 * `uuid` (`[A-Za-z0-9_-]{1,64}`), and nothing else. Ids given as digits
	 * become integers, and a list keeps those of its ids that are; null for
	 * anything else. MAIN sends only this form, and a node refuses a command
	 * with a job not already in it (ClusterExecCommand).
	 *
	 * @return array{type: string, id?: int|list<int>, uuid?: string}|null
	 */
	public static function job(mixed $rJob): ?array {
		$rType = is_array($rJob) ? ($rJob['type'] ?? null) : null;
		if (!is_string($rType) || !isset(self::TYPES[$rType])) {
			return null;
		}
		$rID = self::id(...);
		switch (self::TYPES[$rType]) {
			case 'id':
				$rOne = $rID($rJob['id'] ?? null);
				return $rOne === null ? null : ['type' => $rType, 'id' => $rOne];
			case 'ids':
				$rIDs = is_array($rJob['id'] ?? null) ? array_values(array_filter(array_map($rID, $rJob['id']), static fn(?int $rOne): bool => $rOne !== null)) : [];
				return $rIDs === [] ? null : ['type' => $rType, 'id' => $rIDs];
		}
		$rUUID = $rJob['uuid'] ?? null;
		return is_string($rUUID) && preg_match('/^[A-Za-z0-9_-]{1,64}\z/', $rUUID) ? ['type' => $rType, 'uuid' => $rUUID] : null;
	}

	/** An id in job()'s form (an integer ≥ 1, or its digits) as an integer; null for anything else. */
	private static function id(mixed $rValue): ?int {
		return (is_int($rValue) || (is_string($rValue) && preg_match('/^[0-9]{1,18}\z/', $rValue))) && (int) $rValue > 0 ? (int) $rValue : null;
	}

	/**
	 * The jobs in job()'s form, in order; the others left out.
	 *
	 * @param list<mixed> $rJobs
	 * @return list<array{type: string, id?: int|list<int>, uuid?: string}>
	 */
	public static function clean(array $rJobs): array {
		return array_values(array_filter(array_map([self::class, 'job'], $rJobs), static fn(?array $rJob): bool => $rJob !== null));
	}

	/**
	 * The jobs that act on the node itself, in job()'s form: what a node in
	 * mode 2 runs of its own jobs where they are queued. MAIN's cache
	 * rebuilds are left out: no node's build runs them.
	 *
	 * @param list<mixed> $rJobs
	 * @return list<array{type: string, id?: int|list<int>, uuid?: string}>
	 */
	public static function onNode(array $rJobs): array {
		return array_values(array_filter(self::clean($rJobs), static fn(array $rJob): bool => !in_array($rJob['type'], self::REBUILDS, true)));
	}

	/**
	 * How many targets jobs in job()'s form name: one for an id or a uuid,
	 * one per id of a list.
	 *
	 * @param list<array{type: string, id?: int|list<int>, uuid?: string}> $rJobs
	 */
	public static function targets(array $rJobs): int {
		$rTargets = 0;
		foreach ($rJobs as $rJob) {
			$rTargets += is_array($rJob['id'] ?? null) ? count($rJob['id']) : 1;
		}
		return $rTargets;
	}

	/**
	 * The `node.cache` commands MAIN sends for jobs: the jobs in job()'s
	 * form, in order, no command naming more than MAX targets. A list of ids
	 * longer than what is left of a command goes on in the next one, as a
	 * job of the same type.
	 *
	 * @param list<mixed> $rJobs
	 * @return list<list<array{type: string, id?: int|list<int>, uuid?: string}>> each command's jobs
	 */
	public static function commands(array $rJobs): array {
		$rCommands = [];
		$rCommand = [];
		$rRoom = self::MAX;
		foreach (self::clean($rJobs) as $rJob) {
			$rIDs = is_array($rJob['id'] ?? null) ? $rJob['id'] : null;
			while (true) {
				if ($rRoom === 0) {
					$rCommands[] = $rCommand;
					$rCommand = [];
					$rRoom = self::MAX;
				}
				if ($rIDs === null) {
					$rCommand[] = $rJob;
					$rRoom--;
					break;
				}
				$rPart = array_splice($rIDs, 0, $rRoom);
				$rCommand[] = ['type' => $rJob['type'], 'id' => $rPart];
				$rRoom -= count($rPart);
				if ($rIDs === []) {
					break;
				}
			}
		}
		if ($rCommand !== []) {
			$rCommands[] = $rCommand;
		}
		return $rCommands;
	}

	/**
	 * Run jobs as the `signals` rows carry them (`custom_data`), in order,
	 * as the signals daemon always did: the stream and line cache rebuilds
	 * go last, one each for all the jobs.
	 *
	 * @param list<mixed> $rJobs
	 */
	public static function run(array $rJobs): void {
		$rUpdatedStreams = $rUpdatedLines = [];
		foreach ($rJobs as $rCustomData) {
			switch ($rCustomData['type'] ?? null) {
				case 'update_stream':
					if (!in_array($rCustomData['id'], $rUpdatedStreams)) {
						$rUpdatedStreams[] = $rCustomData['id'];
					}
					break;
				case 'update_line':
					if (!in_array($rCustomData['id'], $rUpdatedLines)) {
						$rUpdatedLines[] = $rCustomData['id'];
					}
					break;
				case 'update_streams':
					foreach ($rCustomData['id'] as $rID) {
						if (!in_array($rID, $rUpdatedStreams)) {
							$rUpdatedStreams[] = $rID;
						}
					}
					break;
				case 'update_lines':
					foreach ($rCustomData['id'] as $rID) {
						if (!in_array($rID, $rUpdatedLines)) {
							$rUpdatedLines[] = $rID;
						}
					}
					break;
				case 'delete_con':
					// The connection file may already be gone (the stream
					// closed between the signal being queued and processed);
					// suppress the harmless "No such file" warning.
					@unlink(CONS_TMP_PATH . $rCustomData['uuid']);
					break;
				case 'drop_con':
					// A daemon-served viewer on this node was kicked
					// from another (ConnectionTracker::dropDaemonViewer).
					FanoutClient::dropConnection((string) ($rCustomData['uuid'] ?? ''));
					break;
				case 'delete_vod':
					self::deleteVod(intval($rCustomData['id']));
					break;
				case 'delete_vods':
					foreach ($rCustomData['id'] as $rID) {
						self::deleteVod(intval($rID));
					}
					break;
			}
		}
		self::rebuild('streams_update', $rUpdatedStreams);
		self::rebuild('lines_update', $rUpdatedLines);
	}

	/**
	 * Remove a movie's files, `content/vod/<id>.*`, as `rm` did with that
	 * pattern: every entry it matches but a directory (rm had no -r), a
	 * symlink itself and not what it points to. Silent: an id with no file,
	 * or an entry that stays.
	 */
	private static function deleteVod(int $rID): void {
		foreach (glob(MAIN_HOME . 'content/vod/' . $rID . '.*') ?: [] as $rFile) {
			if (is_link($rFile) || !is_dir($rFile)) {
				@unlink($rFile);
			}
		}
	}

	/**
	 * MAIN's cache rebuild for $rIDs: one cron:cache_engine, from an argv
	 * list, its type and its ids (joined by commas) one argument each, as the
	 * quoted words of the shell line it was. Only ids in job()'s form go, as
	 * integers, each once; none left, nothing runs.
	 *
	 * @param list<mixed> $rIDs
	 */
	private static function rebuild(string $rType, array $rIDs): void {
		$rIDs = array_values(array_unique(array_filter(array_map(self::id(...), $rIDs), static fn(?int $rID): bool => $rID !== null)));
		if ($rIDs !== []) {
			ProcessRunner::run([PHP_BIN, MAIN_HOME . 'console.php', 'cron:cache_engine', $rType, implode(',', $rIDs)]);
		}
	}
}
