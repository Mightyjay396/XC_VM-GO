<?php

namespace XcVm\Core\Cluster;

use XcVm\Core\Events\EventDispatcher;
use XcVm\Core\Events\Server\ServerSavedEvent;
use XcVm\Core\Events\Settings\CrontabChangedEvent;
use XcVm\Core\Events\Settings\SettingsChangedEvent;

/**
 * MAIN's cache of the replica's R1 sections (plan, section 9, "Change
 * detection"): a section's ETag is the SHA-256 of its canonical content, and
 * MAIN reuses the section and its ETag for 10 s instead of reading the
 * database for every node that asks. A settings, server or crontab save
 * (SettingsChangedEvent, ServerSavedEvent, CrontabChangedEvent) and a revoked
 * or re-enrolled node drop it at once (bump()), so the next `config` call
 * sees the change; anything that changes a section without an event is seen
 * within the 10 s.
 *
 * One file per section (and per node or mode where the section depends on
 * it) in `TMP_PATH/cluster_replica/`: `{at, gen, etag, data}`, `at` in ms on
 * MAIN's clock. `gen` is the cache's generation (`.gen`) when the section
 * was read from the database: a bump replaces it with a random token, so a
 * section read before the bump and written after it is never served, even
 * when two bumps race. In Core, not
 * Domain\Cluster: the event listener is registered at boot on every node
 * (ContainerPopulateStage), and on an LB there is simply nothing to drop.
 *
 * The `secrets` section is never kept: these files are plain JSON, and a
 * secret must not rest unsealed on MAIN's disk. It is read for each request.
 */
final class ReplicaEtagCache {
	/** How long a section is reused (ms). */
	public const TTL_MS = 10000;

	/** @var string|false|null null: TMP_PATH's; false: no cache */
	private static string|false|null $rDir = null;

	/** Tests: another directory, false for no cache, null for TMP_PATH's. */
	public static function useDir(string|false|null $rDir): void {
		self::$rDir = $rDir;
	}

	public static function dir(): ?string {
		if (self::$rDir === false) {
			return null;
		}
		return self::$rDir ?? (defined('TMP_PATH') ? TMP_PATH . 'cluster_replica/' : null);
	}

	/** The cache's generation: read it before reading a section from the database. */
	public static function generation(): string {
		$rDir = self::dir();
		return $rDir === null ? '' : (string) @file_get_contents($rDir . '.gen');
	}

	/**
	 * The cached section, while it is younger than TTL_MS on MAIN's clock and
	 * no bump came since it was read.
	 *
	 * @return array{etag: string, data: array<mixed>}|null
	 */
	public static function get(string $rKey, int $rNowMs): ?array {
		$rDir = self::dir();
		if ($rDir === null || !self::validKey($rKey)) {
			return null;
		}
		$rHit = json_decode((string) @file_get_contents($rDir . $rKey . '.json'), true);
		if (!is_array($rHit) || !is_int($rHit['at'] ?? null) || !is_string($rHit['gen'] ?? null) || !is_string($rHit['etag'] ?? null) || !is_array($rHit['data'] ?? null)) {
			return null;
		}
		if ($rNowMs < $rHit['at'] || $rNowMs - $rHit['at'] >= self::TTL_MS || $rHit['gen'] !== self::generation()) {
			return null;
		}
		return ['etag' => $rHit['etag'], 'data' => $rHit['data']];
	}

	/**
	 * Keep a section read from the database at generation $rGen.
	 *
	 * @param array<mixed> $rData
	 */
	public static function put(string $rKey, int $rNowMs, string $rGen, string $rEtag, array $rData): void {
		$rDir = self::dir();
		if ($rDir === null || !self::validKey($rKey)) {
			return;
		}
		if (!is_dir($rDir)) {
			@mkdir($rDir, 0750, true);
		}
		$rTmp = $rDir . '.' . $rKey . '.' . getmypid() . '.tmp';
		$rJson = json_encode(['at' => $rNowMs, 'gen' => $rGen, 'etag' => $rEtag, 'data' => $rData], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
		if ($rJson !== false && @file_put_contents($rTmp, $rJson) !== false && !@rename($rTmp, $rDir . $rKey . '.json')) {
			@unlink($rTmp);
		}
	}

	/**
	 * Drop every cached section: a new generation, so a section read before
	 * now is not served even if it is written after, and delete them. The
	 * generation is random, not a counter: two bumps at once never write the
	 * same one, so what a request read between them never matches either.
	 */
	public static function bump(?object $rEvent = null): void {
		$rDir = self::dir();
		if ($rDir === null) {
			return;
		}
		if (!is_dir($rDir)) {
			@mkdir($rDir, 0750, true);
		}
		$rTmp = $rDir . '.gen.' . getmypid() . '.tmp';
		if (@file_put_contents($rTmp, bin2hex(random_bytes(8))) !== false && !@rename($rTmp, $rDir . '.gen')) {
			@unlink($rTmp);
		}
		foreach (glob($rDir . '*.json') ?: [] as $rFile) {
			@unlink($rFile);
		}
	}

	/** Drop the cache on every settings, server and crontab save. */
	public static function subscribe(): void {
		foreach ([SettingsChangedEvent::class, ServerSavedEvent::class, CrontabChangedEvent::class] as $rEvent) {
			EventDispatcher::listen($rEvent, [self::class, 'bump']);
		}
	}

	/** A section this cache may keep: never `secrets`. */
	private static function validKey(string $rKey): bool {
		return $rKey !== ReplicaSections::SECRETS && preg_match('/^[a-z]+(\.[a-z0-9]+)?$/', $rKey);
	}
}
