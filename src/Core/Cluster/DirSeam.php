<?php

namespace XcVm\Core\Cluster;

/**
 * A class's working directory, which tests move elsewhere (useDir()): the
 * class's defaultDir() unless a test set another. Each class using this has
 * its own: a static property a trait declares belongs to each using class,
 * so one class's test directory never moves another's.
 */
trait DirSeam {
	private static ?string $rDir = null;

	/** Tests: another directory; null restores the default. */
	public static function useDir(?string $rDir): void {
		self::$rDir = $rDir;
	}

	public static function dir(): string {
		return self::$rDir ?? self::defaultDir();
	}

	/** The directory while no test set another. */
	abstract private static function defaultDir(): string;
}
