<?php

namespace XcVm\Core\Cluster;

use XcVm\Core\Logging\FileLogger;
use XcVm\Core\Updates\ReleaseAsset;

/**
 * Artefacts on the node (plan, section 7: the `artefact` op and "Root
 * artefacts"; section 8: custom off-air videos "via artefact"): files MAIN
 * hands a node through a grant, a `cmd`-signed command that names the
 * artefact's id, size, SHA-256 and expiry.
 *
 * ```text
 * grant  = args.artefact {id, name, size, sha256, mtime, exp}
 *          id: offair/<name> | module/<name>/<version> | agent/<arch>
 * agent  → config/cluster/artefacts/<cmd_id>        xc_vm, 0600: its download,
 *                                                   checked by the agent itself
 * off-air video (artefact.fetch, cluster:exec, xc_vm)
 *        → content/video/cluster/<name>             checked, then renamed in
 * root artefact (node.root carrying a grant, cluster:root, root)
 *        → /etc/xc_vm/cluster/stage/<cmd_id>        root:root 0700 / 0600: copied
 *                                                   and checked there, then the
 *                                                   action runs with that copy
 * ```
 *
 * Whatever the agent checked, the bytes a node uses are checked here against
 * the signed grant's size and SHA-256 as they are copied to where they are
 * used, and anything else is refused and audited (refuse()): a `log.syslog`
 * line of type ARTEFACT for MAIN's system log, and the refusal in the
 * command's ack, which MAIN audits too. Root reads the agent's download only
 * with the agent's rights (SettingsAudit::asAgentUser), never follows a link
 * planted there, and runs the action on its own copy, which nothing but root
 * can change. Everything a grant names is data: the id and name are checked
 * for shape, and no path comes from anywhere but the node's own directories.
 */
final class ArtefactStage {
	/** The largest chunk the `artefact` op serves (plan section 7: ≤ 4 MB). */
	public const MAX_CHUNK = 4194304;

	/** The command that grants an off-air video (run by cluster:exec). */
	public const TYPE_FETCH = 'artefact.fetch';

	/** A file name the node writes an artefact under: no path, no dot file. */
	public const NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/';

	/** A staged copy older than this is a crash's leftover (cluster:root prunes it). */
	public const STAGE_TTL = 3600;

	/** Bytes copied at a time. */
	private const BLOCK = 1048576;

	private static ?string $rDownloads = null;

	private static ?string $rVideos = null;

	/**
	 * The root artefact the action cluster:root runs now was staged as, while
	 * it runs (withStaged()); null otherwise, and always on the signals path.
	 *
	 * @var array{path: string, grant: array<string, mixed>}|null
	 */
	private static ?array $rCurrent = null;

	/** Tests: other directories; null restores the defaults. */
	public static function useDirs(?string $rDownloads, ?string $rVideos): void {
		self::$rDownloads = $rDownloads;
		self::$rVideos = $rVideos;
	}

	/** Where the agent writes what it downloaded, one file per command: `<cmd_id>`. */
	public static function downloads(): string {
		return self::$rDownloads ?? ((defined('CONFIG_PATH') ? CONFIG_PATH : '/home/xc_vm/config/') . 'cluster/artefacts/');
	}

	/** Root's own stage, beside its pin of the panel key. */
	public static function stageDir(): string {
		return RootPin::dir() . 'stage/';
	}

	/** Where the node keeps the off-air videos MAIN granted it. */
	public static function videoDir(): string {
		return self::$rVideos ?? ((defined('VIDEO_PATH') ? VIDEO_PATH : '/home/xc_vm/content/video/') . 'cluster/');
	}

	/**
	 * Is this an artefact id of MAIN's registry? `offair/<name>` for the
	 * off-air names the `cluster` section carries, `module/<name>/<version>`
	 * as ModuleManager names a custom module's archive, `agent/<arch>` for a
	 * release arch. Shape only: MAIN resolves it, never a node.
	 */
	public static function validId(string $rId): bool {
		$rParts = explode('/', $rId);
		return match ($rParts[0]) {
			'offair' => count($rParts) === 2 && isset(ReplicaSections::OFF_AIR[$rParts[1]]),
			'module' => count($rParts) === 3 && preg_match('/^[a-z0-9][a-z0-9-]{0,63}\z/', $rParts[1]) === 1
				&& preg_match('/^[0-9A-Za-z][0-9A-Za-z._-]{0,31}\z/', $rParts[2]) === 1 && !str_contains($rParts[2], '..'),
			'agent' => count($rParts) === 2 && in_array($rParts[1], ReleaseAsset::ARCH_MAP, true),
			default => false,
		};
	}

	/**
	 * A command's artefact grant, checked for shape.
	 *
	 * @param array<string, mixed> $rCmd The signed command, decoded.
	 * @return array{id: string, name: string, size: int, sha256: string, exp: int, cmd_id: string}|string the grant, or why it is refused
	 */
	public static function grant(array $rCmd): array|string {
		$rGrant = $rCmd['args']['artefact'] ?? null;
		$rCmdID = $rCmd['cmd_id'] ?? null;
		if (!is_array($rGrant) || !is_string($rCmdID) || !preg_match('/^[0-9a-f]{32}\z/', $rCmdID)) {
			return 'no artefact grant';
		}
		$rId = $rGrant['id'] ?? null;
		$rName = $rGrant['name'] ?? null;
		$rSize = $rGrant['size'] ?? null;
		$rHash = $rGrant['sha256'] ?? null;
		if (!is_string($rId) || !self::validId($rId)) {
			return 'not an artefact MAIN serves';
		}
		if (!is_string($rName) || !preg_match(self::NAME_PATTERN, $rName)) {
			return 'not a file name';
		}
		if (!is_int($rSize) || $rSize < 1 || !is_string($rHash) || !preg_match('/^[0-9a-f]{64}\z/', $rHash) || !is_int($rGrant['exp'] ?? null)) {
			return 'a malformed grant';
		}
		return ['id' => $rId, 'name' => $rName, 'size' => $rSize, 'sha256' => $rHash, 'exp' => (int) $rGrant['exp'], 'cmd_id' => $rCmdID];
	}

	/**
	 * `artefact.fetch` (cluster:exec, as xc_vm): the off-air video the agent
	 * downloaded, placed as `<videoDir>/<name>` once its size and SHA-256
	 * are the grant's. The copy is written aside and renamed in, so a player
	 * never reads half a video, and a refused one leaves the video placed
	 * before where it was.
	 *
	 * @param array<string, mixed> $rCmd The signed command, decoded.
	 * @return array{placed: string, size: int, sha256: string}|string what was placed, or the refusal (audited)
	 */
	public static function placeOffAir(array $rCmd): array|string {
		$rGrant = self::grant($rCmd);
		if (is_string($rGrant)) {
			return self::refuse($rCmd, $rGrant);
		}
		if (!str_starts_with($rGrant['id'], 'offair/')) {
			return self::refuse($rCmd, 'not an off-air video');
		}
		$rWhy = 'the agent\'s directory is not the agent\'s';
		$rDone = SettingsAudit::asAgentUser(static function () use ($rGrant, &$rWhy): bool {
			$rIn = self::openDownload($rGrant, $rWhy);
			if ($rIn !== null) {
				$rWhy = self::placeVideo($rIn, $rGrant);
				fclose($rIn);
			}
			// Spent, placed or not (a link planted there goes, never what it names).
			@unlink(self::downloads() . $rGrant['cmd_id']);
			return $rWhy === null;
		}, self::agentDir());
		if (!$rDone) {
			return self::refuse($rCmd, (string) $rWhy);
		}
		return ['placed' => $rGrant['name'], 'size' => $rGrant['size'], 'sha256' => $rGrant['sha256']];
	}

	/**
	 * Copy an off-air video into the node's video directory, checked as it is
	 * written aside, then rename it in. Null once placed, else why not.
	 *
	 * @param resource $rIn
	 * @param array<string, mixed> $rGrant
	 */
	private static function placeVideo($rIn, array $rGrant): ?string {
		$rDir = self::videoDir();
		if (!is_dir($rDir) && !@mkdir($rDir, 0755, true) && !is_dir($rDir)) {
			return 'cannot create ' . $rDir;
		}
		$rTmp = $rDir . '.' . $rGrant['name'] . '.' . $rGrant['cmd_id'] . '.tmp';
		$rWhy = self::copyChecked($rIn, $rTmp, $rGrant, 0644);
		if ($rWhy === null && !@rename($rTmp, $rDir . $rGrant['name'])) {
			@unlink($rTmp);
			$rWhy = 'cannot place it';
		}
		return $rWhy;
	}

	/**
	 * A root command's artefact (cluster:root, as root): the agent's download,
	 * read with the agent's rights, copied into root's own stage and checked
	 * there. The agent's copy goes either way; the staged one stays for the
	 * action (withStaged()) and goes after it (discard()).
	 *
	 * @param array<string, mixed> $rCmd The verified command, decoded.
	 * @return array{path: string, grant: array<string, mixed>}|string the staged copy, or the refusal (audited)
	 */
	public static function stage(array $rCmd): array|string {
		$rGrant = self::grant($rCmd);
		if (is_string($rGrant)) {
			return self::refuse($rCmd, $rGrant);
		}
		$rStage = self::stageDir();
		if (!self::ownStage($rStage)) {
			return self::refuse($rCmd, 'root\'s stage ' . $rStage . ' is not root\'s alone');
		}
		$rWhy = 'the agent\'s directory is not the agent\'s';
		$rIn = null;
		SettingsAudit::asAgentUser(static function () use ($rGrant, &$rIn, &$rWhy): bool {
			$rIn = self::openDownload($rGrant, $rWhy);
			return $rIn !== null;
		}, self::agentDir());
		if ($rIn === null) {
			self::spend($rGrant['cmd_id']);
			return self::refuse($rCmd, (string) $rWhy);
		}
		$rPath = $rStage . $rGrant['cmd_id'];
		$rWhy = self::copyChecked($rIn, $rPath, $rGrant, 0600);
		fclose($rIn);
		self::spend($rGrant['cmd_id']);
		if ($rWhy !== null) {
			return self::refuse($rCmd, $rWhy);
		}
		return ['path' => $rPath, 'grant' => $rGrant];
	}

	/**
	 * Run a root action with its staged artefact, which current() hands it
	 * while it runs.
	 *
	 * @param array{path: string, grant: array<string, mixed>} $rStaged
	 * @template T
	 * @param callable(): T $rRun
	 * @return T
	 */
	public static function withStaged(array $rStaged, callable $rRun): mixed {
		self::$rCurrent = $rStaged;
		try {
			return $rRun();
		} finally {
			self::$rCurrent = null;
		}
	}

	/**
	 * The staged artefact of the root action running now: set only by
	 * cluster:root, after its checks. A `signals` row, or a payload naming a
	 * path, never gets one.
	 *
	 * @return array{path: string, grant: array<string, mixed>}|null
	 */
	public static function current(): ?array {
		return self::$rCurrent;
	}

	/** Remove a staged copy once its action ran. */
	public static function discard(array $rStaged): void {
		@unlink((string) $rStaged['path']);
	}

	/** Remove what a crash left staged. */
	public static function pruneStage(int $rNow): void {
		foreach (glob(self::stageDir() . '*') ?: [] as $rFile) {
			if (!is_link($rFile) && is_file($rFile) && (int) @filemtime($rFile) < $rNow - self::STAGE_TTL) {
				@unlink($rFile);
			}
		}
	}

	/**
	 * `agent_binary` (root): install the staged, checked agent binary where
	 * run.sh starts it, as the agent's user (the directory is xc_vm's), from
	 * root's copy. It is checked again as it is written, then renamed in.
	 *
	 * @param array{path: string, grant: array<string, mixed>} $rStaged
	 * @return string|null null once installed, else why not
	 */
	public static function installAgent(array $rStaged, string $rTarget): ?string {
		$rIn = @fopen((string) $rStaged['path'], 'rb');
		if ($rIn === false) {
			return 'the staged copy is gone';
		}
		$rDir = dirname($rTarget) . '/';
		$rWhy = 'the agent\'s directory is not the agent\'s';
		SettingsAudit::asAgentUser(static function () use ($rIn, $rDir, $rTarget, $rStaged, &$rWhy): bool {
			$rTmp = $rDir . '.' . basename($rTarget) . '.new';
			$rWhy = self::copyChecked($rIn, $rTmp, $rStaged['grant'], 0755);
			if ($rWhy === null && !@rename($rTmp, $rTarget)) {
				@unlink($rTmp);
				$rWhy = 'cannot install it';
			}
			return $rWhy === null;
		}, $rDir);
		fclose($rIn);
		return $rWhy;
	}

	/**
	 * module:install's archive, when root staged it (install_module with a
	 * grant): only a file in root's stage, and only the grant's bytes.
	 *
	 * @param array<string, mixed>|null $rGrant The command's grant, as the payload carries it.
	 * @return string|null null when it may be installed, else why not
	 */
	public static function stagedArchive(string $rPath, ?array $rGrant): ?string {
		$rStage = realpath(self::stageDir());
		$rReal = realpath($rPath);
		if ($rStage === false || $rReal === false || dirname($rReal) !== $rStage || is_link($rPath) || !is_file($rReal)) {
			return 'the archive is not in root\'s stage';
		}
		$rSize = $rGrant['size'] ?? null;
		$rHash = $rGrant['sha256'] ?? null;
		if (!is_int($rSize) || !is_string($rHash) || filesize($rReal) !== $rSize || !hash_equals($rHash, (string) hash_file('sha256', $rReal))) {
			return 'the archive is not the one MAIN granted';
		}
		return null;
	}

	/**
	 * The off-air video to play for a token's `video_path` (MAIN's path):
	 * the node's own file there when it has one, as before; else the copy of
	 * that video MAIN granted it (placeOffAir()), by the path's file name;
	 * else the path, as before.
	 */
	public static function offAirVideo(string $rPath): string {
		if (!str_starts_with($rPath, '/') || is_file($rPath)) {
			return $rPath;
		}
		$rName = basename($rPath);
		if (!preg_match(self::NAME_PATTERN, $rName) || !str_ends_with($rPath, '/' . $rName)) {
			return $rPath;
		}
		$rLocal = self::videoDir() . $rName;
		return is_file($rLocal) ? $rLocal : $rPath;
	}

	/**
	 * Refuse an artefact, audited: a system log line for MAIN (type
	 * ARTEFACT: a `log.syslog` event with LOGS on, else, in mode 2, the
	 * panel's error log), or the panel's error log where MAIN would take a
	 * row the node wrote itself (never MAIN's database from here). Returns
	 * the refusal, for the command's result.
	 *
	 * @param array<string, mixed> $rCmd The command, decoded.
	 */
	public static function refuse(array $rCmd, string $rWhy): string {
		$rGrant = is_array($rCmd['args']['artefact'] ?? null) ? $rCmd['args']['artefact'] : [];
		$rId = is_string($rGrant['id'] ?? null) ? substr((string) preg_replace('/[^\x21-\x7e]/', '?', $rGrant['id']), 0, 128) : '?';
		$rName = is_string($rGrant['name'] ?? null) ? substr((string) preg_replace('/[^\x21-\x7e]/', '?', $rGrant['name']), 0, 128) : '?';
		$rCmdID = is_string($rCmd['cmd_id'] ?? null) ? substr((string) preg_replace('/[^0-9a-f]/', '', $rCmd['cmd_id']), 0, 32) : '';
		$rLine = 'Refused artefact ' . $rId . ' (' . $rName . ') for command ' . $rCmdID . ': ' . $rWhy;
		if (!LogSink::syslog('ARTEFACT', $rLine)) {
			$rKept = SettingsAudit::asAgentUser(static function () use ($rLine): bool {
				FileLogger::log('artefact', $rLine);
				return true;
			}, self::agentDir());
			if (!$rKept) {
				error_log('XC_VM ' . $rLine);
			}
		}
		return 'artefact refused: ' . $rId . ' (' . $rName . '): ' . $rWhy;
	}

	/** The agent's state directory (config/cluster/), whose owner is the agent's user. */
	private static function agentDir(): string {
		return dirname(rtrim(self::downloads(), '/'));
	}

	/**
	 * Open the agent's download of a grant: a regular file, not a link,
	 * opened with the caller's rights (the agent's, under asAgentUser).
	 *
	 * @param array<string, mixed> $rGrant
	 * @return resource|null
	 */
	private static function openDownload(array $rGrant, ?string &$rWhy) {
		$rPath = self::downloads() . $rGrant['cmd_id'];
		clearstatcache(true, $rPath);
		$rLink = @lstat($rPath);
		if ($rLink === false) {
			$rWhy = 'not downloaded';
			return null;
		}
		if (($rLink['mode'] & 0170000) !== 0100000) {
			$rWhy = 'the download is not a file';
			return null;
		}
		$rIn = @fopen($rPath, 'rb');
		$rStat = $rIn === false ? false : fstat($rIn);
		if ($rIn === false || $rStat === false || $rStat['ino'] !== $rLink['ino'] || $rStat['dev'] !== $rLink['dev']) {
			if ($rIn !== false) {
				fclose($rIn);
			}
			$rWhy = 'the download changed while it was opened';
			return null;
		}
		return $rIn;
	}

	/**
	 * Copy what $rIn holds into a new file at $rDest, created exclusively
	 * (whatever was planted there goes first), hashing as it goes and
	 * reading at most one byte past the grant's size. Null when the bytes
	 * are the grant's; otherwise why not, and nothing is left at $rDest.
	 *
	 * @param resource $rIn
	 * @param array<string, mixed> $rGrant
	 */
	private static function copyChecked($rIn, string $rDest, array $rGrant, int $rMode): ?string {
		if (is_link($rDest) || file_exists($rDest)) {
			@unlink($rDest);
		}
		$rOut = @fopen($rDest, 'x');
		if ($rOut === false) {
			return 'cannot write ' . basename($rDest);
		}
		@chmod($rDest, $rMode);
		$rHash = hash_init('sha256');
		$rSize = (int) $rGrant['size'];
		$rLeft = $rSize + 1;
		$rCopied = 0;
		$rWritten = true;
		while ($rLeft > 0 && !feof($rIn)) {
			$rBlock = fread($rIn, min(self::BLOCK, $rLeft));
			if ($rBlock === false) {
				break;
			}
			if ($rBlock === '') {
				continue;
			}
			$rLeft -= strlen($rBlock);
			$rCopied += strlen($rBlock);
			hash_update($rHash, $rBlock);
			$rWritten = $rWritten && fwrite($rOut, $rBlock) === strlen($rBlock);
		}
		$rWritten = fflush($rOut) && $rWritten;
		fclose($rOut);
		$rWhy = match (true) {
			$rCopied !== $rSize => 'size mismatch (' . ($rCopied > $rSize ? 'more than ' . $rSize : $rCopied) . ' bytes, the grant says ' . $rSize . ')',
			!hash_equals((string) $rGrant['sha256'], hash_final($rHash)) => 'sha256 mismatch',
			!$rWritten => 'cannot write ' . basename($rDest),
			default => null,
		};
		if ($rWhy !== null) {
			@unlink($rDest);
		}
		return $rWhy;
	}

	/** Remove the agent's download of a command, as the agent's user. */
	private static function spend(string $rCmdID): void {
		SettingsAudit::asAgentUser(static function () use ($rCmdID): bool {
			@unlink(self::downloads() . $rCmdID);
			return true;
		}, self::agentDir());
	}

	/**
	 * Root's stage: created 0700 when missing, closed to others when it is
	 * already this process's, and trusted only when it is a directory (not a
	 * link) owned by this process's user (root) that no one else can enter.
	 */
	private static function ownStage(string $rStage): bool {
		$rDir = rtrim($rStage, '/');
		if (!file_exists($rDir) && !is_link($rDir)) {
			@mkdir($rDir, 0700);
		}
		clearstatcache(true, $rDir);
		$rStat = @lstat($rDir);
		if ($rStat === false || ($rStat['mode'] & 0170000) !== 0040000 || (function_exists('posix_geteuid') && $rStat['uid'] !== posix_geteuid())) {
			return false;
		}
		if (($rStat['mode'] & 0077) !== 0) {
			@chmod($rDir, 0700);
			clearstatcache(true, $rDir);
			$rStat = @lstat($rDir);
		}
		return $rStat !== false && ($rStat['mode'] & 0170000) === 0040000 && ($rStat['mode'] & 0077) === 0;
	}
}
