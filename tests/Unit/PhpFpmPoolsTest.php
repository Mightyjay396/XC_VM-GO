<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Process\PhpFpmPools;

/**
 * The php-fpm pools' files (PhpFpmPools::files), which the SSH install and
 * cron:root_signals' set_services each built inline before: the same bytes,
 * in the same order.
 */
final class PhpFpmPoolsTest extends TestCase {
	private const TEMPLATE = "[#ID#]\nlisten = #PATH#bin/php/sockets/#ID#.sock\npid = #PATH#bin/php/sockets/#ID#.pid\n";

	public function testTheFilesMatchWhatTheCallersBuiltInline(): void {
		foreach ([1, 4, 7] as $rCount) {
			$this->assertSame(self::inline($rCount, self::TEMPLATE, '/home/xc_vm/'), PhpFpmPools::files($rCount, self::TEMPLATE, '/home/xc_vm/'), (string) $rCount);
		}
	}

	public function testTheOrderIsThePoolsThenDaemonsThenBalance(): void {
		$this->assertSame([
			'/b/bin/php/etc/1.conf', '/b/bin/php/etc/2.conf', '/b/bin/daemons.sh', '/b/bin/nginx/conf/balance.conf',
		], array_keys(PhpFpmPools::files(2, '', '/b/')));
		$rFiles = PhpFpmPools::files(2, self::TEMPLATE, '/b/');
		$this->assertSame("[2]\nlisten = /b/bin/php/sockets/2.sock\npid = /b/bin/php/sockets/2.pid\n", $rFiles['/b/bin/php/etc/2.conf']);
		$this->assertSame("upstream php {\n    least_conn;\n    server unix:/b/bin/php/sockets/1.sock;\n    server unix:/b/bin/php/sockets/2.sock;\n}", $rFiles['/b/bin/nginx/conf/balance.conf']);
		$this->assertStringStartsWith("#! /bin/bash\nstart-stop-daemon --start --quiet --pidfile /b/bin/php/sockets/1.pid --exec /b/bin/php/sbin/php-fpm -- --daemonize --fpm-config /b/bin/php/etc/1.conf\n", $rFiles['/b/bin/daemons.sh']);
	}

	public function testACountBelowOneCountsDownAsRangeDid(): void {
		$this->assertSame(self::inline(0, self::TEMPLATE, '/b/'), PhpFpmPools::files(0, self::TEMPLATE, '/b/'));
		$this->assertArrayHasKey('/b/bin/php/etc/0.conf', PhpFpmPools::files(0, self::TEMPLATE, '/b/'));
	}

	/**
	 * The block both callers held, verbatim but for the writes.
	 *
	 * @return array<string, string>
	 */
	private static function inline(int $rServices, string $rTemplate, string $rBase): array {
		$rFiles = [];
		$rNewScript = '#! /bin/bash' . "\n";
		$rNewBalance = 'upstream php {' . "\n" . '    least_conn;' . "\n";
		foreach (range(1, $rServices) as $i) {
			$rNewScript .= 'start-stop-daemon --start --quiet --pidfile ' . $rBase . 'bin/php/sockets/' . $i . '.pid --exec ' . $rBase . 'bin/php/sbin/php-fpm -- --daemonize --fpm-config ' . $rBase . 'bin/php/etc/' . $i . '.conf' . "\n";
			$rNewBalance .= '    server unix:' . $rBase . 'bin/php/sockets/' . $i . '.sock;' . "\n";
			$rFiles[$rBase . 'bin/php/etc/' . $i . '.conf'] = str_replace('#PATH#', $rBase, str_replace('#ID#', (string) $i, $rTemplate));
		}
		$rFiles[$rBase . 'bin/daemons.sh'] = $rNewScript;
		$rFiles[$rBase . 'bin/nginx/conf/balance.conf'] = $rNewBalance . '}';
		return $rFiles;
	}
}
