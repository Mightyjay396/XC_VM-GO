<?php

namespace XcVm\Domain\Stream;

use XcVm\Core\Cluster\ReplicaStreamCache;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * Stream Source
 *
 * Where a node reads the definition of a stream it is about to run: the
 * `streams` row (with its type and transcode profile), this node's
 * `streams_servers` row, the stream's options (user agent, proxy, cookie,
 * headers, …) and the recordings scheduled on it. The launcher, the
 * monitor, the proxy producer, live.php, the scanner and the recorder used
 * to run these queries themselves.
 *
 * The legacy backend reads MAIN's database, as they did. Once the node's
 * replica owns the streams (the STREAMS flow on, and cluster:apply built
 * the stream caches from the R2 `streams` section: ReplicaStreamCache),
 * every answer about this node comes from those caches, in the same shapes,
 * and none reads MAIN's database: a stream the node does not hold is no
 * stream. What no record carries (the node's runtime columns, MAIN's
 * catalogue metadata, an argument's description) is null there.
 * `stream_bundle` on a miss (plan, section 7) is not built.
 *
 * @package XC_VM_Domain_Stream
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class StreamSource {
	/** @var (callable(string, int, array<string, mixed>): mixed)|null */
	private static $rLoader;

	/**
	 * The stream's row joined with its type (live or not) and transcode
	 * profile. Direct-source streams are not run by a node, so they are
	 * excluded.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function streamRow(int $rStreamID, bool $rLive, ?object $rDb = null): ?array {
		if (self::$rLoader !== null) {
			return (self::$rLoader)('stream', $rStreamID, ['live' => $rLive]);
		}
		if (ReplicaStreamCache::owned()) {
			return ReplicaStreamCache::streamRow($rStreamID, $rLive);
		}
		$rDb ??= DatabaseFactory::get();
		$rDb->query('SELECT * FROM `streams` t1 INNER JOIN `streams_types` t2 ON t2.type_id = t1.type AND t2.live = ' . ($rLive ? 1 : 0) . ' LEFT JOIN `profiles` t4 ON t1.transcode_profile_id = t4.profile_id WHERE t1.direct_source = 0 AND t1.id = ?', $rStreamID);
		return $rDb->num_rows() > 0 ? $rDb->get_row() : null;
	}

	/**
	 * A node's `streams_servers` row for the stream (this node by default).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function serverRow(int $rStreamID, ?int $rServerID = null, ?object $rDb = null): ?array {
		$rServerID ??= intval(SERVER_ID);
		if (self::$rLoader !== null) {
			return (self::$rLoader)('server', $rStreamID, ['server_id' => $rServerID]);
		}
		if ($rServerID === intval(SERVER_ID) && ReplicaStreamCache::owned()) {
			return ReplicaStreamCache::serverRow($rStreamID);
		}
		$rDb ??= DatabaseFactory::get();
		$rDb->query('SELECT * FROM `streams_servers` WHERE stream_id = ? AND `server_id` = ?', $rStreamID, $rServerID);
		return $rDb->num_rows() > 0 ? $rDb->get_row() : null;
	}

	/**
	 * The stream's options joined with their argument definitions, as a list
	 * or keyed by `argument_key`.
	 *
	 * @return array<int|string, array<string, mixed>>
	 */
	public static function arguments(int $rStreamID, bool $rKeyed = false, ?object $rDb = null): array {
		if (self::$rLoader !== null) {
			return (self::$rLoader)('arguments', $rStreamID, ['keyed' => $rKeyed]);
		}
		if (ReplicaStreamCache::owned()) {
			return ReplicaStreamCache::arguments($rStreamID, $rKeyed);
		}
		$rDb ??= DatabaseFactory::get();
		$rDb->query('SELECT t1.*, t2.* FROM `streams_options` t1, `streams_arguments` t2 WHERE t1.stream_id = ? AND t1.argument_id = t2.id', $rStreamID);
		return ($rKeyed ? $rDb->get_rows(true, 'argument_key') : $rDb->get_rows()) ?: [];
	}

	/**
	 * Just the source list of a stream (`stream_source`, JSON), for callers
	 * that only pull the source (the fanout proxy hand-off).
	 *
	 * @return array{stream_source?: string}
	 */
	public static function sourceRow(int $rStreamID, ?object $rDb = null): array {
		if (self::$rLoader !== null) {
			return (self::$rLoader)('source', $rStreamID, []);
		}
		if (ReplicaStreamCache::owned()) {
			return ReplicaStreamCache::sourceRow($rStreamID);
		}
		$rDb ??= DatabaseFactory::get();
		$rDb->query('SELECT `stream_source` FROM `streams` WHERE `id` = ?', $rStreamID);
		return $rDb->num_rows() > 0 ? $rDb->get_row() : [];
	}

	/**
	 * A recording's row (every `recordings` column), for the recorder: once
	 * the replica owns the streams, one scheduled on this node, with the
	 * `status` MAIN last heard.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function recording(int $rRecordingID, ?object $rDb = null): ?array {
		if (self::$rLoader !== null) {
			return (self::$rLoader)('recording', $rRecordingID, []);
		}
		if (ReplicaStreamCache::owned()) {
			return ReplicaStreamCache::recording($rRecordingID);
		}
		$rDb ??= DatabaseFactory::get();
		$rDb->query('SELECT * FROM `recordings` WHERE `id` = ?;', $rRecordingID);
		return $rDb->num_rows() > 0 ? $rDb->get_row() : null;
	}

	/** Replace the backend (tests; later the cluster API). Null restores the SQL backend. */
	public static function useLoader(?callable $rLoader): void {
		self::$rLoader = $rLoader;
	}
}
