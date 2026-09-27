<?php

namespace XcVm\Core\Module;

/**
 * A module-owned kind of live source: the module claims source URLs of its own
 * scheme and runs its own producer process for them, in ffmpeg's place.
 *
 * Declared in module.json (`"source_drivers": [FQCN, …]`) and loaded by
 * {@see SourceDriverRegistry} WITHOUT ModuleLoader::bootAll() — a viewer's
 * request can start a stream from the streaming entry point — so an
 * implementation takes no constructor arguments, uses no container services and
 * reaches the database only through DatabaseAware + self::db().
 *
 * The producer contract (process, disk HLS, ingest, progress) is in
 * docs/en/development/source-drivers.md. Core enforces the stream settings a
 * driver cannot honour (transcode, custom maps, RTMP output, delay…) itself.
 *
 * @package XC_VM_Core_Module
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
interface SourceDriverInterface {
	/**
	 * URL schemes this driver owns, lowercase, without "://".
	 *
	 * @return string[]
	 */
	public function schemes(): array;

	/**
	 * Basename of the engine executable. Core recognises a running producer by
	 * it, so it must be stable across upgrades and unique among producers.
	 */
	public function binary(): string;

	/**
	 * The producer's argv; element 0 is the absolute path of binary(). Core
	 * escapes every element and adds the redirections and pid handling itself.
	 *
	 * @param array $ctx stream_id, url, label, fetch, hls{dir,playlist,segment_pattern,
	 *                   seg_time,list_size,delete_threshold}, ingest, progress_path,
	 *                   errors_path, supervised.
	 * @return string[]
	 */
	public function buildArgv(array $ctx): array;

	/**
	 * Whether the source is reachable now. Called instead of ffprobe on the
	 * source URL, so it must return within a few seconds.
	 */
	public function available(int $streamId, string $url): bool;
}
