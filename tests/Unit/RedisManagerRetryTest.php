<?php

use PHPUnit\Framework\TestCase;
use XcVm\Infrastructure\Redis\RedisManager;

/**
 * With Redis down, every RedisManager::instance() call connected again, and
 * each connect could block for default_socket_timeout (60 s): a request that
 * asks Redis many times hung for minutes. A failed connect is now tried again
 * only after RETRY_AFTER (reconnect() tries at once), and a connect is bounded
 * by CONNECT_TIMEOUT.
 */
final class RedisManagerRetryTest extends TestCase {
	protected function tearDown(): void {
		RedisManager::useConnector(null);
		(new \ReflectionProperty(RedisManager::class, 'instance'))->setValue(null, null);
	}

	public function testAFailedConnectIsNotTriedAgainAtEveryCall(): void {
		(new \ReflectionProperty(RedisManager::class, 'instance'))->setValue(null, null);
		$rTried = 0;
		$rTimeouts = [];
		RedisManager::useConnector(static function () use (&$rTried, &$rTimeouts) {
			$rTried++;
			$rTimeouts[] = ini_get('default_socket_timeout');
			return false;
		});
		$rBefore = ini_get('default_socket_timeout');

		$this->assertNull(RedisManager::instance());
		$this->assertNull(RedisManager::instance());
		$this->assertNull(RedisManager::instance());
		$this->assertSame(1, $rTried, 'one try per RETRY_AFTER');
		$this->assertSame([(string) RedisManager::CONNECT_TIMEOUT], $rTimeouts, 'the connect is bounded');
		$this->assertSame($rBefore, ini_get('default_socket_timeout'), 'and the setting put back');

		$this->assertNull(RedisManager::reconnect());
		$this->assertSame(2, $rTried, 'reconnect() tries at once');
	}
}
