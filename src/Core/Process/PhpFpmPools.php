<?php

namespace XcVm\Core\Process;

/**
 * The php-fpm pools a server runs, as files: one config per pool from the
 * panel's template (`bin/php/etc/template`, `#ID#` and `#PATH#` filled in),
 * `bin/daemons.sh` starting each, and nginx's `upstream php` over their
 * sockets (`bin/nginx/conf/balance.conf`). The SSH install writes them to a
 * new LB, and cron:root_signals (`set_services`) rewrites them on a server
 * of either kind, so this lives in Core, which ships to LBs.
 *
 * Only the text: the caller removes the old pool configs, writes the files
 * where it writes them (locally, or through a temporary file sent over SSH)
 * and sets their owner.
 */
final class PhpFpmPools {
	/**
	 * The files for pools 1..$rCount under $rBase (MAIN_HOME), by absolute
	 * path, in the order the callers write them: the pool configs, then
	 * `daemons.sh`, then `balance.conf`. The pool ids are `range(1, $rCount)`,
	 * as they always were, so a count below 1 counts down to it.
	 *
	 * @return array<string, string> path => contents
	 */
	public static function files(int $rCount, string $rTemplate, string $rBase): array {
		$rFiles = [];
		$rScript = '#! /bin/bash' . "\n";
		$rBalance = 'upstream php {' . "\n" . '    least_conn;' . "\n";
		foreach (range(1, $rCount) as $i) {
			$rScript .= 'start-stop-daemon --start --quiet --pidfile ' . $rBase . 'bin/php/sockets/' . $i . '.pid --exec ' . $rBase . 'bin/php/sbin/php-fpm -- --daemonize --fpm-config ' . $rBase . 'bin/php/etc/' . $i . '.conf' . "\n";
			$rBalance .= '    server unix:' . $rBase . 'bin/php/sockets/' . $i . '.sock;' . "\n";
			$rFiles[$rBase . 'bin/php/etc/' . $i . '.conf'] = self::pool($i, $rTemplate, $rBase);
		}
		$rFiles[$rBase . 'bin/daemons.sh'] = $rScript;
		$rFiles[$rBase . 'bin/nginx/conf/balance.conf'] = $rBalance . '}';
		return $rFiles;
	}

	/** One pool's config: the template with its id and the install base. */
	public static function pool(int $rID, string $rTemplate, string $rBase): string {
		return str_replace('#PATH#', $rBase, str_replace('#ID#', (string) $rID, $rTemplate));
	}
}
