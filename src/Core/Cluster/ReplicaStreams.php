<?php

namespace XcVm\Core\Cluster;

/**
 * The R2 `streams` section as the node's agent stores it (plan, section 9),
 * for the node's PHP to read: one `replica/streams/<id>.json` per stream the
 * node holds, `{etag, ver, data}` with the `stream` record's data exactly as
 * MAIN signed it (ReplicaSections), and `replica/streams.json`, `{since}`,
 * the cursor of the section the files hold. A cursor of 0 or none means the
 * agent has not completed a pass over every stream: the files are then not
 * the whole section.
 *
 * Every reader answers null unless the whole section is there and every
 * file reads: a caller that prunes files by the list (cron:cleanup's
 * archive and stream checks) must never take a partial list for the node's
 * streams. It then keeps its own source (MAIN's database while the node has
 * one) or does nothing.
 *
 * The agent writes these files once each record opened for the node and
 * verified under the pinned panel key; like the other sections' `.json`,
 * PHP trusts them as they are.
 */
final class ReplicaStreams {
	/**
	 * Every stream record the node holds: stream id => data. Null without a
	 * whole section, or when a file does not read as the record of its id.
	 *
	 * @return array<int, array<string, mixed>>|null
	 */
	public static function records(): ?array {
		$rDir = ReplicaApply::dir();
		$rIndex = json_decode((string) @file_get_contents($rDir . 'streams.json'), true);
		if (!is_array($rIndex) || !is_int($rIndex['since'] ?? null) || $rIndex['since'] <= 0) {
			return null;
		}
		$rFiles = glob($rDir . 'streams/*.json');
		if ($rFiles === false) {
			return null;
		}
		$rOut = [];
		foreach ($rFiles as $rFile) {
			$rName = basename($rFile, '.json');
			if (!preg_match('/^[1-9][0-9]{0,9}$/', $rName)) {
				return null;
			}
			$rRecord = json_decode((string) @file_get_contents($rFile), true);
			$rData = is_array($rRecord) ? ($rRecord['data'] ?? null) : null;
			if (!is_array($rData) || !is_array($rData['stream'] ?? null) || ($rData['stream']['id'] ?? null) !== (int) $rName) {
				return null;
			}
			$rOut[(int) $rName] = $rData;
		}
		ksort($rOut);
		return $rOut;
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
