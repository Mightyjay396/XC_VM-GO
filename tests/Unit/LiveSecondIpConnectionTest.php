<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Stream\ConnectionTracker;

/**
 * disallow_2nd_ip_con in Redis mode — live.php accepts only the IP of the
 * line's oldest connection. The LINE# set holds connection keys, not the
 * connections, so the rows are read behind them before the oldest is picked;
 * sorting the keys by date_start was a ValueError on PHP 8.
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

	public function testConnectionKeysAloneYieldNoIpInsteadOfAValueError(): void {
		// The shape live.php used to sort: the LINE# members, uuid strings.
		$this->assertNull(ConnectionTracker::oldestConnectionIP(['uuid-a', 'uuid-b']));
	}

	public function testLineConnectionRowsReadsTheRowsBehindTheLineKeys(): void {
		if (!extension_loaded('redis') || !extension_loaded('igbinary')) {
			$this->markTestSkipped('phpredis and igbinary are needed for the \Redis double and the payloads.');
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

	public function testLiveEndpointNoLongerSortsTheLineKeys(): void {
		$rPath = MAIN_HOME . 'Public/stream/live.php';
		if (!is_file($rPath)) {
			$this->markTestSkipped('live.php is not part of this layout');
		}
		$rSource = (string) file_get_contents($rPath);

		// getLineConnections() answers the LINE# members (keys); sorting those by
		// date_start threw "Array sizes are inconsistent" for any live line.
		$this->assertSame(0, substr_count($rSource, 'array_multisort'), 'live.php must not sort connection keys');
		$this->assertSame(0, substr_count($rSource, 'ConnectionTracker::getLineConnections('), 'live.php must read the connection rows, not the keys');
	}
}
