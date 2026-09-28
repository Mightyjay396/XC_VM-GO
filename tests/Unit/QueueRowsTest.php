<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\AgentClient;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Core\Cluster\QueueSink;
use XcVm\Core\Cluster\ReplicaBoot;
use XcVm\Core\Database\DatabaseHandler;
use XcVm\Domain\Cluster\NodeQueue;

/** Every statement it was asked; statements matching $rRefuse fail (false). */
class QueueRowsDb extends DatabaseHandler {
	/** @var list<array{string, list<mixed>}> */
	public array $rQueries = [];

	/**
	 * @param list<array<string, mixed>> $rRows Rows every SELECT returns.
	 */
	public function __construct(private array $rRows = [], public ?string $rRefuse = null) {
		$this->dbh = true;
	}

	public function query($query, ...$args): bool {
		$this->rQueries[] = [(string) $query, $args];
		return $this->rRefuse === null || !preg_match($this->rRefuse, (string) $query);
	}

	public function get_rows($use_id = false, $column_as_id = '', $unique_row = true, $sub_row_id = '') {
		return $this->rRows;
	}
}

/**
 * The encoding queue's SQL, which both halves run (QueueSink's *Rows()): a
 * node over its own connection, MAIN for the node the cluster API
 * authenticated (NodeQueue). What each half answers from it stays its own.
 */
final class QueueRowsTest extends TestCase {
	private string $rDir;

	public static function setUpBeforeClass(): void {
		if (!defined('SERVER_ID')) {
			define('SERVER_ID', 5);
		}
	}

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-queue-rows-' . getmypid();
		@mkdir($this->rDir, 0o777, true);
		NodeRole::useMainBuild(false);
		AgentClient::useSocket($this->rDir . '/absent.sock');
		AgentClient::useSleep(static function (int $rSec): void {
		});
	}

	protected function tearDown(): void {
		(new ReflectionProperty(NodeQueue::class, 'db'))->setValue(null, null);
		NodeFlows::usePath(null);
		NodeRole::useMainBuild(null);
		AgentClient::useSocket(null);
		AgentClient::useSleep(null);
		ReplicaBoot::reset();
		array_map('unlink', glob($this->rDir . '/*') ?: []);
		@rmdir($this->rDir);
	}

	public function testIdsArePositiveIntsEachOnce(): void {
		$this->assertSame([7, 9, 3], QueueSink::ids([7, '7', 0, -1, '9', 'x', 3, 9]));
		$this->assertSame([], QueueSink::ids([]));
	}

	public function testInsertRowsReportsTheNewRowsAndTheInsertsOutcome(): void {
		$rDb = new QueueRowsDb();
		$this->assertSame([2, true], QueueSink::insertRows($rDb, 9, 'movie', [7, 8]));
		$this->assertSame('DELETE FROM `queue` WHERE `stream_id` IN (?,?) AND `server_id` = ?;', $rDb->rQueries[0][0]);
		$this->assertSame([7, 8, 9], $rDb->rQueries[0][1]);
		$this->assertStringStartsWith('INSERT INTO `queue`', $rDb->rQueries[1][0]);

		$rDb = new QueueRowsDb([], '/^INSERT/');
		$this->assertSame([2, false], QueueSink::insertRows($rDb, 9, 'movie', [7, 8]));

		// A channel already queued is left alone: nothing new, nothing inserted.
		$rDb = new QueueRowsDb([['stream_id' => 7]]);
		$this->assertSame([0, true], QueueSink::insertRows($rDb, 9, 'channel', [7]));
		$this->assertCount(1, $rDb->rQueries);
		$rDb = new QueueRowsDb([['stream_id' => 7]]);
		$this->assertSame([1, true], QueueSink::insertRows($rDb, 9, 'channel', [7, 8]));
		$this->assertSame(['channel', 8, 9], array_slice($rDb->rQueries[1][1], 0, 3));
	}

	public function testClaimRowsIsKeyedToTheServerAndCapped(): void {
		$rDb = new QueueRowsDb([['id' => 3, 'pid' => 900, 'stream_id' => 7]]);
		$rOut = QueueSink::claimRows($rDb, 9, 'channel', 1000);
		$this->assertSame(['running' => [['id' => 3, 'pid' => 900]], 'pending' => [['id' => 3, 'stream_id' => 7]]], $rOut);
		$this->assertSame([9, 'channel'], $rDb->rQueries[0][1]);
		$this->assertStringContainsString('LIMIT ' . QueueSink::MAX_CLAIM . ';', $rDb->rQueries[1][0]);
		$this->assertSame([9, 'channel'], $rDb->rQueries[1][1]);

		// A read that fails is no rows.
		$rDb = new QueueRowsDb([['id' => 3, 'pid' => 900, 'stream_id' => 7]], '/^SELECT/');
		$this->assertSame(['running' => [], 'pending' => []], QueueSink::claimRows($rDb, 9, 'movie', -4));
		$this->assertCount(1, $rDb->rQueries, 'a limit below 0 still asked for pending rows');
	}

	public function testUpdateRowsCountsWhatWasRecordedAndNamedToDrop(): void {
		$rDb = new QueueRowsDb();
		$this->assertSame(4, QueueSink::updateRows($rDb, 9, [3 => 900, 0 => 1, 4 => 0, 6 => '12'], [5, '5', -1, 8]));
		$this->assertSame([
			['UPDATE `queue` SET `pid` = ? WHERE `id` = ? AND `server_id` = ?;', [900, 3, 9]],
			['UPDATE `queue` SET `pid` = ? WHERE `id` = ? AND `server_id` = ?;', [12, 6, 9]],
			['DELETE FROM `queue` WHERE `id` IN (5,8) AND `server_id` = ?;', [9]],
		], $rDb->rQueries);

		// A pid the database refused is not counted; a drop is counted as named.
		$rDb = new QueueRowsDb([], '/^(UPDATE|DELETE)/');
		$this->assertSame(1, QueueSink::updateRows($rDb, 9, [3 => 900], [5]));
		$this->assertSame(0, QueueSink::updateRows(new QueueRowsDb(), 9, [], []));
	}

	/**
	 * The two halves answer a refused INSERT differently, and did before the
	 * SQL was shared: the node's path says it was not queued, MAIN's op
	 * counts the rows that were new.
	 */
	public function testEachHalfKeepsItsOwnAnswerToARefusedInsert(): void {
		$rDb = new QueueRowsDb([], '/^INSERT/');
		$this->assertFalse(QueueSink::enqueue('movie', [7, 8], 9, $rDb));

		$rDb = new QueueRowsDb([], '/^INSERT/');
		NodeQueue::setDb($rDb);
		$this->assertSame(2, NodeQueue::enqueue(9, 'movie', [7, '8', 0]));
		$this->assertSame([7, 8, 9], $rDb->rQueries[0][1]);
	}

	public function testNodeQueueRunsTheSameStatementsForTheAuthenticatedNode(): void {
		$rDb = new QueueRowsDb([['id' => 3, 'pid' => 900, 'stream_id' => 7]]);
		NodeQueue::setDb($rDb);

		$this->assertSame(0, NodeQueue::enqueue(9, 'nope', [7]));
		$this->assertSame(0, NodeQueue::enqueue(9, 'movie', [0, -2]));
		$this->assertSame([], $rDb->rQueries);

		$this->assertSame(['running' => [['id' => 3, 'pid' => 900]], 'pending' => [['id' => 3, 'stream_id' => 7]]], NodeQueue::claim(9, 'movie', 5));
		$this->assertSame(2, NodeQueue::update(9, [3 => 900], [4]));
		$rServers = array_map(static fn(array $rQuery): mixed => end($rQuery[1]), array_slice($rDb->rQueries, 2));
		$this->assertSame([9, 9], $rServers);

		// The node's own path runs the same statements, keyed to SERVER_ID.
		$rSink = new QueueRowsDb([['id' => 3, 'pid' => 900, 'stream_id' => 7]]);
		QueueSink::claim('movie', 5, $rSink);
		QueueSink::update([3 => 900], [4], $rSink);
		$rMain = array_map(static fn(array $rQuery): string => $rQuery[0], $rDb->rQueries);
		$this->assertSame($rMain, array_map(static fn(array $rQuery): string => $rQuery[0], $rSink->rQueries));
		$this->assertSame((int) SERVER_ID, end($rSink->rQueries[3][1]));
	}
}
