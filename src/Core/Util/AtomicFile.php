<?php

namespace XcVm\Core\Util;

/**
 * Atomic File
 *
 * Replace a file so that a reader sees either the old content or the new,
 * never half of it: the data is written aside, in the same directory (so
 * rename() stays on one filesystem), and renamed over the target. The file
 * aside is hidden (a reader globbing `*` or `*.json` never picks it up) and
 * named per writer (pid and a random part), so concurrent writers never
 * write into each other's file; it is created exclusively, so a file or
 * link planted under its name is never followed or written through. On any
 * failure it is removed and the target is left as it was.
 *
 * Core, no dependencies: usable on MAIN and on a load balancer alike.
 *
 * @package XC_VM_Core_Util
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

final class AtomicFile {
	/**
	 * Replace $rPath with $rData. The directory must exist; the caller makes
	 * it (with the mode it wants).
	 *
	 * @param int|null $rMode   Permissions of the new file (set before the
	 *                          data is written); null keeps what the umask gives.
	 * @param bool     $rSync   Flush the data to disk (fdatasync) before the
	 *                          rename, so a crash never leaves an empty file.
	 * @param (callable(string): bool)|null $rPrepare Run on the written file
	 *                          aside (its path) before it is renamed in, e.g.
	 *                          root handing it to another user; false aborts.
	 * @return bool True once the new content is in place.
	 */
	public static function write(string $rPath, string $rData, ?int $rMode = null, bool $rSync = false, ?callable $rPrepare = null): bool {
		$rTmp = self::tempPath($rPath);
		$rHandle = @fopen($rTmp, 'x');
		if ($rHandle === false) {
			return false;
		}
		$rOk = ($rMode === null || @chmod($rTmp, $rMode))
			&& @fwrite($rHandle, $rData) === strlen($rData)
			&& fflush($rHandle)
			&& (!$rSync || fdatasync($rHandle));
		$rOk = fclose($rHandle) && $rOk;
		if (!$rOk || ($rPrepare !== null && !$rPrepare($rTmp)) || !@rename($rTmp, $rPath)) {
			@unlink($rTmp);
			return false;
		}
		return true;
	}

	/** Where $rPath is written aside: hidden, beside it, this writer's own. */
	private static function tempPath(string $rPath): string {
		return dirname($rPath) . '/.' . basename($rPath) . '.' . getmypid() . '.' . bin2hex(random_bytes(4)) . '.tmp';
	}
}
