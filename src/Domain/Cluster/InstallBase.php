<?php

namespace XcVm\Domain\Cluster;

/**
 * The install root a class writes under (MAIN_HOME, or a test's), and the
 * user its services run as there: the cluster's PHP-FPM pools
 * (ClusterPool) and nginx (ClusterNginxConfig) both run as xc_vm, and
 * only a process running as that user may rewrite and reload them. Each
 * class using this has its own: a static property a trait declares belongs
 * to each using class.
 */
trait InstallBase {
	private static ?string $rBase = null;

	private static string $rUser = 'xc_vm';

	/** Tests: another install root (null: MAIN_HOME), and the user the services run as. */
	public static function useBase(?string $rBase, string $rUser = 'xc_vm'): void {
		self::$rBase = $rBase;
		self::$rUser = $rUser;
	}

	/** Does this process run (effectively) as the services' user? */
	private static function runsAsUser(): bool {
		$rUser = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid()) : false;
		return is_array($rUser) && $rUser['name'] === self::$rUser;
	}

	private static function base(): ?string {
		return self::$rBase ?? (defined('MAIN_HOME') ? (string) MAIN_HOME : null);
	}
}
