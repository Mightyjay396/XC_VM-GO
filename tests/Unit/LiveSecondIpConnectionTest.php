<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Stream\ConnectionTracker;

/**
 * disallow_2nd_ip_con in Redis mode — live.php accepts only the IP of the
 * line's oldest connection. The LINE# set holds connection keys, not the
 * connections, so the rows are read behind them before the oldest is picked;
 * sorting the keys by date_start was a ValueError on PHP 8, and an HMAC
 * token's null line id a TypeError.
 */
final class LiveSecondIpConnectionTest extends TestCase {

	public function testOldestConnectionIpPicksTheEarliestStartWhateverTheOrder(): void {
		$rRows = [
			['user_ip' => '3.3.3.3', 'date_start' => 300],
			['user_ip' => '1.1.1.1', 'date_start' => 100],
			['user_ip' => '2.2.2.2', 'date_start' => 200],
		];

		$this->assertSame('1.1.1.1', ConnectionTracker::oldestConnectionIP($rRows));
		$this->assertSame('1.1.1.1', ConnectionTracker::oldestConnectionIP(array_reverse($rRows)));
	}

	public function testOldestConnectionIpSkipsUnreadableRowsAndRowsWithoutAnIp(): void {
		$rRows = [
			false,
			['date_start' => 50],
			['user_ip' => '', 'date_start' => 60],
			['user_ip' => '2.2.2.2', 'date_start' => 200],
			['user_ip' => '1.1.1.1', 'date_start' => 100],
		];

		$this->assertSame('1.1.1.1', ConnectionTracker::oldestConnectionIP($rRows));
	}

	public function testOldestConnectionIpSortsUndatedRowsLastAndKeepsTheFirstOnATie(): void {
		$rRows = [
			['user_ip' => '9.9.9.9'],
			['user_ip' => '1.1.1.1', 'date_start' => 100],
			['user_ip' => '2.2.2.2', 'date_start' => 100],
		];

		$this->assertSame('1.1.1.1', ConnectionTracker::oldestConnectionIP($rRows));
		$this->assertSame('9.9.9.9', ConnectionTracker::oldestConnectionIP([['user_ip' => '9.9.9.9']]));
	}

	public function testNoConnectionsMeansNoAcceptedIp(): void {
		$this->assertNull(ConnectionTracker::oldestConnectionIP([]));
	}

	public function testConnectionKeysAreNotConnectionRows(): void {
		// The LINE# members, uuid strings, carry no IP.
		$this->assertNull(ConnectionTracker::oldestConnectionIP(['uuid-a', 'uuid-b']));
	}

	public function testOldestIpIsAcceptedForALineWithActiveConnections(): void {
		if (!extension_loaded('redis')) {
			$this->markTestSkipped('phpredis is not loaded; the mGet branch needs a \Redis double.');
		}

		// Sorting these LINE# keys by date_start was "ValueError: Array sizes are
		// inconsistent" as soon as the line had one active connection.
		$rRedis = $this->createMock(\Redis::class);
		$rRedis->expects($this->once())->method('zRangeByScore')->with('LINE#7', '-inf', '+inf')->willReturn(['k-new', 'k-old']);
		$rRedis->expects($this->once())->method('mGet')->with(['k-new', 'k-old'])->willReturn([
			igbinary_serialize(['user_ip' => '2.2.2.2', 'date_start' => 200]),
			igbinary_serialize(['user_ip' => '1.1.1.1', 'date_start' => 100]),
		]);

		$this->assertSame('1.1.1.1', ConnectionTracker::acceptedLineIP($rRedis, '7'));
	}

	public function testHmacIdentityHasNoAcceptedIpAndNeverQueriesRedis(): void {
		if (!extension_loaded('redis')) {
			$this->markTestSkipped('phpredis is not loaded; the zRangeByScore branch needs a \Redis double.');
		}

		// An HMAC token's line id is null; getLineConnections(int) was a TypeError.
		$rRedis = $this->createMock(\Redis::class);
		$rRedis->expects($this->never())->method('zRangeByScore');
		$rRedis->expects($this->never())->method('mGet');

		$this->assertNull(ConnectionTracker::acceptedLineIP($rRedis, null));
	}

	public function testLineConnectionRowsReadsTheRowsBehindTheLineKeys(): void {
		if (!extension_loaded('redis')) {
			$this->markTestSkipped('phpredis is not loaded; the mGet branch needs a \Redis double.');
		}

		$rRedis = $this->createMock(\Redis::class);
		$rRedis->expects($this->once())->method('zRangeByScore')->with('LINE#7', '-inf', '+inf')->willReturn(['a', 'b']);
		$rRedis->expects($this->once())->method('mGet')->with(['a', 'b'])->willReturn([igbinary_serialize(['user_ip' => '1.1.1.1', 'date_start' => 5]), false]);

		$this->assertSame([['user_ip' => '1.1.1.1', 'date_start' => 5]], ConnectionTracker::getLineConnectionRows($rRedis, 7, true));
	}

	public function testDroppedConnectionReadsAsNoRows(): void {
		if (!extension_loaded('redis')) {
			$this->markTestSkipped('phpredis is not loaded; the mGet branch needs a \Redis double.');
		}

		$rRedis = $this->createMock(\Redis::class);
		$rRedis->method('zRangeByScore')->willReturn(['a']);
		$rRedis->method('mGet')->willReturn(false);

		$this->assertSame([], ConnectionTracker::getLineConnectionRows($rRedis, 7, true));
	}

	public function testUnreachableRedisReadsAsNoRows(): void {
		$this->assertSame([], ConnectionTracker::getLineConnectionRows(null, 7, true));
	}

	public function testFailedKeyLookupReadsAsNoRows(): void {
		if (!extension_loaded('redis')) {
			$this->markTestSkipped('phpredis is not loaded; the zRangeByScore branch needs a \Redis double.');
		}

		$rRedis = $this->createMock(\Redis::class);
		$rRedis->method('zRangeByScore')->willReturn(false);
		$rRedis->expects($this->never())->method('mGet');

		$this->assertSame([], ConnectionTracker::getLineConnectionRows($rRedis, 7, true));
	}

	public function testLiveEndpointTakesTheAcceptedIpFromTheSeam(): void {
		$rPath = MAIN_HOME . 'Public/stream/live.php';
		$this->assertFileExists($rPath);
		$rSource = (string) file_get_contents($rPath);

		$this->assertSame(1, substr_count($rSource, 'ConnectionTracker::acceptedLineIP('), 'live.php must take the Redis-mode accepted IP from acceptedLineIP()');
	}
}
