<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Stream\StreamRowMerge;

/**
 * MAIN merges a node's stream runtime state into that node's row only, and
 * only into runtime-state columns.
 */
final class StreamRowMergeTest extends TestCase {

	private function recorder(): object {
		return new class {
			/** @var list<array{string, list<mixed>}> */
			public array $rQueries = [];
			public function query(string $rSql, mixed ...$rParams): bool {
				$this->rQueries[] = [$rSql, $rParams];
				return true;
			}
		};
	}

	public function testMergeIsKeyedByTheReportingNode(): void {
		$rDb = $this->recorder();
		StreamRowMerge::mergeNode(7, 42, ['pid' => 100, 'stream_status' => 0], $rDb);
		$this->assertSame([['UPDATE `streams_servers` SET `pid` = ?, `stream_status` = ? WHERE `stream_id` = ? AND `server_id` = ?', [100, 0, 42, 7]]], $rDb->rQueries);
	}

	public function testMergeRowAndNothingToMerge(): void {
		$rDb = $this->recorder();
		StreamRowMerge::mergeRow(9, ['bitrate' => null], $rDb);
		StreamRowMerge::mergeRow(9, [], $rDb);
		$this->assertSame([['UPDATE `streams_servers` SET `bitrate` = ? WHERE `server_stream_id` = ?', [null, 9]]], $rDb->rQueries);
	}

	public function testDesiredStateIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		StreamRowMerge::mergeNode(1, 1, ['on_demand' => 1], $this->recorder());
	}

	public function testEventFieldsDropForeignKeysAndRedactTheSource(): void {
		$this->assertSame(
			['pid' => 5, 'current_source' => 'http://src/live/***/***/1.ts'],
			StreamRowMerge::eventFields(['pid' => 5, 'server_id' => 3, 'parent_id' => 2, 'current_source' => 'http://src/live/u/p/1.ts'])
		);
	}
}
