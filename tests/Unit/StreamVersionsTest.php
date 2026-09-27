<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\ReplicaSections;
use XcVm\Core\Cluster\StreamVersions;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Events\EventDispatcher;
use XcVm\Core\Events\Stream\StreamArgumentsChangedEvent;
use XcVm\Core\Events\Stream\StreamsChangedEvent;
use XcVm\Core\Events\Stream\StreamsDeletedEvent;
use XcVm\Core\Events\Stream\TranscodeProfileSavedEvent;
use XcVm\Domain\Epg\EpgService;
use XcVm\Domain\Stream\ContentSink;
use XcVm\Domain\Stream\RecordingFinalizer;
use XcVm\Domain\Stream\StreamConfigRepository;
use XcVm\Domain\Stream\StreamRepository;
use XcVm\Domain\Stream\StreamRowMerge;
use XcVm\Domain\Stream\StreamService;
use XcVm\Domain\Stream\StreamStateWriter;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;
use XcVm\Tests\Support\QueryLogDb;

if (!defined('STATUS_SUCCESS')) {
	define('STATUS_SUCCESS', 1);
}

/**
 * The R2 `streams` section's versions (cluster plan, section 9, "Change
 * detection"): MAIN's writers of a stream's desired configuration dispatch
 * an event, and StreamVersions stamps the stream anew in cluster_stream_ver
 * for every server that holds it now or held it before, each stream of a
 * bump with a version of its own. What nodes write back never bumps.
 */
final class StreamVersionsTest extends TestCase {
	private TestDb $rDb;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach (['streams', 'streams_servers', 'recordings', 'profiles', 'streams_arguments', 'streams_options'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec(InstallSchema::migration('034_create_cluster_changes'));
		$this->rDb->exec('CREATE TABLE `cluster_meta` (`name` varchar(64) PRIMARY KEY, `value` mediumtext, `updated_at` int NOT NULL)');
		DatabaseFactory::set($this->rDb);
		// The writers below read DatabaseFactory's handle, never one another test injected.
		foreach ([StreamRepository::class, StreamService::class, StreamConfigRepository::class, EpgService::class, RecordingFinalizer::class] as $rClass) {
			(new \ReflectionProperty($rClass, 'db'))->setValue(null, null);
		}
		EventDispatcher::resetInstance();
		SettingsManager::set([]);
	}

	protected function tearDown(): void {
		EventDispatcher::resetInstance();
		StreamStateWriter::useSink(null);
		DatabaseFactory::reset();
		SettingsManager::set([]);
	}

	/** Streams 10 and 11 on servers 2 and 3; 12 archived on 4 and thumbnailed on 5; a recording of 11 on 6. */
	private function catalogue(): void {
		$this->rDb->exec("INSERT INTO `streams` (`id`, `type`, `stream_source`, `transcode_profile_id`, `tv_archive_server_id`, `vframes_server_id`, `epg_id`) VALUES (10, 1, '[]', 7, 0, 0, 3), (11, 1, '[]', 0, 0, 0, NULL), (12, 1, '[]', 0, 4, 5, NULL)");
		$this->rDb->exec('INSERT INTO `streams_servers` (`stream_id`, `server_id`, `parent_id`, `on_demand`) VALUES (10, 2, NULL, 0), (10, 3, 2, 1), (11, 2, NULL, 0)');
		$this->rDb->exec("INSERT INTO `recordings` (`id`, `stream_id`, `source_id`, `title`, `start`, `end`, `archive`, `status`) VALUES (1, 11, 6, 'news', 100, 200, 0, 0)");
	}

	/** Every holder's row at version 0, as migration 047 seeds an install's. */
	private function seed(): void {
		foreach ([
			'SELECT `server_id`, `stream_id`, 0, 0 FROM `streams_servers`',
			'SELECT `tv_archive_server_id`, `id`, 0, 0 FROM `streams` WHERE `tv_archive_server_id` > 0',
			'SELECT `vframes_server_id`, `id`, 0, 0 FROM `streams` WHERE `vframes_server_id` > 0',
			'SELECT `source_id`, `stream_id`, 0, 0 FROM `recordings` WHERE `source_id` > 0',
		] as $rSelect) {
			$this->rDb->exec('REPLACE INTO `cluster_stream_ver` (`server_id`, `stream_id`, `ver`, `updated_at`) ' . $rSelect);
		}
	}

	/** @return array<string, int> "server:stream" => ver */
	private function rows(): array {
		$this->rDb->query('SELECT `server_id`, `stream_id`, `ver` FROM `cluster_stream_ver` ORDER BY `server_id`, `stream_id`');
		$rOut = [];
		foreach ($this->rDb->get_rows() as $rRow) {
			$rOut[$rRow['server_id'] . ':' . $rRow['stream_id']] = (int) $rRow['ver'];
		}
		return $rOut;
	}

	public function testABumpStampsEveryServerThatHoldsTheStreamWithAVersionOfItsOwn(): void {
		$this->catalogue();
		$this->assertSame(1, StreamVersions::head(), 'before any change: 1, never 0');

		$this->assertSame(4, StreamVersions::bump([12, 10, '11', 10, 0, -3]));

		// Ids in order, one version each; the assignment, the archive and
		// thumbnail servers and the recording's node all hold the stream.
		$this->assertSame(['2:10' => 2, '2:11' => 3, '3:10' => 2, '4:12' => 4, '5:12' => 4, '6:11' => 3], $this->rows());
		$this->assertSame(4, StreamVersions::head());
		$this->assertSame(0, StreamVersions::floor());
		$this->assertSame(0, StreamVersions::bump([]), 'nothing to stamp');
		$this->assertSame(4, StreamVersions::head());
	}

	public function testAServerThatNoLongerHoldsTheStreamIsStampedToo(): void {
		$this->catalogue();
		StreamVersions::bump([10]);
		$this->rDb->exec('DELETE FROM `streams_servers` WHERE `server_id` = 3');
		$this->rDb->exec('DELETE FROM `streams` WHERE `id` = 10');

		$this->assertSame(3, StreamVersions::bump([10]));
		// Its row moves, so the node sees the change (a removal) past its cursor.
		$this->assertSame(['2:10' => 3, '3:10' => 3], $this->rows());
	}

	public function testResetRaisesTheFloorToANewVersion(): void {
		$this->catalogue();
		StreamVersions::bump([10, 11]);
		$this->assertSame(4, StreamVersions::reset());
		$this->assertSame([4, 4], [StreamVersions::head(), StreamVersions::floor()]);
		$this->assertSame(['2:10' => 2, '2:11' => 3, '3:10' => 2, '6:11' => 3], $this->rows(), 'no row is stamped');

		StreamVersions::raiseFloor(3);
		$this->assertSame(4, StreamVersions::floor(), 'never lowered');
		StreamVersions::raiseFloor(9);
		$this->assertSame(9, StreamVersions::floor());
	}

	public function testTheCounterIsKeptWhereTheMigrationStartedIt(): void {
		$this->catalogue();
		$this->rDb->exec("INSERT INTO `cluster_meta` VALUES ('stream_ver', '1', 0)");
		$this->assertSame(2, StreamVersions::bump([10]));
		$this->assertSame(4, StreamVersions::bump([11, 12]));
	}

	public function testAFailedStatementNeverThrowsAndStampsNothingPastIt(): void {
		$this->catalogue();
		$rLog = new QueryLogDb($this->rDb);
		$rLog->rRefuse = '/^REPLACE INTO `cluster_stream_ver`/';
		DatabaseFactory::set($rLog);
		$this->assertSame(0, StreamVersions::bump([10]));
		$this->assertSame([], $this->rows());

		$rLog->rRefuse = '/FROM `recordings`/';
		$this->assertSame(0, StreamVersions::bump([10]), 'a holder that cannot be read: nothing is stamped, never a partial list');
		$this->assertSame([], $this->rows());
		$rLog->rRefuse = '/cluster_meta/';
		$this->assertSame(0, StreamVersions::reset());
	}

	public function testEachEventStampsItsStreams(): void {
		$this->catalogue();
		$this->rDb->exec("INSERT INTO `streams_arguments` (`id`, `argument_key`, `argument_default_value`) VALUES (1, 'user_agent', 'VLC'), (2, 'proxy', NULL)");
		$this->rDb->exec("INSERT INTO `streams_options` (`stream_id`, `argument_id`, `value`) VALUES (11, 1, 'curl'), (12, 2, 'http://p')");
		EventDispatcher::subscribe(StreamVersions::class);

		EventDispatcher::dispatch(new StreamsChangedEvent([11]));
		$this->assertSame(['2:11' => 2, '6:11' => 2], $this->rows());
		EventDispatcher::dispatch(new StreamsDeletedEvent([12]));
		$this->assertSame(3, $this->rows()['4:12']);
		EventDispatcher::dispatch(new TranscodeProfileSavedEvent(7));
		$this->assertSame([4, 4], [$this->rows()['2:10'], $this->rows()['3:10']], 'the streams that transcode with it');
		EventDispatcher::dispatch(new StreamArgumentsChangedEvent(['user_agent']));
		$this->assertSame(5, $this->rows()['2:11'], 'the streams with an option of it');
		$this->assertSame(3, $this->rows()['4:12']);
		EventDispatcher::dispatch(StreamsChangedEvent::all());
		$this->assertSame([6, 6], [StreamVersions::head(), StreamVersions::floor()]);
	}

	public function testMainsWritersDispatchTheirChange(): void {
		$this->catalogue();
		$this->rDb->exec("INSERT INTO `profiles` (`profile_id`, `profile_name`, `profile_options`) VALUES (7, 'hd', '{}')");
		$this->rDb->exec('CREATE TABLE `watch_folders` (`id` INTEGER PRIMARY KEY, `transcode_profile_id` int)');
		$this->rDb->exec('CREATE TABLE `epg` (`id` INTEGER PRIMARY KEY, `epg_name` text)');
		$this->rDb->exec('CREATE TABLE `epg_channels` (`id` INTEGER PRIMARY KEY, `epg_id` int)');
		$this->rDb->exec("INSERT INTO `epg` VALUES (3, 'guide')");
		$this->seed();
		EventDispatcher::subscribe(StreamVersions::class);

		// Taken off a server: that server's row moves too (seeded, as every
		// holder's is), so its node sees the removal.
		StreamRepository::deleteStreamsByServer([10], 3);
		$this->assertSame(['2:10' => 2, '2:11' => 0, '3:10' => 2, '4:12' => 0, '5:12' => 0, '6:11' => 0], $this->rows());
		// Moved to another server: both nodes follow.
		StreamService::move(['content_type' => 0, 'source_server' => 2, 'replacement_server' => 8]);
		$this->assertSame(['2:10' => 3, '2:11' => 4, '3:10' => 3, '4:12' => 0, '5:12' => 0, '6:11' => 4, '8:10' => 3, '8:11' => 4], $this->rows(), 'every past holder too: a removal it already applied costs it nothing');
		// A profile deleted: its streams stop transcoding with it.
		$this->assertTrue(StreamConfigRepository::deleteProfile(7));
		$this->assertSame(5, $this->rows()['8:10']);
		// An EPG source deleted: its streams lose their guide.
		$this->assertTrue(EpgService::deleteEpgById(3));
		$this->assertSame(6, $this->rows()['8:10']);
		// A node finished a recording: it holds the VOD now.
		$this->rDb->exec('UPDATE `recordings` SET `created_id` = 20 WHERE `id` = 1');
		$this->rDb->exec("INSERT INTO `streams` (`id`, `type`, `stream_source`) VALUES (20, 2, '[]')");
		$this->assertTrue(RecordingFinalizer::finish(1, 6));
		$this->assertSame([8, 7], [$this->rows()['6:20'], $this->rows()['6:11']]);
	}

	public function testWhatNodesWriteBackNeverBumps(): void {
		$this->catalogue();
		EventDispatcher::subscribe(StreamVersions::class);
		StreamVersions::bump([10, 11, 12]);
		$rBefore = [$this->rows(), StreamVersions::head()];

		StreamRowMerge::mergeNode(2, 10, ['pid' => 100, 'stream_status' => 0, 'bitrate' => 5000, 'current_source' => 'http://a']);
		StreamStateWriter::useSink(null);
		StreamStateWriter::update(10, 2, ['monitor_pid' => 7, 'progress_info' => '{}']);
		ContentSink::recordingState(1, 1);
		ContentSink::workerPid(12, 'tv_archive', 55);
		ContentSink::movieProperties(10, ['duration' => '00:01:00']);

		$this->assertSame($rBefore, [$this->rows(), StreamVersions::head()]);
		// And none of the runtime columns is a field a record carries.
		$this->assertSame([], array_values(array_diff(StreamStateWriter::STATE_FIELDS, ReplicaSections::STREAM_SERVER_LOCAL)));
		$this->assertSame([], array_values(array_intersect(StreamStateWriter::STATE_FIELDS, array_keys(ReplicaSections::STREAM_SERVER_FIELDS))));
		$this->assertContains('tv_archive_pid', ReplicaSections::STREAM_LOCAL);
		$this->assertContains('vframes_pid', ReplicaSections::STREAM_LOCAL);
	}

	/**
	 * Every file that writes a stream's configuration dispatches its change
	 * (or stamps it itself). A new path fails here until it does, or is
	 * listed with why not.
	 */
	public function testEveryWriterOfAStreamsConfigurationDispatchesIt(): void {
		$rSrc = dirname(__DIR__, 2) . '/src';
		$rExempt = [
			// What the node reports: runtime state, worker pids, its recordings' status, its VOD analysis.
			'Domain/Stream/ContentSink.php', 'Domain/Stream/StreamRowMerge.php', 'Domain/Cluster/EventIngest.php',
			// MAIN's catalogue metadata from TMDb and providers: the agent's section hashes pick it up.
			'Domain/Vod/TmdbCron.php', 'Domain/Vod/TmdbPopularCron.php', 'Cli/CronJobs/ProvidersCronJob.php',
		];
		$rMissing = [];
		$rIt = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rSrc, FilesystemIterator::SKIP_DOTS));
		foreach ($rIt as $rFile) {
			$rPath = (string) $rFile;
			if (!str_ends_with($rPath, '.php') || str_contains($rPath, '/vendor/') || str_contains($rPath, '/bin/install/')) {
				continue;
			}
			$rRel = substr($rPath, strlen($rSrc) + 1);
			$rCode = (string) file_get_contents($rPath);
			if (!preg_match('/(INSERT INTO|REPLACE INTO|DELETE FROM|UPDATE|TRUNCATE)\s+`(streams|streams_servers|streams_options|streams_arguments|streams_types|profiles|recordings)`/', $rCode)) {
				continue;
			}
			if (in_array($rRel, $rExempt, true) || preg_match('/StreamsChangedEvent|StreamsDeletedEvent|TranscodeProfileSavedEvent|StreamArgumentsChangedEvent|StreamVersions::/', $rCode)) {
				continue;
			}
			$rMissing[] = $rRel;
		}
		$this->assertSame([], $rMissing, 'writes a stream\'s configuration without stamping its R2 version');
	}
}
