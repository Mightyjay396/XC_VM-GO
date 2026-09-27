<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\ReplicaApply;
use XcVm\Core\Cluster\ReplicaStreams;

/**
 * The node's reader of the R2 `streams` section its agent stores
 * (`replica/streams.json` and `replica/streams/<id>.json`): the whole
 * section or nothing, so a caller that prunes by the list (cron:cleanup)
 * never takes a partial one for the node's streams.
 */
final class ReplicaStreamsTest extends TestCase {
	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-replica-streams-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir . 'streams', 0700, true);
		ReplicaApply::useDir($this->rDir);
	}

	protected function tearDown(): void {
		ReplicaApply::useDir(null);
		foreach (glob($this->rDir . 'streams/{,.}*', GLOB_BRACE) ?: [] as $rFile) {
			if (is_file($rFile)) {
				unlink($rFile);
			}
		}
		@rmdir($this->rDir . 'streams');
		@unlink($this->rDir . 'streams.json');
		@rmdir($this->rDir);
	}

	/** A record's data, as MAIN signs it (only the fields the readers look at). */
	private function store(int $rID, int $rType, ?array $rServer, int $rArchiveServer = 0, int $rDays = 0): void {
		$rData = ['children' => [], 'options' => [], 'profile' => null, 'recordings' => [], 'server' => $rServer, 'stream' => ['id' => $rID, 'type' => $rType, 'tv_archive_duration' => $rDays, 'tv_archive_server_id' => $rArchiveServer], 'tickets' => null, 'type' => null];
		file_put_contents($this->rDir . 'streams/' . $rID . '.json', json_encode(['etag' => str_repeat('a', 64), 'ver' => 3, 'data' => $rData]));
		file_put_contents($this->rDir . 'streams/' . $rID . '.rep', 'sealed');
	}

	private function since(int $rSince): void {
		file_put_contents($this->rDir . 'streams.json', json_encode(['since' => $rSince]));
	}

	public function testTheWholeSectionOrNothing(): void {
		$rServer = ['server_id' => 5, 'stream_id' => 0, 'parent_id' => null, 'on_demand' => 0, 'server_stream_id' => 1];
		$this->store(10, 1, $rServer);
		$this->store(11, 3, $rServer);
		$this->store(12, 1, null, 5, 7);
		$this->store(13, 2, $rServer);
		file_put_contents($this->rDir . 'streams/.14.json.tmp', '{');
		$this->assertNull(ReplicaStreams::records(), 'no cursor: the agent has not taken every stream yet');
		$this->since(0);
		$this->assertNull(ReplicaStreams::assigned([1]));
		$this->since(42);

		$this->assertSame([10, 11, 12, 13], array_keys(ReplicaStreams::records()));
		$this->assertSame([10, 11], array_keys(ReplicaStreams::assigned([1, 3, 4])), 'cron:cleanup\'s live, created and radio streams run here');
		$this->assertSame([10, 11, 13], array_keys(ReplicaStreams::assigned()));
		$this->assertSame([12 => 7], ReplicaStreams::archives(5));
		$this->assertSame([], ReplicaStreams::archives(6));

		// One file that is not its stream's record: nothing, never a partial list.
		file_put_contents($this->rDir . 'streams/13.json', json_encode(['etag' => 'x', 'data' => ['stream' => ['id' => 99]]]));
		$this->assertNull(ReplicaStreams::records());
		$this->assertNull(ReplicaStreams::archives(5));
		file_put_contents($this->rDir . 'streams/13.json', '{');
		$this->assertNull(ReplicaStreams::assigned());
		unlink($this->rDir . 'streams/13.json');
		file_put_contents($this->rDir . 'streams/notes.json', '{}');
		$this->assertNull(ReplicaStreams::records(), 'a file that names no stream');
	}
}
