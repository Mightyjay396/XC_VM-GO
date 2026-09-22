<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Stream\ConnectionTracker;

/**
 * ConnectionTracker::readConnections — the guard every mGet batch read goes
 * through. A dropped connection or an unreachable Redis must look like "no
 * connections", not like a warning or a fatal in the caller's foreach.
 */
final class ConnectionTrackerReadConnectionsTest extends TestCase {

	public function testUnreachableRedisReadsAsNoConnections(): void {
		$this->assertSame([], ConnectionTracker::readConnections(null, ['LINE#1', 'LINE#2']));
	}

	public function testEmptyBatchNeverReachesRedis(): void {
		// A reseller with no lines produces no keys; phpredis is not asked at all,
		// so a null connection is fine here too.
		$this->assertSame([], ConnectionTracker::readConnections(null, []));
	}

	public function testDroppedConnectionReadsAsNoConnections(): void {
		if (!extension_loaded('redis')) {
			$this->markTestSkipped('phpredis is not loaded; the mGet branch needs a \Redis double.');
		}

		$rRedis = $this->createMock(\Redis::class);
		$rRedis->method('mGet')->willReturn(false);

		$this->assertSame([], ConnectionTracker::readConnections($rRedis, ['LINE#1']));
	}

	public function testPayloadsArePassedThroughUntouched(): void {
		if (!extension_loaded('redis')) {
			$this->markTestSkipped('phpredis is not loaded; the mGet branch needs a \Redis double.');
		}

		$rRedis = $this->createMock(\Redis::class);
		$rRedis->method('mGet')->willReturn(['payload-a', 'payload-b']);

		$this->assertSame(['payload-a', 'payload-b'], ConnectionTracker::readConnections($rRedis, ['LINE#1', 'LINE#2']));
	}
}
