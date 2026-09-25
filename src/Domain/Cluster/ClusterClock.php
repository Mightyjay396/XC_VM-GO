<?php

namespace XcVm\Domain\Cluster;

/**
 * MAIN time for the cluster API, in milliseconds. MAIN's clock is
 * authoritative for the fleet; tests pin it.
 */
final class ClusterClock {
	private static ?int $rFixedMs = null;

	public static function nowMs(): int {
		return self::$rFixedMs ?? (int) floor(microtime(true) * 1000);
	}

	public static function now(): int {
		return intdiv(self::nowMs(), 1000);
	}

	/** Pin the clock (tests). Null restores real time. */
	public static function fix(?int $rMs): void {
		self::$rFixedMs = $rMs;
	}
}
