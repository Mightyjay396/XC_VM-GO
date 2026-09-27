<?php

namespace XcVm\Core\Config;

use XcVm\Core\Cluster\ReplicaApply;

/**
 * The viewer-token secret (`live_streaming_pass`) and the value it replaced.
 *
 * Every stream link, HLS key URL and admin preview token is minted under this
 * secret ({@see \XcVm\Core\Util\Encryption::mintToken}), and the links are
 * already in players' hands when it changes: the token a viewer sends next was
 * minted under the old value. Changing the secret used to break every one of
 * them at once, which is why an operator could not rotate a leaked secret
 * without a visible outage.
 *
 * The value replaced is therefore kept beside the config (`stream_secret.prev`,
 * 0600, as OPENSSL_EXTRA keeps its own) and accepted for PREVIOUS_WINDOW after
 * the change; `readToken()` tries it once the current value fails. The window is
 * a few minutes, not a rotation schedule: the plan's rotation (section 9.1,
 * step 5) also re-encrypts what is stored under the secret, and that is Phase 9.
 *
 * MAIN writes the file when the settings save changes the value; a node adopts
 * what the replica's `secrets` section sends ({@see ReplicaApply}), so both
 * sides accept the same two values.
 */
final class StreamSecret {
	/** How long a replaced secret is still accepted: longer than any HLS window. */
	public const PREVIOUS_WINDOW = 600;

	private const FILE = 'stream_secret.prev';

	/** @var array{value: string, valid_until: int}|false|null */
	private static array|bool|null $rPrevious = null;

	/** The mtime the cached value was read at: the file is written by another process. */
	private static ?int $rReadAt = null;

	private static ?string $rFile = null;

	/** The file the previous value lives in. */
	public static function file(): string {
		return self::$rFile ?? self::dir() . self::FILE;
	}

	/**
	 * Record that $rOld has been replaced, so tokens minted under it are still
	 * read for PREVIOUS_WINDOW. Replacing a value with itself keeps the file as
	 * it is, and an empty old value has nothing to keep.
	 */
	public static function replaced(string $rOld, string $rNew, ?int $rNow = null, int $rWindow = self::PREVIOUS_WINDOW): bool {
		if ($rOld === '' || $rOld === $rNew) {
			return false;
		}
		return self::keep(['value' => $rOld, 'valid_until' => ($rNow ?? time()) + $rWindow]);
	}

	/**
	 * Adopt what MAIN's `secrets` section sent: its `previous` while its window
	 * is still open, else nothing (a node never invents one of its own — the
	 * value it held is MAIN's to date).
	 *
	 * @return bool|null Null when there was nothing to write.
	 */
	public static function adopt(?string $rPrevious, ?int $rPreviousUntil, ?int $rNow = null): ?bool {
		$rNow ??= time();
		if ($rPrevious === null || $rPrevious === '' || $rPreviousUntil === null || $rPreviousUntil < $rNow) {
			return null;
		}
		$rKeep = ['value' => $rPrevious, 'valid_until' => $rPreviousUntil];
		return self::entry() === $rKeep ? null : self::keep($rKeep);
	}

	/**
	 * The secret this panel replaced last, while it is still accepted; null
	 * once the window has closed or there is none.
	 */
	public static function previous(?int $rNow = null): ?string {
		return self::previousEntry($rNow)['value'] ?? null;
	}

	/**
	 * previous() with the end of its window: what MAIN's replica sends as the
	 * `secrets` section's `previous` and `previous_valid_until`.
	 *
	 * @return array{value: string, valid_until: int}|null
	 */
	public static function previousEntry(?int $rNow = null): ?array {
		$rEntry = self::entry();
		return $rEntry !== null && ($rNow ?? time()) <= $rEntry['valid_until'] ? $rEntry : null;
	}

	/** Tests: another file, and forget what was read; null restores the default. */
	public static function useFile(?string $rPath): void {
		self::$rFile = $rPath;
		self::$rPrevious = null;
		self::$rReadAt = null;
	}

	/**
	 * The file's entry, re-read when it has changed since: the secret is
	 * replaced by the admin's request or by an apply, and the php-fpm worker
	 * that reads a viewer's token is neither — a value cached before the change
	 * would leave that worker refusing the links it is the point of. One stat
	 * per call, and only a token the current secret already failed to open gets
	 * this far.
	 *
	 * @return array{value: string, valid_until: int}|null
	 */
	private static function entry(): ?array {
		$rMtime = @filemtime(self::file());
		$rMtime = $rMtime === false ? null : $rMtime;
		if (self::$rPrevious === null || self::$rReadAt !== $rMtime) {
			self::$rReadAt = $rMtime;
			$rDoc = $rMtime === null ? null : json_decode((string) @file_get_contents(self::file()), true);
			self::$rPrevious = is_array($rDoc) && is_string($rDoc['value'] ?? null) && $rDoc['value'] !== '' && isset($rDoc['valid_until']) && is_numeric($rDoc['valid_until'])
				? ['value' => $rDoc['value'], 'valid_until' => (int) $rDoc['valid_until']]
				: false;
		}
		return self::$rPrevious === false ? null : self::$rPrevious;
	}

	/** @param array{value: string, valid_until: int} $rKeep */
	private static function keep(array $rKeep): bool {
		$rPath = self::file();
		$rTmp = $rPath . '.' . getmypid() . '.tmp';
		@unlink($rTmp);
		if (!@touch($rTmp)) {
			return false;
		}
		@chmod($rTmp, 0600);
		// The owner of the directory, so php-fpm reads it the moment it appears
		// (root's crons and the admin's php-fpm both write it).
		$rDir = dirname($rPath);
		if (($rOwner = @fileowner($rDir)) !== false) {
			@chown($rTmp, $rOwner);
		}
		if (($rGroup = @filegroup($rDir)) !== false) {
			@chgrp($rTmp, $rGroup);
		}
		$rData = (string) json_encode($rKeep);
		if (@file_put_contents($rTmp, $rData) !== strlen($rData) || !@rename($rTmp, $rPath)) {
			@unlink($rTmp);
			return false;
		}
		self::$rPrevious = $rKeep;
		self::$rReadAt = @filemtime($rPath) ?: null;
		return true;
	}

	/** The config directory, with its trailing slash (a test may move it). */
	private static function dir(): string {
		return ReplicaApply::configDir();
	}
}
