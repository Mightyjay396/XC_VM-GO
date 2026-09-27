<?php

namespace XcVm\Core\Config;

/**
 * OPENSSL_EXTRA across the cluster.
 *
 * OPENSSL_EXTRA (ConstantsInitializer::resolveOpensslExtra: config/openssl_extra,
 * else the built-in default) keys stream tokens, hmac_keys and image-cache
 * names. A MAIN and its LBs must hold the same value: a token MAIN mints for a
 * redirect is read on the LB with the LB's value. A MAIN set up by the current
 * installer holds a random value, while an LB added with server:install before
 * provisioning shipped the file runs on the default.
 *
 * Each node publishes a fingerprint of its value (never the value) in
 * servers.server_hardware, so server:diagnose can compare them. When a root
 * signal (server:sync-openssl-extra) brings a node onto MAIN's value, install()
 * keeps the value it replaces for a short window, and Encryption::readToken()
 * falls back to it, so tokens the node minted just before the switch still open.
 *
 * On a node whose replica is authoritative (the CONFIG flow), cluster:apply
 * brings it onto the value in MAIN's sealed `secrets` section instead
 * (adopt()), keeping MAIN's previous value, when MAIN sends one, until MAIN's
 * end of its window.
 *
 * @package XC_VM_Core_Config
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class OpensslExtra {
	/** The server_hardware key cron:servers publishes the fingerprint under. */
	public const HARDWARE_KEY = 'openssl_extra_fp';

	/** Seconds a replaced value still opens tokens. */
	public const PREVIOUS_WINDOW = 600;

	/** The root-signal action server:sync-openssl-extra queues and cron:root_signals applies. */
	public const SIGNAL_ACTION = 'set_openssl_extra';

	/** Test seam: the previous-value file to read instead of CONFIG_PATH's. */
	private static ?string $rPrevFile = null;

	/**
	 * The previous-value file as read once per process: null until read, false
	 * when there is none, else ['value' => string, 'valid_until' => int].
	 *
	 * @var array{value:string,valid_until:int}|false|null
	 */
	private static $rPrevious;

	/**
	 * A fingerprint of $rValue that can be compared across nodes: an HMAC keyed
	 * by the value, cut to 64 bits. It does not reveal the value.
	 */
	public static function fingerprint(string $rValue): string {
		return substr(hash_hmac('sha256', 'xc_vm openssl_extra fingerprint v1', $rValue), 0, 16);
	}

	/**
	 * The fingerprint a server row publishes, or null when it publishes none
	 * (a proxy, or a node that has not been updated).
	 */
	public static function reportedFingerprint(array $rServer): ?string {
		$rHardware = json_decode((string) ($rServer['server_hardware'] ?? ''), true);
		$rPrint = is_array($rHardware) ? ($rHardware[self::HARDWARE_KEY] ?? null) : null;

		return (is_string($rPrint) && $rPrint !== '') ? $rPrint : null;
	}

	/** $rHardware with this node's fingerprint added, as cron:servers publishes it. */
	public static function publish(array $rHardware): array {
		$rHardware[self::HARDWARE_KEY] = self::fingerprint(OPENSSL_EXTRA);

		return $rHardware;
	}

	/** The signals.custom_data that brings a node onto $rValue (applied by applySignal()). */
	public static function signal(string $rValue): string {
		return (string) json_encode(['action' => self::SIGNAL_ACTION, 'value' => $rValue]);
	}

	/**
	 * Apply a decoded signal() payload on this node.
	 *
	 * @return bool|null Null on the main, which never takes its value this way
	 *                   (it keys hmac_keys and image names); else install()'s result.
	 */
	public static function applySignal(array $rData, bool $rIsMain, string $rConfigDir, int $rNow): ?bool {
		if ($rIsMain) {
			return null;
		}

		return is_string($rData['value'] ?? null) && self::install($rData['value'], $rConfigDir, $rNow);
	}

	/**
	 * Make $rNew this node's OPENSSL_EXTRA.
	 *
	 * The value in use now (OPENSSL_EXTRA) is first written to openssl_extra.prev,
	 * accepted until $rNow + $rWindow; then openssl_extra is replaced atomically.
	 * Installing the value already in use leaves an earlier .prev alone. Both
	 * files are 0600 and take the owner of $rConfigDir, so php-fpm can read them
	 * the moment they appear.
	 *
	 * @param string $rConfigDir The config directory, with a trailing slash.
	 */
	public static function install(string $rNew, string $rConfigDir, int $rNow, int $rWindow = self::PREVIOUS_WINDOW): bool {
		$rNew = trim($rNew);
		if ($rNew === '') {
			return false;
		}
		$rCurrent = defined('OPENSSL_EXTRA') ? (string) OPENSSL_EXTRA : '';
		if ($rCurrent !== '' && $rCurrent !== $rNew) {
			if (!self::writeFile($rConfigDir . 'openssl_extra.prev', json_encode(['value' => $rCurrent, 'valid_until' => $rNow + $rWindow]))) {
				return false;
			}
		}
		self::$rPrevious = null;

		return self::writeFile($rConfigDir . 'openssl_extra', $rNew);
	}

	/**
	 * The value this node replaced last (config/openssl_extra.prev), while it
	 * is still accepted: null once the window has closed, when there is none,
	 * or when it is the value in use now.
	 */
	public static function previous(?int $rNow = null): ?string {
		return self::previousEntry($rNow)['value'] ?? null;
	}

	/**
	 * previous() with the end of its window: what MAIN's replica sends as the
	 * `secrets` section's `previous` and `previous_valid_until`.
	 *
	 * @return array{value:string,valid_until:int}|null
	 */
	public static function previousEntry(?int $rNow = null): ?array {
		if (self::$rPrevious === null) {
			self::$rPrevious = self::readPrevious();
		}
		if (self::$rPrevious === false || ($rNow ?? time()) > self::$rPrevious['valid_until']) {
			return null;
		}
		if (defined('OPENSSL_EXTRA') && self::$rPrevious['value'] === OPENSSL_EXTRA) {
			return null;
		}

		return self::$rPrevious;
	}

	/**
	 * The value this node reads now: its config/openssl_extra, else the
	 * built-in default this process resolved (ConstantsInitializer), whatever
	 * a later write changed since.
	 *
	 * @param string $rConfigDir The config directory, with a trailing slash.
	 */
	public static function inUse(string $rConfigDir): string {
		$rValue = is_file($rConfigDir . 'openssl_extra') ? trim((string) @file_get_contents($rConfigDir . 'openssl_extra')) : '';
		if ($rValue === '' && defined('OPENSSL_EXTRA')) {
			$rValue = (string) OPENSSL_EXTRA;
		}

		return $rValue;
	}

	/**
	 * Make MAIN's $rNew this node's OPENSSL_EXTRA, as the replica's `secrets`
	 * section carries it. The previous value kept (openssl_extra.prev) is
	 * MAIN's $rPrevious until $rPreviousUntil, when MAIN sends one that is
	 * still open; otherwise, when the value changes, the one it replaces here,
	 * for PREVIOUS_WINDOW, as install() keeps it. Run every minute, it writes
	 * nothing once the node holds both, so a window is never extended. Both
	 * files are 0600 and take the owner of $rConfigDir.
	 *
	 * @param string $rConfigDir The config directory, with a trailing slash.
	 * @return bool|null Null when there was nothing to write; else whether both writes succeeded.
	 */
	public static function adopt(string $rNew, ?string $rPrevious, ?int $rPreviousUntil, string $rConfigDir, int $rNow): ?bool {
		$rNew = trim($rNew);
		if ($rNew === '') {
			return false;
		}
		$rInUse = self::inUse($rConfigDir);
		$rKeep = null;
		if ($rPrevious !== null && $rPrevious !== '' && $rPrevious !== $rNew && $rPreviousUntil !== null && $rPreviousUntil >= $rNow) {
			$rKeep = ['value' => $rPrevious, 'valid_until' => $rPreviousUntil];
		} elseif ($rInUse !== '' && $rInUse !== $rNew) {
			$rKeep = ['value' => $rInUse, 'valid_until' => $rNow + self::PREVIOUS_WINDOW];
		}
		$rWrote = null;
		if ($rKeep !== null && self::readPreviousFile($rConfigDir . 'openssl_extra.prev') !== $rKeep) {
			if (!self::writeFile($rConfigDir . 'openssl_extra.prev', (string) json_encode($rKeep))) {
				return false;
			}
			$rWrote = true;
		}
		if ($rInUse !== $rNew) {
			if (!self::writeFile($rConfigDir . 'openssl_extra', $rNew)) {
				return false;
			}
			$rWrote = true;
		}
		self::$rPrevious = null;

		return $rWrote;
	}

	/** Test seam: read the previous value from $rPath (null restores CONFIG_PATH's). */
	public static function usePrevFile(?string $rPath): void {
		self::$rPrevFile = $rPath;
		self::$rPrevious = null;
	}

	/** @return array{value:string,valid_until:int}|false */
	private static function readPrevious() {
		$rFile = self::$rPrevFile;
		if ($rFile === null) {
			if (defined('CONFIG_PATH')) {
				$rFile = CONFIG_PATH . 'openssl_extra.prev';
			} elseif (defined('MAIN_HOME')) {
				$rFile = MAIN_HOME . 'config/openssl_extra.prev';
			} else {
				return false;
			}
		}

		return self::readPreviousFile($rFile);
	}

	/** @return array{value:string,valid_until:int}|false */
	private static function readPreviousFile(string $rFile) {
		if (!is_file($rFile)) {
			return false;
		}
		$rData = json_decode((string) @file_get_contents($rFile), true);
		if (!is_array($rData) || !isset($rData['value'], $rData['valid_until']) || !is_string($rData['value']) || $rData['value'] === '') {
			return false;
		}

		return ['value' => $rData['value'], 'valid_until' => intval($rData['valid_until'])];
	}

	/** Write $rData to $rPath through a 0600 temporary file owned like its directory, then rename it into place. */
	private static function writeFile(string $rPath, string $rData): bool {
		$rDir = dirname($rPath);
		$rTmp = $rPath . '.' . getmypid() . '.tmp';
		@unlink($rTmp);
		if (!@touch($rTmp)) {
			return false;
		}
		@chmod($rTmp, 0600);
		$rOwner = @fileowner($rDir);
		$rGroup = @filegroup($rDir);
		if ($rOwner !== false) {
			@chown($rTmp, $rOwner);
		}
		if ($rGroup !== false) {
			@chgrp($rTmp, $rGroup);
		}
		if (@file_put_contents($rTmp, $rData) !== strlen($rData) || !@rename($rTmp, $rPath)) {
			@unlink($rTmp);
			return false;
		}

		return true;
	}
}
