<?php

namespace XcVm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use XcVm\Cli\CronJobs\ActivityCronJob;
use XcVm\Cli\CronJobs\LinesLogsCronJob;
use XcVm\Core\Database\DatabaseHandler;

/** Records every statement; a multi-row lines_activity INSERT hands out consecutive ids from 100. */
class LogImportDb extends DatabaseHandler {
	public array $queries = [];
	public bool $fail = false;
	private int $nextID = 100;
	private int $lastID = 0;

	public function __construct() {
		$this->dbh = true;
	}

	public function query($query, $buffered = false) {
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
 * rest. Every valid row must now be imported, in INSERTs of at most 1000 rows.
 */
class LogImportCronJobsTest extends TestCase {
	private const ACTIVITY_INSERT = 'INSERT INTO `lines_activity`';
	private const LINES_UPSERT = 'INSERT INTO `lines`(';
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

	private function activityRow(int $rUserID, array $rOverride = []): string {
		return base64_encode(json_encode(array_merge([
			'user_id' => $rUserID, 'stream_id' => 7, 'server_id' => 1, 'proxy_id' => 0, 'date_start' => 1700000000,
			'user_agent' => 'VLC/3.0', 'user_ip' => '192.0.2.' . ($rUserID % 250), 'date_end' => 1700000060, 'container' => 'ts',
			'geoip_country_code' => 'NL', 'isp' => 'Example ISP', 'external_device' => '', 'divergence' => 0, 'hmac_id' => null, 'hmac_identifier' => '',
		], $rOverride)));
	}

	private function logRow(int $rUserID): string {
		return base64_encode(json_encode([
			'user_id' => $rUserID, 'stream_id' => 5, 'action' => 'AUTH_FAILED', 'query_string' => 'q=' . $rUserID,
			'user_agent' => 'VLC', 'user_ip' => '192.0.2.' . ($rUserID % 250), 'time' => 1700000000, 'extra_data' => '',
		]));
	}

	/** @return array<int, array{0:int, 1:string}> The `lines` upsert, as last_activity id => [line id, last_ip]. */
	private function upserted(string $rQuery): array {
		preg_match_all("/\\((\\d+),'([^']*)',(\\d+),/", $rQuery, $rMatches, PREG_SET_ORDER);
		$rRows = [];
		foreach ($rMatches as $rMatch) {
			$rRows[(int) $rMatch[3]] = [(int) $rMatch[1], $rMatch[2]];
		}
		return $rRows;
	}

	public function testActivityImportsEveryValidRow(): void {
		$rFile = $this->spool('activity', [
			$this->activityRow(11),
			'',
			$this->activityRow(99, ['user_ip' => '']),
			$this->activityRow(12),
			'not-base64 !!!',
			$this->activityRow(13),
		]);

		$this->assertSame(3, $this->import(ActivityCronJob::class, $rFile));

		$rInserts = $this->db->startingWith(self::ACTIVITY_INSERT);
		$this->assertCount(1, $rInserts);
		$this->assertSame(3, self::tuples($rInserts[0]));
		$this->assertStringContainsString("('1','0','11','Example ISP','','7','1700000000','VLC/3.0','192.0.2.11','1700000060','ts','NL','0','','')", $rInserts[0]);

		$rUpserts = $this->db->startingWith(self::LINES_UPSERT);
		$this->assertCount(1, $rUpserts);
		$this->assertSame([100 => [11, '192.0.2.11'], 101 => [12, '192.0.2.12'], 102 => [13, '192.0.2.13']], $this->upserted($rUpserts[0]));
	}

	public function testActivityChunksAt1000(): void {
		$rFile = $this->spool('activity', array_map(fn($i) => $this->activityRow($i), range(1, 2500)));

		$this->assertSame(2500, $this->import(ActivityCronJob::class, $rFile));

		$this->assertSame([1000, 1000, 500], array_map([self::class, 'tuples'], $this->db->startingWith(self::ACTIVITY_INSERT)));
		$rUpserts = $this->db->startingWith(self::LINES_UPSERT);
		$this->assertCount(3, $rUpserts);
		foreach ([[100, 1099], [1100, 2099], [2100, 2599]] as $i => [$rFirst, $rLast]) {
			$rRows = $this->upserted($rUpserts[$i]);
			$this->assertSame(range($rFirst, $rLast), array_keys($rRows));
			$this->assertSame($rFirst - 99, $rRows[$rFirst][0], 'batch ' . $i . ' ids line up with its rows');
		}
	}

	public function testActivityFailedInsertDropsTheRows(): void {
		$this->db->fail = true;
		$rFile = $this->spool('activity', [$this->activityRow(11), $this->activityRow(12)]);

		$this->assertSame(0, $this->import(ActivityCronJob::class, $rFile));

		$this->assertCount(1, $this->db->startingWith(self::ACTIVITY_INSERT));
		$this->assertSame([], $this->db->startingWith(self::LINES_UPSERT), 'no ids to upsert without the insert');
		$this->assertFileDoesNotExist($rFile);
	}

	public function testLinesLogsImportsEveryRowAndSkipsGarbage(): void {
		$rFile = $this->spool('client_request.log', [
			$this->logRow(11),
			'not-base64 !!!',
			base64_encode('42'),
			$this->logRow(12),
			'',
			$this->logRow(13),
		]);

		$this->assertSame(3, $this->import(LinesLogsCronJob::class, $rFile));

		$rInserts = $this->db->startingWith(self::LOGS_INSERT);
		$this->assertCount(1, $rInserts);
		$this->assertSame(3, self::tuples($rInserts[0]));
		$this->assertStringContainsString("('5','11','AUTH_FAILED','q=11','VLC','192.0.2.11','','1700000000')", $rInserts[0]);
	}

	public function testLinesLogsChunksAt1000(): void {
		$rFile = $this->spool('client_request.log', array_map(fn($i) => $this->logRow($i), range(1, 2500)));

		$this->assertSame(2500, $this->import(LinesLogsCronJob::class, $rFile));

		$this->assertSame([1000, 1000, 500], array_map([self::class, 'tuples'], $this->db->startingWith(self::LOGS_INSERT)));
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

	public function testMissingSpoolImportsNothing(): void {
		$this->assertSame(0, $this->import(ActivityCronJob::class, $this->dir . 'activity'));
		$this->assertSame(0, $this->import(LinesLogsCronJob::class, $this->dir . 'client_request.log'));
		$this->assertSame([], $this->db->queries);
	}
}
