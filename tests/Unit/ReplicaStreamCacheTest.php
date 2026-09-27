<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cache\FileCache;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\ReplicaApply;
use XcVm\Core\Cluster\ReplicaSections;
use XcVm\Core\Cluster\ReplicaStreamCache;
use XcVm\Core\Cluster\StreamRecords;
use XcVm\Domain\Stream\StreamSource;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;
use XcVm\Tests\Support\ReplicaFixture;

/**
 * The node's half of the R2 `streams` section (cluster plan, section 9,
 * "Storage and boot"; section 10): cluster:apply turns the records the agent
 * stored into the node's stream caches, in the shapes its readers took from
 * MAIN's database (StreamSource: the stream starts, the monitor, the proxy
 * producer, live.php, the scanner, the recorder). With the STREAMS flow on
 * they are authoritative: the readers stop reading MAIN's database once an
 * apply built them. With it off the apply only reports how the section
 * differs from MAIN's database, ids and names only. A record that does not
 * read never deletes its stream's entry; a stream the agent removed loses
 * it. `--from-disk` verifies each record. Mode 0 is unchanged.
 */
final class ReplicaStreamCacheTest extends TestCase {
	private string $rDir;

	private ReplicaFixture $rFixture;

	private TestDb $rDb;

	/** This node (SERVER_ID, whichever test defined it first) and another server. */
	private int $rSid;

	private int $rOther;

	protected function setUp(): void {
		if (!defined('SERVER_ID')) {
			define('SERVER_ID', 5);
		}
		$this->rSid = (int) SERVER_ID;
		$this->rOther = $this->rSid + 1;
		$this->rDir = sys_get_temp_dir() . '/xcvm-stream-cache-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir . 'cluster', 0777, true);
		mkdir($this->rDir . 'cache', 0777, true);
		$this->rFixture = new ReplicaFixture($this->rDir . 'cluster/');
		ReplicaApply::useDir($this->rFixture->dir());
		ReplicaApply::useConfigDir($this->rDir);
		(new \ReflectionProperty(FileCache::class, 'defaultInstance'))->setValue(null, new FileCache($this->rDir . 'cache/'));
		$this->flows(NodeFlows::STREAMS);
		$this->rDb = new TestDb();
		foreach (['streams', 'streams_servers', 'recordings', 'profiles', 'streams_types', 'streams_arguments', 'streams_options'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec("INSERT INTO `streams_types` VALUES (1, 'Live Streams', 'live', 'live', 1), (2, 'Movies', 'movie', 'movie', 0), (3, 'Created Live', 'created_live', 'live', 1)");
		$this->rDb->exec("INSERT INTO `profiles` VALUES (7, 'hd', '{\"3\":{\"cmd\":\"-b:v 4M\"}}')");
		$this->rDb->exec("INSERT INTO `streams_arguments` VALUES (1, 'fetch', 'User Agent', 'shown in the form', 'http', 'user_agent', '-user_agent \"%s\"', 'text', 'VLC'), (2, 'fetch', 'Proxy', 'a proxy', 'http', 'proxy', '-http_proxy \"%s\"', 'text', NULL)");
		// 10 live, assigned here and relayed on by the other server; 11 a movie
		// assigned here; 12 recorded here (its TV archive) only; 13 with a
		// recording scheduled here; 14 on the other server alone; 15 a direct
		// source assigned here.
		$this->rDb->exec("INSERT INTO `streams` (`id`, `type`, `stream_display_name`, `stream_source`, `notes`, `order`, `transcode_profile_id`, `enable_transcode`, `tv_archive_server_id`, `tv_archive_duration`, `vframes_server_id`, `direct_source`, `probesize_ondemand`, `target_container`, `tmdb_id`) VALUES
			(10, 1, 'News', '[\"http://user:pass@src.example/a\"]', 'secret-notes', 3, 7, 1, 0, 0, 0, 0, 512000, NULL, 0),
			(11, 2, 'Film', '[\"http://src.example/film.mkv\"]', NULL, 4, 0, 0, 0, 0, 0, 0, 0, 'mkv', 42),
			(12, 1, 'Archive', '[\"http://src.example/c\"]', NULL, 5, 0, 0, {$this->rSid}, 24, 0, 0, 0, NULL, 0),
			(13, 1, 'Recorded', '[\"http://src.example/d\"]', NULL, 6, 0, 0, 0, 0, 0, 0, 0, NULL, 0),
			(14, 1, 'Elsewhere', '[\"http://src.example/e\"]', NULL, 7, 0, 0, 0, 0, 0, 0, 0, NULL, 0),
			(15, 1, 'Direct', '[\"http://src.example/f\"]', NULL, 8, 0, 0, 0, 0, 0, 1, 0, NULL, 0)");
		$this->rDb->exec("INSERT INTO `streams_servers` (`server_stream_id`, `stream_id`, `server_id`, `parent_id`, `on_demand`, `pid`, `stream_status`, `current_source`, `bitrate`) VALUES
			(1, 10, {$this->rSid}, NULL, 1, 4242, 0, 'http://user:pass@src.example/a', 3000),
			(2, 10, {$this->rOther}, {$this->rSid}, 0, 0, 0, NULL, NULL),
			(3, 11, {$this->rSid}, NULL, 0, 99, 0, NULL, NULL),
			(4, 14, {$this->rOther}, NULL, 0, 0, 0, NULL, NULL),
			(5, 15, {$this->rSid}, NULL, 0, 0, 0, NULL, NULL)");
		$this->rDb->exec("INSERT INTO `streams_options` (`id`, `stream_id`, `argument_id`, `value`) VALUES (1, 10, 1, 'curl/8'), (2, 10, 2, 'http://proxy.example:3128')");
		$this->rDb->exec("INSERT INTO `recordings` (`id`, `stream_id`, `created_id`, `source_id`, `title`, `start`, `end`, `archive`, `status`) VALUES (1, 13, 0, {$this->rSid}, 'Match', 100, 200, 0, 0), (2, 13, 0, {$this->rOther}, 'Match elsewhere', 100, 200, 0, 0)");
		DatabaseFactory::set($this->rDb);
	}

	protected function tearDown(): void {
		DatabaseFactory::reset();
		StreamSource::useLoader(null);
		ReplicaApply::useDir(null);
		ReplicaApply::useConfigDir(null);
		NodeFlows::usePath(null);
		(new \ReflectionProperty(FileCache::class, 'defaultInstance'))->setValue(null, null);
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	private function flows(?int $rFlows, int $rMode = 1): void {
		if ($rFlows === null) {
			@unlink($this->rDir . 'flows.json');
		} else {
			file_put_contents($this->rDir . 'flows.json', json_encode(['mode' => $rMode, 'flows' => $rFlows, 'state' => 'active']));
		}
		NodeFlows::usePath($this->rDir . 'flows.json');
	}

	/** The section as the agent stores it: MAIN's records of the streams this node holds, and a completed pass. */
	private function storeSection(int $rSince = 7): void {
		foreach (StreamRecords::data($this->rSid, StreamRecords::held($this->rSid, null)) as $rID => $rData) {
			$this->rFixture->stream($rID, $rData, 3);
		}
		$this->rFixture->streamsSince($rSince);
	}

	/** @return array<string, mixed> */
	private function apply(bool $rFromDisk = false): array {
		$rReport = ReplicaApply::run(false, 1800000000, $this->rSid, $rFromDisk);
		$this->assertIsArray($rReport);
		$this->assertArrayHasKey(ReplicaSections::STREAMS, $rReport);
		return $rReport;
	}

	/** MAIN's database gone: whatever reads it now fails. */
	private function mainUnreachable(): void {
		DatabaseFactory::reset();
	}

	/**
	 * What every reader of a stream's definition gets.
	 *
	 * @return array<string, mixed>
	 */
	private function answers(): array {
		$rOut = [];
		foreach ([10, 11, 12, 13, 14, 15, 99] as $rID) {
			$rOut[$rID] = [
				'live' => StreamSource::streamRow($rID, true),
				'vod' => StreamSource::streamRow($rID, false),
				'server' => StreamSource::serverRow($rID),
				'arguments' => StreamSource::arguments($rID),
				'keyed' => StreamSource::arguments($rID, true),
				'source' => StreamSource::sourceRow($rID),
			];
		}
		$rOut['recordings'] = [1 => StreamSource::recording(1), 2 => StreamSource::recording(2)];
		return $rOut;
	}

	/** Scalars as strings, as a driver reads them, at any depth. */
	private static function loose(mixed $rValue): mixed {
		if (is_array($rValue)) {
			return array_map([self::class, 'loose'], $rValue);
		}
		return $rValue === null ? null : (string) $rValue;
	}

	/**
	 * The same shape: every key the SQL answer has, the same values but for
	 * the columns no record carries, which are null.
	 */
	private function assertSameShape(?array $rSql, ?array $rReplica, string $rWhat): void {
		if ($rSql === null || $rReplica === null) {
			$this->assertSame($rSql, $rReplica, $rWhat);
			return;
		}
		$this->assertEqualsCanonicalizing(array_keys($rSql), array_keys($rReplica), $rWhat . ': the same columns');
		$rLocal = array_merge(ReplicaSections::STREAM_LOCAL, ReplicaSections::STREAM_SERVER_LOCAL, ['argument_description']);
		foreach ($rSql as $rKey => $rValue) {
			if (is_array($rValue)) {
				$this->assertSameShape($rValue, $rReplica[$rKey], $rWhat . '.' . $rKey);
			} elseif (in_array($rKey, $rLocal, true)) {
				$this->assertNull($rReplica[$rKey], $rWhat . '.' . $rKey . ': no record carries it');
			} else {
				$this->assertSame(self::loose($rValue), self::loose($rReplica[$rKey]), $rWhat . '.' . $rKey);
			}
		}
	}

	public function testTheCachesAnswerInTheShapesMainsDatabaseDid(): void {
		$this->flows(0);
		$rSql = $this->answers();
		$this->assertSame('curl/8', $rSql[10]['keyed']['user_agent']['value'], 'the SQL backend, as before');
		$this->assertSame('Match', $rSql['recordings'][1]['title']);

		$this->storeSection();
		$this->flows(NodeFlows::STREAMS);
		$rReport = $this->apply();
		$this->assertSame(['since' => 7, 'streams' => 5, 'mode' => 'applied', 'written' => 5, 'removed' => 0, 'unreadable' => []], $rReport[ReplicaSections::STREAMS]);
		$this->assertTrue(ReplicaStreamCache::owned());

		// MAIN's database is gone: every answer comes from the caches.
		$this->mainUnreachable();
		$rReplica = $this->answers();
		foreach ([10, 11, 12, 13, 15] as $rID) {
			foreach ($rSql[$rID] as $rWhat => $rAnswer) {
				$this->assertSameShape($rAnswer, $rReplica[$rID][$rWhat], $rID . '.' . $rWhat);
			}
		}
		$this->assertSameShape($rSql['recordings'][1], $rReplica['recordings'][1], 'recordings.1');
		// A stream, or a recording, the node does not hold is none of its business.
		$this->assertSame(['live' => null, 'vod' => null, 'server' => null, 'arguments' => [], 'keyed' => [], 'source' => []], $rReplica[14]);
		$this->assertNull($rReplica['recordings'][2]);
		$this->assertNotNull($rSql[14]['live'], 'MAIN\'s database answered for any stream');

		// The answers that matter, spelled out.
		$this->assertSame(['http://user:pass@src.example/a'], json_decode((string) $rReplica[10]['live']['stream_source'], true));
		$this->assertSame(['live', 7, 'hd'], [$rReplica[10]['live']['type_key'], $rReplica[10]['live']['profile_id'], $rReplica[10]['live']['profile_name']]);
		$this->assertNull($rReplica[10]['live']['notes'], 'MAIN\'s catalogue metadata stays MAIN\'s');
		$this->assertSame([1, null, null], [$rReplica[10]['server']['on_demand'], $rReplica[10]['server']['pid'], $rReplica[10]['server']['current_source']], 'the runtime state is the node\'s, no record carries it');
		$this->assertSame(['user_agent', 'proxy'], array_keys($rReplica[10]['keyed']));
		$this->assertSame(2, $rReplica[10]['keyed']['proxy']['id'], 'the argument\'s id wins the join, as in SQL');
		$this->assertNull($rReplica[11]['live'], 'a movie is not a live stream');
		$this->assertSame('mkv', $rReplica[11]['vod']['target_container']);
		$this->assertNull($rReplica[12]['server'], 'held for its archive, not run here');
		$this->assertNull($rReplica[15]['live'], 'a direct source is not run by a node');
		$this->assertSame(['stream_source' => '["http://src.example/f"]'], $rReplica[15]['source']);
		$this->assertSame([$this->rOther], ReplicaStreamCache::get(10)['children']);

		// Another server's row is not the replica's to answer.
		DatabaseFactory::set($this->rDb);
		$this->assertSame($this->rOther, (int) StreamSource::serverRow(10, $this->rOther)['server_id']);
	}

	public function testWithStreamsOffTheApplyOnlyReportsHowTheSectionDiffers(): void {
		$this->storeSection();
		// MAIN changed since the agent's last sync: a source, an unassignment, an assignment.
		$this->rDb->exec("UPDATE `streams` SET `stream_source` = '[\"http://user:pass@src.example/a2\"]' WHERE `id` = 10");
		$this->rDb->exec('DELETE FROM `streams_servers` WHERE `stream_id` = 11');
		$this->rDb->exec("INSERT INTO `streams_servers` (`server_stream_id`, `stream_id`, `server_id`, `parent_id`, `on_demand`) VALUES (6, 14, {$this->rSid}, NULL, 0)");
		$this->rDb->exec("UPDATE `recordings` SET `title` = 'Final' WHERE `id` = 1");
		$this->flows(NodeFlows::CONFIG);
		$rReport = $this->apply()[ReplicaSections::STREAMS];
		$this->assertSame(['since' => 7, 'streams' => 5, 'mode' => 'shadow', 'missing' => [14], 'extra' => [11], 'unreadable' => [], 'differ' => ['10.stream.stream_source', '13.recordings']], $rReport);
		$rJson = (string) json_encode($rReport);
		foreach (['src.example', 'pass', 'Final', 'Match', 'tickets'] as $rValue) {
			$this->assertStringNotContainsString($rValue, $rJson, 'names only, never a value or the tickets');
		}
		$this->assertSame([], ReplicaStreamCache::cached(), 'nothing written');
		$this->assertFalse(ReplicaStreamCache::owned());
		$this->assertSame('["http://user:pass@src.example/a2"]', StreamSource::streamRow(10, true)['stream_source'], 'the readers keep MAIN\'s database');

		// MAIN's database does not answer: nothing to compare with, and still nothing written.
		$this->mainUnreachable();
		$this->assertSame(['since' => 7, 'streams' => 5, 'mode' => 'shadow', 'compared' => false, 'unreadable' => []], $this->apply()[ReplicaSections::STREAMS]);
	}

	public function testTheFlowDecidesAndHandsTheStreamsBack(): void {
		$this->storeSection();
		$this->apply();
		$this->assertTrue(ReplicaStreamCache::owned());
		$this->rDb->exec("UPDATE `streams` SET `stream_display_name` = 'Renamed' WHERE `id` = 10");
		$this->assertSame('News', StreamSource::streamRow(10, true)['stream_display_name'], 'the replica\'s, however MAIN\'s database changed');

		// CONFIG going off leaves the streams to their own flow.
		ReplicaApply::disown();
		$this->assertTrue(ReplicaStreamCache::owned());

		// STREAMS off: cron:cache's minute hands them back at once, and they are MAIN's database's.
		$this->flows(NodeFlows::CONFIG);
		$this->assertFalse(ReplicaStreamCache::owned(), 'the flow is read at each call');
		$this->assertNull(ReplicaApply::streamsMinute($this->rSid));
		$this->assertFalse(ReplicaApply::built(ReplicaSections::STREAMS));
		$this->assertSame('Renamed', StreamSource::streamRow(10, true)['stream_display_name']);

		// On again: nothing is the replica's until an apply built it; the minute does.
		$this->flows(NodeFlows::STREAMS);
		$this->assertFalse(ReplicaStreamCache::owned());
		$this->assertSame('applied', ReplicaApply::streamsMinute($this->rSid)['mode']);
		$this->assertSame(0, ReplicaApply::streamsMinute($this->rSid)['written'], 'an unchanged record is not written again');
		$this->assertSame('News', StreamSource::streamRow(10, true)['stream_display_name']);

		// STREAMS off with CONFIG on: cron:cache's apply never compares a shadow
		// section with MAIN's database, and keeps the agent's last comparison.
		$this->flows(0);
		$rShadow = $this->apply()[ReplicaSections::STREAMS];
		$this->assertSame(['shadow', ['10.stream.stream_display_name']], [$rShadow['mode'], $rShadow['differ']]);
		$this->flows(NodeFlows::CONFIG);
		$this->mainUnreachable();
		$rReport = ReplicaApply::run(true, 1800000000, $this->rSid, false, true);
		$this->assertSame($rShadow, $rReport[ReplicaSections::STREAMS]);
		$this->assertFalse(ReplicaStreamCache::owned());
	}

	public function testARecordThatDoesNotReadNeverDeletesItsStreamsEntry(): void {
		$rData = StreamRecords::data($this->rSid, [10, 11]);
		$this->storeSection();
		$this->apply();
		file_put_contents($this->rFixture->dir() . 'streams/10.json', '{"etag": "torn');
		$rReport = $this->apply()[ReplicaSections::STREAMS];
		$this->assertSame(['applied', 0, 0, [10]], [$rReport['mode'], $rReport['written'], $rReport['removed'], $rReport['unreadable']]);
		$this->mainUnreachable();
		$this->assertSame('News', StreamSource::streamRow(10, true)['stream_display_name'], 'kept as it was');
		// One that is another stream's, or names another server's row, is not this one's either.
		$this->rFixture->stream(10, $rData[11]);
		$this->assertSame([10], $this->apply()[ReplicaSections::STREAMS]['unreadable']);
		$rTen = $rData[10];
		$rTen['server']['server_id'] = $this->rOther;
		$this->rFixture->stream(10, $rTen);
		$this->assertSame([10], $this->apply()[ReplicaSections::STREAMS]['unreadable']);
		$this->assertSame('News', StreamSource::streamRow(10, true)['stream_display_name']);

		// From disk, a record missing behind its .json is not a removal either.
		DatabaseFactory::set($this->rDb);
		$this->storeSection();
		unlink($this->rFixture->dir() . 'streams/10.rep');
		$rReport = $this->apply(true);
		$this->assertSame([10], $rReport[ReplicaSections::STREAMS]['unreadable']);
		$this->assertSame(['streams'], $rReport['from_disk']['unverified']);
		$this->assertNotNull(ReplicaStreamCache::get(10));

		// The agent removed it (both files): the node no longer holds it, and never asks MAIN.
		unlink($this->rFixture->dir() . 'streams/10.json');
		$rReport = $this->apply()[ReplicaSections::STREAMS];
		$this->assertSame([1, 4], [$rReport['removed'], $rReport['streams']]);
		$this->mainUnreachable();
		$this->assertNull(StreamSource::streamRow(10, true));
		$this->assertSame([11, 12, 13, 15], ReplicaStreamCache::cached());
		$this->assertSame([11, 12, 13, 15], array_keys(ReplicaStreamCache::index()));
	}

	public function testWithoutAWholeSectionTheReadersKeepMainsDatabase(): void {
		$this->storeSection(0);
		$this->assertSame(['since' => 0, 'mode' => 'incomplete'], $this->apply()[ReplicaSections::STREAMS], 'the agent has not completed a pass');
		$this->assertFalse(ReplicaStreamCache::owned());
		$this->assertSame([], ReplicaStreamCache::cached());

		$this->rFixture->streamsSince(7);
		$this->apply();
		$this->assertTrue(ReplicaStreamCache::owned());
		// A file that names no stream: the section is not the agent's, nothing is touched, MAIN's database again.
		file_put_contents($this->rFixture->dir() . 'streams/notes.json', '{}');
		$this->assertSame(['since' => 7, 'mode' => 'refused'], $this->apply()[ReplicaSections::STREAMS]);
		$this->assertFalse(ReplicaStreamCache::owned());
		$this->assertSame([10, 11, 12, 13, 15], ReplicaStreamCache::cached(), 'no entry deleted');
		$this->rDb->exec("UPDATE `streams` SET `stream_display_name` = 'Renamed' WHERE `id` = 10");
		$this->assertSame('Renamed', StreamSource::streamRow(10, true)['stream_display_name']);
		unlink($this->rFixture->dir() . 'streams/notes.json');

		// The directory lost: a section lost, never one that holds nothing.
		exec('rm -rf ' . escapeshellarg($this->rFixture->dir() . 'streams'));
		$this->assertSame(['since' => 7, 'mode' => 'incomplete'], $this->apply()[ReplicaSections::STREAMS]);
		$this->assertSame([10, 11, 12, 13, 15], ReplicaStreamCache::cached());

		// No section at all: nothing to report.
		unlink($this->rFixture->dir() . 'streams.json');
		$this->assertNull(ReplicaApply::run(true, 1800000000, $this->rSid), 'nothing the agent wrote');
	}

	public function testFromDiskEachRecordIsVerifiedAndWins(): void {
		$this->storeSection();
		// The .json says something else (planted or torn): the signed record wins.
		$rTen = StreamRecords::data($this->rSid, [10])[10];
		$rPlanted = $rTen;
		$rPlanted['stream']['stream_source'] = '["http://evil.example/"]';
		$this->rFixture->stream(10, $rTen, 3, $rPlanted);
		$rReport = $this->apply(true);
		$this->assertSame(['verified' => ['streams'], 'unverified' => []], $rReport['from_disk']);
		$this->assertSame(5, $rReport[ReplicaSections::STREAMS]['written']);
		$this->mainUnreachable();
		$this->assertSame('["http://user:pass@src.example/a"]', StreamSource::sourceRow(10)['stream_source']);
		// An entry written from a planted .json is written again from its record.
		ReplicaApply::run(false, 1800000000, $this->rSid);
		$rPlanted['stream']['stream_display_name'] = 'Planted';
		$this->rFixture->stream(10, $rTen, 3, $rPlanted);
		ReplicaStreamCache::store()->set('10', ReplicaStreamCache::entry(10, $rPlanted, $this->rSid, ReplicaStreamCache::index()[10]['etag'], 3));
		$this->assertSame('Planted', StreamSource::streamRow(10, true)['stream_display_name']);
		$this->assertSame(5, $this->apply(true)[ReplicaSections::STREAMS]['written']);
		$this->assertSame('News', StreamSource::streamRow(10, true)['stream_display_name']);

		// Signed by another panel key, for another node, another stream's, corrupt: none is taken.
		DatabaseFactory::set($this->rDb);
		$this->storeSection();
		$rData = StreamRecords::data($this->rSid, [11, 12, 13]);
		file_put_contents($this->rFixture->dir() . 'streams/11.rep', $this->rFixture->record('rep', ['section' => 'stream', 'node' => $this->rFixture->rUuid, 'stream_id' => 11, 'ver' => 3, 'etag' => str_repeat('c', 64), 'data' => $rData[11]], ReplicaFixture::otherPanel()));
		file_put_contents($this->rFixture->dir() . 'streams/12.rep', $this->rFixture->record('rep', ['section' => 'stream', 'node' => '00000000-0000-4000-a000-000000000000', 'stream_id' => 12, 'ver' => 3, 'etag' => str_repeat('c', 64), 'data' => $rData[12]]));
		copy($this->rFixture->dir() . 'streams/10.rep', $this->rFixture->dir() . 'streams/13.rep');
		$this->rFixture->corrupt('streams/15.rep');
		ReplicaStreamCache::store()->flush();
		$rReport = $this->apply(true);
		$this->assertSame([11, 12, 13, 15], $rReport[ReplicaSections::STREAMS]['unreadable']);
		$this->assertSame(['streams'], $rReport['from_disk']['unverified']);
		$this->assertSame([10], ReplicaStreamCache::cached());
		$this->assertStringNotContainsString('src.example', (string) json_encode($rReport), 'the report names streams, never their content');
		// Nor is a stream whose record did not verify built later from its unsigned .json.
		$this->assertNull(StreamSource::streamRow(11, false));
		$this->assertNull(StreamSource::streamRow(12, true));
		$this->assertSame([10], ReplicaStreamCache::cached());
		// Once the agent's apply reads them, they are.
		ReplicaApply::run(false, 1800000000, $this->rSid);
		$this->assertSame('Film', StreamSource::streamRow(11, false)['stream_display_name']);
	}

	public function testAnEntryGoneIsBuiltAgainFromTheAgentsFile(): void {
		$this->storeSection();
		$this->apply();
		$this->assertSame('0700', substr(sprintf('%o', fileperms($this->rDir . 'cache/' . ReplicaStreamCache::DIR)), -4), 'the entries hold the sources');
		ReplicaStreamCache::store()->delete('10');
		$this->mainUnreachable();
		$this->assertSame('News', StreamSource::streamRow(10, true)['stream_display_name'], 'the agent\'s file, never MAIN\'s database');
		$this->assertContains(10, ReplicaStreamCache::cached(), 'and cached again');
	}

	public function testModeZeroIsUnchanged(): void {
		$this->storeSection();
		$this->flows(null);
		$rReport = $this->apply()[ReplicaSections::STREAMS];
		$this->assertSame('shadow', $rReport['mode'], 'a legacy node only compares');
		$this->assertSame([], ReplicaStreamCache::cached());
		// Even a stale record of ownership changes nothing without the flow.
		FileCache::setCache(ReplicaApply::OWNED_CACHE, [ReplicaSections::STREAMS => '7']);
		$this->assertFalse(ReplicaStreamCache::owned());
		$this->rDb->exec("UPDATE `streams` SET `stream_display_name` = 'Renamed' WHERE `id` = 10");
		$this->assertSame('Renamed', StreamSource::streamRow(10, true)['stream_display_name']);
		$this->assertNull(ReplicaApply::streamsMinute($this->rSid));
		$this->assertFalse(ReplicaApply::built(ReplicaSections::STREAMS));
	}
}
