<?php

namespace XcVm\Core\Cluster;

/**
 * A class's working directory that may be none (the class then keeps
 * nothing), which tests move elsewhere or turn off (useDir()): the class's
 * defaultDir() unless a test set another. Each class using this has its own:
 * a static property a trait declares belongs to each using class, so one
 * class's test directory never moves another's.
 */
trait OptionalDirSeam {
	/** @var string|false|null null: defaultDir(); false: none */
	private static string|false|null $rDir = null;

	/** Tests: another directory, or false for none; null restores the default. */
	public static function useDir(string|false|null $rDir): void {
		self::$rDir = $rDir;
	}

	/** The directory; null when there is none (turned off, or no default here). */
	public static function dir(): ?string {
		if (self::$rDir === false) {
			return null;
		}
		return self::$rDir ?? self::defaultDir();
	}

	/** The directory while no test set another; null when there is none. */
	abstract private static function defaultDir(): ?string;
}
