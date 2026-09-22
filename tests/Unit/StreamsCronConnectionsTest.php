<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\CronJobs\StreamsCronJob;

/**
 * Coverage for StreamsCronJob connection parsing — the fix for the TypeError
 * raised when getConnections() returns the Redis [keys, data] pair instead of
 * the MySQL user-id map. Both shapes (and a single connection row) must yield
 * the stream's UUIDs, filtered to that stream.
 */
final class StreamsCronConnectionsTest extends TestCase {

	/**
	 * Call a private helper. Whether it is static is an implementation detail
	 * (Rector's LocallyCalledStaticMethodToNonStatic flips it), so bind an
	 * instance only when the method actually needs one.
	 */
	private function call(string $rMethod, ...$rArgs) {
		$rM = new ReflectionMethod(StreamsCronJob::class, $rMethod);
		$rM->setAccessible(true);
		return $rM->invoke($rM->isStatic() ? null : new StreamsCronJob(), ...$rArgs);
	}

	private function uuids($rConnections, $rStreamID): array {
		return $this->call('connectionUuidsForStream', $rConnections, $rStreamID);
	}

	private function collect($rNode): array {
		return $this->call('collectUuidRows', $rNode);
	}

	public function testRedisPairShapeFiltersByStream(): void {
		$rConnections = [
			['redis-key-1', 'redis-key-2'],
			[
				['uuid' => 'a', 'stream_id' => 5],
				['uuid' => 'b', 'stream_id' => 9],
			],
		];
		$this->assertSame(['a'], $this->uuids($rConnections, 5));
	}

	public function testMysqlGroupedShapeKeepsRowsWithoutStreamId(): void {
		$rConnections = [
			10 => [['uuid' => 'c', 'stream_id' => 5]],
			11 => [['uuid' => 'd']], // no stream_id → kept
		];
		$this->assertSame(['c', 'd'], $this->uuids($rConnections, 5));
	}

	public function testSingleConnectionRow(): void {
		$this->assertSame(['e'], $this->uuids(['uuid' => 'e', 'stream_id' => 5], 5));
	}

	public function testNonArrayAndEmptyYieldNothing(): void {
		$this->assertSame([], $this->uuids([], 5));
		$this->assertSame([], $this->uuids(null, 5));
		$this->assertSame([], $this->collect('a-redis-key-string'));
	}
}
