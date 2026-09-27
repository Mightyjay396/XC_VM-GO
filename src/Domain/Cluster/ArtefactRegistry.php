<?php

namespace XcVm\Domain\Cluster;

use XcVm\Cli\Commands\AgentBinaryCommand;
use XcVm\Core\Cluster\ArtefactStage;
use XcVm\Core\Cluster\ReplicaSections;
use XcVm\Core\Module\ModuleManager;

/**
 * What MAIN may hand a node as an artefact (plan, section 7: the `artefact`
 * op, "off-air videos, pinned binaries"), each by an id that names it and
 * never a path:
 *
 * | Id | File on MAIN | Used by |
 * | --- | --- | --- |
 * | `offair/<name>` | the admin's custom video for that off-air name (`<name>_video_path`, as `cluster.off_air` names its file) | `artefact.fetch` (cluster:exec) |
 * | `module/<name>/<version>` | the custom module's archive MAIN keeps (ModuleManager::archivePathFor) | `node.root install_module` |
 * | `agent/<arch>` | the xc_agent binary MAIN pinned and verified (`agent_binary` cache, with its version) | `node.root agent_binary` |
 *
 * locate() resolves an id from MAIN's own configuration alone. The id's
 * shape is checked first (ArtefactStage::validId), a module archive or an
 * agent binary must be a file inside its own directory once links are
 * resolved, and an off-air video must be a local video file with a name a
 * node may write. Anything else is not an artefact. A node names only a
 * grant (a command's id), whose artefact id MAIN wrote itself.
 */
final class ArtefactRegistry {
	/** The largest artefact of each kind MAIN serves (bytes). */
	public const MAX_SIZE = ['offair' => 268435456, 'module' => 67108864, 'agent' => 134217728];

	/** An off-air video is served as MPEG-TS (live.php): these extensions only. */
	public const VIDEO_EXTENSIONS = ['ts', 'm2ts', 'mts', 'mpegts', 'mp4'];

	/** cluster_meta: each artefact's SHA-256, kept by the file it was taken from. */
	private const HASHES = 'artefact_hashes';

	private static ?string $rModules = null;

	private static ?string $rAgents = null;

	/** @var array<string, array{path: string, size: int, mtime: int, ino: int, sha256: string}>|null */
	private static ?array $rHashes = null;

	/** Tests: other directories for module archives and the agent cache; null restores the defaults. */
	public static function useDirs(?string $rModuleArchives, ?string $rAgentCache): void {
		self::$rModules = $rModuleArchives;
		self::$rAgents = $rAgentCache;
		self::$rHashes = null;
	}

	/**
	 * Where artefact $rId is on MAIN now.
	 *
	 * @param array<string, mixed> $rSettings MAIN's settings (the off-air videos' paths).
	 * @return array{id: string, kind: string, path: string, name: string, size: int, mtime: int, ino: int, version: ?string}|null
	 */
	public static function locate(string $rId, array $rSettings): ?array {
		if (!ArtefactStage::validId($rId)) {
			return null;
		}
		$rParts = explode('/', $rId);
		$rVersion = null;
		switch ($rParts[0]) {
			case 'offair':
				$rSetting = $rSettings[ReplicaSections::OFF_AIR[$rParts[1]]] ?? null;
				$rPath = is_string($rSetting) ? trim($rSetting) : '';
				if (!str_starts_with($rPath, '/') || !in_array(strtolower(pathinfo($rPath, PATHINFO_EXTENSION)), self::VIDEO_EXTENSIONS, true)) {
					return null;
				}
				$rDir = null;
				break;
			case 'module':
				try {
					$rDir = self::$rModules ?? dirname((new ModuleManager())->archivePathFor($rParts[1], $rParts[2])) . '/';
				} catch (\Throwable) {
					return null;
				}
				$rPath = $rDir . $rParts[1] . '_' . $rParts[2] . '.zip';
				break;
			default:
				$rDir = self::$rAgents ?? AgentBinaryCommand::cacheDir();
				$rPath = $rDir . AgentBinaryCommand::ASSET_PREFIX . $rParts[1];
				// Pinned: only a binary AgentBinaryCommand verified and recorded.
				$rVersion = trim((string) @file_get_contents($rPath . '.version'));
				if (!preg_match('/^[0-9A-Za-z][0-9A-Za-z._+-]{0,31}\z/', $rVersion)) {
					return null;
				}
		}
		$rName = basename($rPath);
		clearstatcache(true, $rPath);
		$rReal = realpath($rPath);
		if (!preg_match(ArtefactStage::NAME_PATTERN, $rName) || $rReal === false || !is_file($rReal)) {
			return null;
		}
		if ($rDir !== null && dirname($rReal) !== rtrim((string) realpath($rDir), '/')) {
			return null; // a link out of MAIN's own directory
		}
		$rStat = @stat($rReal);
		if ($rStat === false || $rStat['size'] < 1 || $rStat['size'] > self::MAX_SIZE[$rParts[0]]) {
			return null;
		}
		return ['id' => $rId, 'kind' => $rParts[0], 'path' => $rReal, 'name' => $rName, 'size' => (int) $rStat['size'], 'mtime' => (int) $rStat['mtime'], 'ino' => (int) $rStat['ino'], 'version' => $rVersion];
	}

	/**
	 * locate() and the artefact's SHA-256, hashed again only when the file
	 * changed (its path, size, mtime or inode).
	 *
	 * @param array<string, mixed> $rSettings
	 * @return array{id: string, kind: string, path: string, name: string, size: int, mtime: int, ino: int, version: ?string, sha256: string}|null
	 */
	public static function describe(string $rId, array $rSettings): ?array {
		$rFound = self::locate($rId, $rSettings);
		if ($rFound === null) {
			return null;
		}
		$rKnown = self::hashes()[$rId] ?? null;
		$rSame = is_array($rKnown) && [$rKnown['path'] ?? null, $rKnown['size'] ?? null, $rKnown['mtime'] ?? null, $rKnown['ino'] ?? null] === [$rFound['path'], $rFound['size'], $rFound['mtime'], $rFound['ino']];
		if ($rSame && is_string($rKnown['sha256'] ?? null)) {
			return $rFound + ['sha256' => (string) $rKnown['sha256']];
		}
		$rHash = @hash_file('sha256', $rFound['path']);
		if (!is_string($rHash)) {
			return null;
		}
		// The file as it was hashed: one that changed meanwhile is not described.
		if (self::locate($rId, $rSettings) !== $rFound) {
			return null;
		}
		self::$rHashes[$rId] = ['path' => $rFound['path'], 'size' => $rFound['size'], 'mtime' => $rFound['mtime'], 'ino' => $rFound['ino'], 'sha256' => $rHash];
		try {
			ClusterMeta::set(self::HASHES, (string) json_encode(self::$rHashes, JSON_UNESCAPED_SLASHES));
		} catch (\Throwable) {
			// Hashed again next time.
		}
		return $rFound + ['sha256' => $rHash];
	}

	/**
	 * The off-air videos MAIN serves now: `offair/<name>` for each off-air
	 * name whose setting names a video MAIN can serve (none for the node's
	 * own default videos).
	 *
	 * @param array<string, mixed> $rSettings
	 * @return list<string>
	 */
	public static function offAirIds(array $rSettings): array {
		$rIds = [];
		foreach (array_keys(ReplicaSections::OFF_AIR) as $rName) {
			if (self::locate('offair/' . $rName, $rSettings) !== null) {
				$rIds[] = 'offair/' . $rName;
			}
		}
		return $rIds;
	}

	/** @return array<string, array<string, mixed>> */
	private static function hashes(): array {
		if (self::$rHashes === null) {
			try {
				$rKnown = json_decode((string) ClusterMeta::get(self::HASHES), true);
			} catch (\Throwable) {
				$rKnown = null;
			}
			self::$rHashes = is_array($rKnown) ? $rKnown : [];
		}
		return self::$rHashes;
	}
}
