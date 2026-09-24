<?php

namespace XcVm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use XcVm\Cli\CronJobs\ActivityCronJob;
use XcVm\Cli\CronJobs\LinesLogsCronJob;
use XcVm\Core\Database\DatabaseHandler;

/** Records every statement; a multi-row lines_activity INSERT hands out consecutive ids from 100. */
class LogImportDb extends DatabaseHandler {
	public array $queries = [];
	public bool $fail = false;
	/** Runs once, on the first statement: stands in for a writer appending mid-import. */
	public ?\Closure $onFirstQuery = null;
	private int $nextID = 100;
	private int $lastID = 0;

	public function __construct() {
		$this->dbh = true;
	}

	public function query($query, $buffered = false) {
		if ($this->onFirstQuery) {
			[$rHook, $this->onFirstQuery] = [$this->onFirstQuery, null];
			$rHook();
		}
		$this->queries[] = $query;
		if ($this->fail) {
			return false;
		}
		if (str_starts_with($query, 'INSERT INTO `lines_activity`')) {
			$this->lastID = $this->nextID;
			$this->nextID += LogImportCronJobsTest::tuples($query);
		}
		return true;
	}

	public function escape($string) {
		return "'" . addslashes((string) $string) . "'";
	}

	public function last_insert_id() {
		return $this->lastID;
	}

	/** @return string[] The recorded statements that start with $rPrefix. */
	public function startingWith(string $rPrefix): array {
		return array_values(array_filter($this->queries, static fn($rQuery) => str_starts_with($rQuery, $rPrefix)));
	}
}

/**
 * The activity and client-request crons drain a base64(JSON)-per-line spool
 * into MySQL once a minute. Both parsers broke out of the read loop after the
 * first row (the activity one also stopped at the first invalid row), then the
 * whole spool was deleted, so each node kept one row a minute and lost the
 * rest. Every valid row must now be imported, in INSERTs of at most 1000 rows
 * or 4 MiB of spool, and only lines that still exist get their last activity.
 *
 * The unit under test is each job's importFile(), reached by reflection:
 * loadCron() only hands it the LOGS_TMP_PATH spool.
 */
class LogImportCronJobsTest extends TestCase {
	private const ACTIVITY_INSERT = 'INSERT INTO `lines_activity`';
	private const LINES_UPDATE = 'UPDATE `lines` SET ';
	private const LOGS_INSERT = 'INSERT INTO `lines_logs`';

	private LogImportDb $db;
	private string $dir;

	protected function setUp(): void {
		$this->db = new LogImportDb();
		ActivityCronJob::setDb($this->db);
		LinesLogsCronJob::setDb($this->db);
		$this->dir = sys_get_temp_dir() . '/xcvm_logimport_' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->dir, 0775, true);
	}

	protected function tearDown(): void {
		foreach (glob($this->dir . '*') ?: [] as $rFile) {
			unlink($rFile);
		}
		rmdir($this->dir);
		foreach ([ActivityCronJob::class, LinesLogsCronJob::class] as $rClass) {
			(new ReflectionProperty($rClass, 'db'))->setValue(null, null);
		}
	}

	/** Number of row tuples in a multi-row INSERT (test values never contain "),("). */
	public static function tuples(string $rQuery): int {
		return substr_count($rQuery, '),(') + 1;
	}

	private function import(string $rClass, string $rFile): int {
		$rM = new ReflectionMethod($rClass, 'importFile');
		$rM->setAccessible(true);
		return $rM->invoke(new $rClass(), $rFile);
	}

	private function spool(string $rName, array $rLines): string {
		file_put_contents($this->dir . $rName, implode("\n", $rLines) . "\n");
		return $this->dir . $rName;
	}

	private function activityRow(int $rUserID, array $rOverride = [], array $rUnset = []): string {
		return base64_encode(json_encode(array_diff_key(array_merge([
			'user_id' => $rUserID, 'stream_id' => 7, 'server_id' => 1, 'proxy_id' => 0, 'date_start' => 1700000000,
			'user_agent' => 'VLC/3.0', 'user_ip' => '192.0.2.' . ($rUserID % 250), 'date_end' => 1700000060, 'container' => 'ts',
			'geoip_country_code' => 'NL', 'isp' => 'Example ISP', 'external_device' => '', 'divergence' => 0, 'hmac_id' => null, 'hmac_identifier' => 'box',
		], $rOverride), array_flip($rUnset))));
	}

	private function logRow(int $rUserID, array $rOverride = [], array $rUnset = []): string {
		return base64_encode(json_encode(array_diff_key(array_merge([
			'user_id' => $rUserID, 'stream_id' => 5, 'action' => 'AUTH_FAILED', 'query_string' => 'q=' . $rUserID,
			'user_agent' => 'VLC', 'user_ip' => '192.0.2.' . ($rUserID % 250), 'time' => 1700000000, 'extra_data' => '{}',
		], $rOverride), array_flip($rUnset))));
	}

	/** @return array<int, int> The `lines` UPDATE, as line id => last_activity. */
	private function lastActivity(string $rQuery): array {
		preg_match('/`last_activity` = CASE `id`(.*?) END/', $rQuery, $rCase);
		preg_match_all('/WHEN (\d+) THEN (\d+)/', $rCase[1], $rMatches);
		return array_combine(array_map('intval', $rMatches[1]), array_map('intval', $rMatches[2]));
	}

	public function testActivityImportsEveryValidRow(): void {
		$rFile = $this->spool('activity', [
			$this->activityRow(13),
			'',
			$this->activityRow(99, ['user_ip' => '']),
			$this->activityRow(12, [], ['hmac_identifier']),
			'not-base64 !!!',
			$this->activityRow(98, ['server_id' => 0]),
			$this->activityRow(13, ['user_ip' => '192.0.2.113', 'stream_id' => 8]),
			$this->activityRow(11),
		]);

		$this->assertSame(4, $this->import(ActivityCronJob::class, $rFile));

		$rInserts = $this->db->startingWith(self::ACTIVITY_INSERT);
		$this->assertCount(1, $rInserts);
		$this->assertSame(4, self::tuples($rInserts[0]));
		$this->assertStringContainsString("('1','0','11','Example ISP','','7','1700000000','VLC/3.0','192.0.2.11','1700000060','ts','NL','0','','box')", $rInserts[0]);
		$this->assertStringContainsString("('1','0','12','Example ISP','','7','1700000000','VLC/3.0','192.0.2.12','1700000060','ts','NL','0','','')", $rInserts[0], 'a missing key imports as empty');

		// One UPDATE, never an INSERT into `lines`: each line once, in id order, with its latest row (13 closed twice).
		$rArray7 = '\'{\"date_end\":1700000060,\"stream_id\":7}\'';
		$rArray8 = '\'{\"date_end\":1700000060,\"stream_id\":8}\'';
		$this->assertSame(
			["UPDATE `lines` SET `last_ip` = CASE `id` WHEN 11 THEN '192.0.2.11' WHEN 12 THEN '192.0.2.12' WHEN 13 THEN '192.0.2.113' END, "
				. '`last_activity` = CASE `id` WHEN 11 THEN 103 WHEN 12 THEN 101 WHEN 13 THEN 102 END, '
				. '`last_activity_array` = CASE `id` WHEN 11 THEN ' . $rArray7 . ' WHEN 12 THEN ' . $rArray7 . ' WHEN 13 THEN ' . $rArray8 . ' END, '
				. '`updated` = `updated` WHERE `id` IN (11,12,13);'],
			$this->db->startingWith(self::LINES_UPDATE)
		);
		$this->assertCount(2, $this->db->queries);
		$this->assertFileDoesNotExist($rFile);
	}

	public function testActivityChunksAt1000(): void {
		$rFile = $this->spool('activity', array_map(fn($i) => $this->activityRow($i), range(1, 2500)));

		$this->assertSame(2500, $this->import(ActivityCronJob::class, $rFile));

		$this->assertSame([1000, 1000, 500], array_map([self::class, 'tuples'], $this->db->startingWith(self::ACTIVITY_INSERT)));
		$rUpdates = $this->db->startingWith(self::LINES_UPDATE);
		$this->assertCount(3, $rUpdates);
		foreach ([[100, 1099], [1100, 2099], [2100, 2599]] as $i => [$rFirst, $rLast]) {
			$this->assertSame(array_combine(range($rFirst - 99, $rLast - 99), range($rFirst, $rLast)), $this->lastActivity($rUpdates[$i]), 'batch ' . $i . ' ids line up with its rows');
		}
	}

	public function testActivityFailedInsertDropsTheRows(): void {
		$this->db->fail = true;
		$rFile = $this->spool('activity', [$this->activityRow(11), $this->activityRow(12)]);

		$this->assertSame(0, $this->import(ActivityCronJob::class, $rFile));

		$this->assertCount(1, $this->db->startingWith(self::ACTIVITY_INSERT));
		$this->assertSame([], $this->db->startingWith(self::LINES_UPDATE), 'no ids to update without the insert');
		$this->assertFileDoesNotExist($rFile);
	}

	public function testLinesLogsImportsEveryRowAndSkipsGarbage(): void {
		$rFile = $this->spool('client_request.log', [
			$this->logRow(11),
			'not-base64 !!!',
			base64_encode('42'),
			$this->logRow(12),
			'',
			$this->logRow(13, [], ['extra_data']),
		]);

		$this->assertSame(3, $this->import(LinesLogsCronJob::class, $rFile));

		$rInserts = $this->db->startingWith(self::LOGS_INSERT);
		$this->assertCount(1, $rInserts);
		$this->assertSame(3, self::tuples($rInserts[0]));
		$this->assertStringContainsString("('5','11','AUTH_FAILED','q=11','VLC','192.0.2.11','{}','1700000000')", $rInserts[0]);
		$this->assertStringContainsString("('5','13','AUTH_FAILED','q=13','VLC','192.0.2.13','','1700000000')", $rInserts[0], 'a missing key imports as empty');
		$this->assertFileDoesNotExist($rFile, 'a bad line does not wedge the spool');
	}

	public function testLinesLogsChunksAt1000(): void {
		$rFile = $this->spool('client_request.log', array_map(fn($i) => $this->logRow($i), range(1, 2500)));

		$this->assertSame(2500, $this->import(LinesLogsCronJob::class, $rFile));

		$this->assertSame([1000, 1000, 500], array_map([self::class, 'tuples'], $this->db->startingWith(self::LOGS_INSERT)));
	}

	public function testLinesLogsFailedInsertDropsTheRows(): void {
		$this->db->fail = true;
		$rFile = $this->spool('client_request.log', [$this->logRow(11), $this->logRow(12)]);

		$this->assertSame(0, $this->import(LinesLogsCronJob::class, $rFile));

		$this->assertCount(1, $this->db->startingWith(self::LOGS_INSERT));
		$this->assertFileDoesNotExist($rFile);
	}

	public function testOversizedRowsSplitTheBatch(): void {
		// 1 MB user agents: about 1.33 MB of spool a row, so 4 rows pass the 4 MiB budget.
		$rAgent = str_repeat('x', 1000000);
		foreach ([ActivityCronJob::class => [self::ACTIVITY_INSERT, 'activity', 'activityRow'], LinesLogsCronJob::class => [self::LOGS_INSERT, 'client_request.log', 'logRow']] as $rClass => [$rInsert, $rName, $rRow]) {
			$rFile = $this->spool($rName, array_map(fn($i) => $this->$rRow($i, ['user_agent' => $rAgent]), range(1, 10)));

			$this->assertSame(10, $this->import($rClass, $rFile), $rClass);

			$rInserts = $this->db->startingWith($rInsert);
			$this->assertSame([4, 4, 2], array_map([self::class, 'tuples'], $rInserts), $rClass);
			foreach ($rInserts as $rQuery) {
				$this->assertLessThan(8 * 1024 * 1024, strlen($rQuery), $rClass . ' stays far below max_allowed_packet');
			}
		}
	}

	public function testSpoolIsClaimedAndRemoved(): void {
		foreach ([ActivityCronJob::class => [self::ACTIVITY_INSERT, 'activity', 'activityRow'], LinesLogsCronJob::class => [self::LOGS_INSERT, 'client_request.log', 'logRow']] as $rClass => [$rInsert, $rName, $rRow]) {
			// A claimed spool left behind by a run that died mid-import goes first.
			$this->spool($rName . '.import', [$this->$rRow(1), $this->$rRow(2)]);
			$rFile = $this->spool($rName, [$this->$rRow(3), $this->$rRow(4), $this->$rRow(5)]);

			$this->assertSame(5, $this->import($rClass, $rFile), $rClass);

			$this->assertSame([2, 3], array_map([self::class, 'tuples'], $this->db->startingWith($rInsert)), $rClass);
			$this->assertFileDoesNotExist($rFile);
			$this->assertFileDoesNotExist($rFile . '.import');
		}
	}

	public function testRowsWrittenDuringTheImportWaitForTheNextRun(): void {
		foreach ([ActivityCronJob::class => ['activity', 'activityRow'], LinesLogsCronJob::class => ['client_request.log', 'logRow']] as $rClass => [$rName, $rRow]) {
			$rFile = $this->spool($rName, [$this->$rRow(1)]);
			$this->db->onFirstQuery = fn() => file_put_contents($rFile, $this->$rRow(2) . "\n", FILE_APPEND);

			$this->assertSame(1, $this->import($rClass, $rFile), $rClass);

			$this->assertSame([$this->$rRow(2)], file($rFile, FILE_IGNORE_NEW_LINES), $rClass . ' keeps the row for the next run');
			unlink($rFile);
		}
	}

	public function testMissingSpoolImportsNothing(): void {
		$this->assertSame(0, $this->import(ActivityCronJob::class, $this->dir . 'activity'));
		$this->assertSame(0, $this->import(LinesLogsCronJob::class, $this->dir . 'client_request.log'));
		$this->assertSame([], $this->db->queries);
	}
}
