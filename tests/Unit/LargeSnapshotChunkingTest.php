<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ClusterApi;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\ConnectionDigest;
use XcVm\Domain\Cluster\ConnectionSnapshot;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * A 20 000-connection `conn_snapshot` (plan, Phase 6 acceptance: "a
 * 20k-connection snapshot applies atomically"): twenty chunks of 1000, staged
 * aside, and MAIN's store for the node changes only when the last one
 * arrives, all at once. A bad chunk, a chunk out of order or a staged chunk
 * that no longer reads leaves the store exactly as it was.
 *
 * MySQL mode, against `lines_live` as install/database.sql creates it (with
 * its uuid and server_id keys, so 20k upserts stay fast on SQLite too).
 */
final class LargeSnapshotChunkingTest extends TestCase {
	private const SID = 5;

	private const TOTAL = 20000;

	private TestDb $rDb;

	private string $rDir;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$rSql = (string) file_get_contents(dirname(__DIR__, 2) . '/src/bin/install/database.sql');
		preg_match('/CREATE TABLE IF NOT EXISTS `lines_live` \(.*?\) ENGINE=[^;]*;/s', $rSql, $rM);
		$rDdl = (string) preg_replace(['/`activity_id` int\(11\) NOT NULL AUTO_INCREMENT/', '/,\s*PRIMARY KEY \(`activity_id`\)/', '/,\s*(UNIQUE )?KEY `\w+` \([^)]*\)( USING BTREE)?/', '/ COLLATE \w+/', '/\) ENGINE=[^;]*;/'], ['`activity_id` INTEGER PRIMARY KEY AUTOINCREMENT', '', '', '', ');'], $rM[0]);
		$this->rDb->exec($rDdl);
		$this->rDb->exec('CREATE INDEX `lines_live_uuid` ON `lines_live` (`uuid`)');
		$this->rDb->exec('CREATE INDEX `lines_live_server_id` ON `lines_live` (`server_id`)');
		DatabaseFactory::set($this->rDb);
		SettingsManager::set(['redis_handler' => 0]);
		$this->rDir = sys_get_temp_dir() . '/xcvm-bigsnap-' . bin2hex(random_bytes(4));
		mkdir($this->rDir);
		ConnectionDigest::useState($this->rDir . '/digest/');
		ConnectionSnapshot::useDir($this->rDir . '/snap/');
		ClusterClock::fix(1800000000000);

		// What MAIN holds before: 1000 of the node's viewers with older state,
		// 500 it no longer has, one it ended, and another node's viewers.
		$rInsert = $this->rDb->pdo->prepare('INSERT INTO `lines_live` (`uuid`, `server_id`, `user_id`, `stream_id`, `container`, `hls_last_read`, `hls_end`) VALUES (?, ?, ?, ?, ?, ?, ?)');
		$this->rDb->pdo->beginTransaction();
		for ($i = 0; $i < 1000; $i++) {
			$rInsert->execute([$this->uuid($i), self::SID, $this->user($i), 100, 'hls', 1, 0]);
		}
		for ($i = 0; $i < 500; $i++) {
			$rInsert->execute([sprintf('gone%06d', $i), self::SID, 9, 100, 'ts', 1, 0]);
		}
		$rInsert->execute(['ended000001', self::SID, 9, 100, 'hls', 1, 1]);
		for ($i = 0; $i < 3; $i++) {
			$rInsert->execute([sprintf('other%06d', $i), 6, 7, 100, 'ts', 1, 0]);
		}
		$this->rDb->pdo->commit();
	}

	protected function tearDown(): void {
		ClusterClock::fix(null);
		ConnectionDigest::useState(null);
		ConnectionSnapshot::useDir(null);
		SettingsManager::set([]);
		DatabaseFactory::reset();
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	private function uuid(int $i): string {
		return sprintf('v%010d', $i);
	}

	private function user(int $i): int {
		return 1000 + intdiv($i, 4); // four viewers a line
	}

	/** The node's registry, as the agent chunks it: 1000 records a chunk. @return list<list<array<string, mixed>>> */
	private function chunks(): array {
		$rRecords = [];
		for ($i = 0; $i < self::TOTAL; $i++) {
			$rRecords[] = [
				'uuid' => $this->uuid($i), 'user_id' => $this->user($i), 'stream_id' => 100 + $i % 50, 'user_ip' => '10.1.' . intdiv($i, 250) % 256 . '.' . $i % 250,
				'user_agent' => 'VLC/3.0.20 LibVLC/3.0.20', 'container' => $i % 3 === 0 ? 'ts' : 'hls', 'pid' => 0, 'date_start' => 1799990000 + $i,
				'geoip_country_code' => 'PT', 'isp' => 'Example ISP', 'hls_last_read' => 1800000000, 'hls_end' => 0,
			];
		}
		return array_chunk($rRecords, ConnectionSnapshot::MAX_RECORDS);
	}

	/** @param list<array<string, mixed>> $rRecords */
	private function send(string $rSnap, int $rSeq, bool $rLast, array $rRecords): array {
		return ConnectionSnapshot::receive(self::SID, ['snap_id' => $rSnap, 'seq' => $rSeq, 'last' => $rLast, 'records' => $rRecords]);
	}

	/** Every row MAIN's store holds, as [uuid, server, user, hls_last_read, hls_end]. */
	private function store(): string {
		$this->rDb->query('SELECT `uuid`, `server_id`, `user_id`, `hls_last_read`, `hls_end` FROM `lines_live` ORDER BY `uuid`');
		return hash('sha256', (string) json_encode(array_map(static fn($rR) => array_values($rR), $this->rDb->get_rows())));
	}

	private function rowsOf(int $rServerID): int {
		$this->rDb->query('SELECT COUNT(*) AS `n` FROM `lines_live` WHERE `server_id` = ?', $rServerID);
		return (int) $this->rDb->get_row()['n'];
	}

	public function testTwentyThousandConnectionsApplyWithTheLastChunkOnly(): void {
		$rChunks = $this->chunks();
		$this->assertCount(20, $rChunks);
		$this->assertLessThan(ConnectionSnapshot::MAX_CHUNKS, count($rChunks));
		foreach ($rChunks as $rChunk) {
			// The agent BOXes each chunk into one request under nginx's 8 MB cap.
			$this->assertLessThan(ClusterApi::MAX_BODY / 8, strlen((string) json_encode(['snap_id' => str_repeat('a', 32), 'seq' => 19, 'last' => false, 'records' => $rChunk])));
		}
		$rBefore = $this->store();
		$rSnap = 'a1b2c3d4e5f60718';
		foreach (array_slice($rChunks, 0, -1) as $rSeq => $rChunk) {
			$this->assertSame(['ok' => true, 'done' => false], $this->send($rSnap, $rSeq, false, $rChunk));
			$this->assertSame($rBefore, $this->store(), 'nothing applied before the last chunk (chunk ' . $rSeq . ')');
		}

		$rOut = $this->send($rSnap, 19, true, $rChunks[19]);
		$this->assertSame(['ok' => true, 'done' => true, 'applied' => self::TOTAL, 'removed' => 500, 'dropped' => 0], $rOut);
		$this->assertSame(self::TOTAL + 1, $this->rowsOf(self::SID), 'the whole registry, plus the viewer the node had ended');
		$this->assertSame(3, $this->rowsOf(6), 'another node\'s viewers are untouched');
		$this->rDb->query("SELECT COUNT(*) AS `n` FROM `lines_live` WHERE `uuid` LIKE 'gone%'");
		$this->assertSame(0, (int) $this->rDb->get_row()['n']);
		$this->rDb->query('SELECT COUNT(*) AS `n` FROM `lines_live` WHERE `server_id` = ? AND `hls_last_read` = 1800000000', self::SID);
		$this->assertSame(self::TOTAL, (int) $this->rDb->get_row()['n'], 'viewers MAIN already held are updated in place, not duplicated');
		$this->assertSame(ConnectionDigest::of(array_merge(...$rChunks)), ConnectionDigest::of(ConnectionDigest::stored(self::SID)), 'the digests agree');
		$this->assertSame([], glob($this->rDir . '/snap/' . self::SID . '/*.json') ?: [], 'nothing stays staged');
	}

	public function testABadChunkLeavesTheStoreUnchanged(): void {
		$rChunks = $this->chunks();
		$rBefore = $this->store();
		$rSnap = 'b1b2c3d4e5f60718';
		for ($rSeq = 0; $rSeq < 10; $rSeq++) {
			$this->send($rSnap, $rSeq, false, $rChunks[$rSeq]);
		}

		// Chunks MAIN refuses outright (400): too big, not a list, past the cap.
		$this->assertTrue($this->send($rSnap, 10, false, array_merge($rChunks[10], [$rChunks[11][0]]))['bad'] ?? false, 'over 1000 records');
		$this->assertTrue($this->send($rSnap, 10, true, ['a' => $rChunks[10][0]])['bad'] ?? false, 'records not a list');
		$this->assertTrue($this->send($rSnap, ConnectionSnapshot::MAX_CHUNKS, true, [])['bad'] ?? false, 'past the chunk cap');
		$this->assertTrue(ConnectionSnapshot::receive(self::SID, ['snap_id' => $rSnap, 'seq' => 10, 'last' => 'yes', 'records' => $rChunks[10]])['bad'] ?? false, 'last not a boolean');
		$this->assertSame($rBefore, $this->store());

		// A chunk out of order (409 SNAP_GAP), even the last one: the agent drops the snapshot.
		$this->assertSame(['ok' => false, 'expected_seq' => 10], $this->send($rSnap, 11, true, $rChunks[11]));
		$this->assertSame(['ok' => false, 'expected_seq' => 0], $this->send('c1b2c3d4e5f60718', 3, true, $rChunks[3]), 'a chunk of another snapshot');
		$this->assertSame($rBefore, $this->store());

		// A staged chunk that no longer reads: the last chunk applies nothing,
		// the staging is cleared, and the node starts over from chunk 0.
		for ($rSeq = 10; $rSeq < 19; $rSeq++) {
			$this->assertTrue($this->send($rSnap, $rSeq, false, $rChunks[$rSeq])['ok']);
		}
		file_put_contents($this->rDir . '/snap/' . self::SID . '/4.json', substr((string) file_get_contents($this->rDir . '/snap/' . self::SID . '/4.json'), 0, 4096));
		$this->assertSame(['ok' => false, 'expected_seq' => 0], $this->send($rSnap, 19, true, $rChunks[19]));
		$this->assertSame($rBefore, $this->store(), 'the store is exactly as before the snapshot');
		$this->assertSame([], glob($this->rDir . '/snap/' . self::SID . '/*.json') ?: []);

		// Sent again whole, it applies.
		$rSnap = 'd1b2c3d4e5f60718';
		foreach ($rChunks as $rSeq => $rChunk) {
			$rOut = $this->send($rSnap, $rSeq, $rSeq === 19, $rChunk);
		}
		$this->assertSame(['ok' => true, 'done' => true, 'applied' => self::TOTAL, 'removed' => 500, 'dropped' => 0], $rOut);
		$this->assertSame(ConnectionDigest::of(array_merge(...$rChunks)), ConnectionDigest::of(ConnectionDigest::stored(self::SID)));
	}
}
