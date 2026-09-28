<?php

namespace XcVm\Core\Cluster;

/**
 * The R2 `streams` section as the node's agent stores it (plan, section 9),
 * for the node's PHP to read: one `replica/streams/<id>.json` per stream the
 * node holds, `{etag, ver, data}` with the `stream` record's data exactly as
 * MAIN signed it (ReplicaSections), and `replica/streams.json`, `{since}`,
 * the cursor of the section the files hold. A cursor of 0 or none means the
 * agent has not completed a pass over every stream: the files are then not
 * the whole section. Once the cursor is above 0 the agent keeps the
 * `streams/` directory, empty when the node holds nothing, so a missing or
 * unreadable one is a section lost, never an empty one.
 *
 * Every reader of the records answers null unless the whole section is
 * there and every file reads: a caller that prunes files by the list
 * (cron:cleanup's archive and stream checks) must never take a partial list
 * for the node's streams. It then keeps its own source (MAIN's database
 * while the node has one) or does nothing; ReplicaStreamCache::owned() says
 * whether the replica owns the streams (STREAMS on and an apply built the
 * stream caches). ids() and since() give the section's shape by the files'
 * names alone, for cluster:apply, which builds the stream caches the node's
 * readers take (ReplicaApply::streams, ReplicaStreamCache) record by record.
 *
 * The section carries what MAIN decides, never the node's runtime state:
 * cron:cleanup's VOD check takes its carried columns from assigned([2, 5])
 * (`stream.target_container`, `stream.movie_properties`,
 * `stream.direct_source`, `server.server_stream_id`), and the `pid > 0` and
 * `stream_status` it filters on from the node's own store (StreamRuntime;
 * NodeStreams::vodChecks).
 *
 * The agent writes these files once each record opened for the node and
 * verified under the pinned panel key; like the other sections' `.json`,
 * PHP trusts them as they are.
 */
final class ReplicaStreams {
	/**
	 * Every stream record the node holds: stream id => data. Null without a
	 * whole section (no cursor above 0, no readable `streams/`), or when a
	 * file does not read as the record of its id.
	 *
	 * @return array<int, array<string, mixed>>|null
	 */
	public static function records(): ?array {
		$rIDs = self::ids();
		if (!is_array($rIDs)) {
			return null;
		}
		$rOut = [];
		foreach ($rIDs as $rID) {
			// The file's whole shape, as every reader of it checks, then its id.
			$rData = ReplicaRecords::storedStream(ReplicaApply::dir(), $rID)['data'] ?? null;
			if (!is_array($rData) || !is_array($rData['stream'] ?? null) || ($rData['stream']['id'] ?? null) !== $rID) {
				return null;
			}
			$rOut[$rID] = $rData;
		}
		return $rOut;
	}

	/**
	 * The cursor of the section the files hold (`streams.json`), 0 when there
	 * is none: the agent has not completed a pass over every stream.
	 */
	public static function since(): int {
		$rIndex = json_decode((string) @file_get_contents(ReplicaApply::dir() . 'streams.json'), true);
		return is_array($rIndex) && is_int($rIndex['since'] ?? null) && $rIndex['since'] > 0 ? $rIndex['since'] : 0;
	}

	/**
	 * The ids of the streams the node holds, ascending, by the names of the
	 * files alone: a file that does not read still names a stream the node
	 * holds. Null without a whole section (no cursor above 0, no readable
	 * `streams/`); false when a file's name is not a stream id.
	 *
	 * @return list<int>|false|null
	 */
	public static function ids(): array|false|null {
		$rDir = ReplicaApply::dir();
		if (self::since() <= 0) {
			return null;
		}
		// A missing or unreadable directory is never a node that holds nothing.
		if (!is_dir($rDir . 'streams') || !is_readable($rDir . 'streams')) {
			return null;
		}
		$rFiles = glob($rDir . 'streams/*.json', GLOB_ERR);
		if ($rFiles === false) {
			return null;
		}
		return FileIds::of($rFiles, '.json', true);
	}

	/**
	 * The streams assigned to this node (its record has a `server` row), of
	 * these `streams.type`s (every type when empty): stream id => data.
	 *
	 * @param list<int> $rTypes
	 * @return array<int, array<string, mixed>>|null
	 */
	public static function assigned(array $rTypes = []): ?array {
		$rRecords = self::records();
		if ($rRecords === null) {
			return null;
		}
		return array_filter($rRecords, static fn(array $rData): bool => is_array($rData['server'] ?? null) && ($rTypes === [] || in_array((int) ($rData['stream']['type'] ?? 0), $rTypes, true)));
	}

	/**
	 * The TV archives this node records: live streams (type 1) whose
	 * `tv_archive_server_id` is it, with a duration: stream id => days.
	 *
	 * @return array<int, int>|null
	 */
	public static function archives(int $rServerID): ?array {
		$rRecords = self::records();
		if ($rRecords === null) {
			return null;
		}
		$rOut = [];
		foreach ($rRecords as $rID => $rData) {
			$rStream = $rData['stream'];
			if ((int) ($rStream['type'] ?? 0) === 1 && (int) ($rStream['tv_archive_server_id'] ?? 0) === $rServerID && (int) ($rStream['tv_archive_duration'] ?? 0) > 0) {
				$rOut[$rID] = (int) $rStream['tv_archive_duration'];
			}
		}
		return $rOut;
	}
}
