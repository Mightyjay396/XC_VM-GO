<?php

namespace XcVm\Core\Cluster;

/**
 * A node's own loopback pulls (the recorder reading its own `/admin/live`
 * and `/admin/timeshift`) without the fleet's `live_streaming_pass`, which
 * pulls any stream from any server: a token only this node can mint, for one
 * stream, which RelayGuard takes only from 127.0.0.1 or ::1 (ADR 0004,
 * Phase 8: nothing on a node carries the secret).
 *
 * ```text
 * token = "lb1-" <exp> "-" hex(HMAC-SHA256(key, "xcvm-loopback-v1" ‖ u64(stream_id) ‖ u64(exp)))
 * ```
 *
 * The key is the node's own (config/cluster/loopback.key, 32 random bytes,
 * created at first use, 0600) and never leaves it.
 */
final class LoopbackToken {
	public const PREFIX = 'lb1-';

	/** How long a token opens its stream: a pull checks it once, at connect. */
	public const TTL = 86400;

	private static ?string $rKeyPath = null;

	/** Tests: another key file; null restores config/cluster/loopback.key. */
	public static function useKeyPath(?string $rPath): void {
		self::$rKeyPath = $rPath;
	}

	/** A token for $rStreamID, or null when the node's key cannot be made. */
	public static function issue(int $rStreamID, ?int $rNow = null): ?string {
		$rKey = self::key(true);
		if ($rKey === null || $rStreamID <= 0) {
			return null;
		}
		$rExp = ($rNow ?? time()) + self::TTL;
		return self::PREFIX . $rExp . '-' . self::mac($rKey, $rStreamID, $rExp);
	}

	/** Does $rToken open $rStreamID now? */
	public static function verify(int $rStreamID, string $rToken, ?int $rNow = null): bool {
		if ($rStreamID <= 0 || !preg_match('/^lb1-(\d{1,12})-([0-9a-f]{64})\z/', $rToken, $rM) || (int) $rM[1] < ($rNow ?? time())) {
			return false;
		}
		$rKey = self::key(false);
		return $rKey !== null && hash_equals(self::mac($rKey, $rStreamID, (int) $rM[1]), $rM[2]);
	}

	private static function mac(string $rKey, int $rStreamID, int $rExp): string {
		return hash_hmac('sha256', 'xcvm-loopback-v1' . pack('J', $rStreamID) . pack('J', $rExp), $rKey);
	}

	/**
	 * The node's key; with $rCreate, made when there is none. Two processes
	 * making it at once agree: the one whose link lands first wins, and the
	 * other reads it.
	 */
	private static function key(bool $rCreate): ?string {
		$rPath = self::$rKeyPath ?? (defined('CONFIG_PATH') ? CONFIG_PATH . 'cluster/loopback.key' : '');
		if ($rPath === '') {
			return null;
		}
		$rKey = @file_get_contents($rPath);
		if (is_string($rKey) && strlen($rKey) === 32) {
			return $rKey;
		}
		if (!$rCreate) {
			return null;
		}
		@mkdir(dirname($rPath), 0700, true);
		$rTmp = $rPath . '.' . bin2hex(random_bytes(4));
		$rKey = random_bytes(32);
		$rOld = umask(0077);
		$rWritten = @file_put_contents($rTmp, $rKey);
		umask($rOld);
		if ($rWritten !== 32) {
			@unlink($rTmp);
			return null;
		}
		$rLinked = @link($rTmp, $rPath);
		@unlink($rTmp);
		if ($rLinked) {
			return $rKey;
		}
		$rKey = @file_get_contents($rPath);
		return is_string($rKey) && strlen($rKey) === 32 ? $rKey : null;
	}
}
