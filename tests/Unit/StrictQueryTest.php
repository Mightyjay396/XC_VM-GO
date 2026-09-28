<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\StreamRecords;
use XcVm\Core\Cluster\StrictQuery;
use XcVm\Core\Database\DatabaseHandler;
use XcVm\Domain\Cluster\BlocklistDelta;
use XcVm\Domain\Cluster\ReplicaBuilder;
use XcVm\Domain\Cluster\StreamReplica;

/** Every statement it was asked, answered with $rResult as Database::query does. */
class StrictQueryDb extends DatabaseHandler {
	/** @var list<array{string, list<mixed>}> */
	public array $rQueries = [];

	public function __construct(public mixed $rResult = true) {
		$this->dbh = true;
	}

	public function query($query, ...$args): mixed {
		$this->rQueries[] = [(string) $query, $args];
		return $this->rResult;
	}

	public function get_rows($use_id = false, $column_as_id = '', $unique_row = true, $sub_row_id = '') {
		return [];
	}

	public function get_row() {
		// The previous statement's row, as a failed read leaves it.
		return ['hi' => 42, 'value' => '1:1'];
	}
}

/**
 * The one "run a statement, throw when it fails" of what MAIN signs (ADR
 * 0004, "Never from a failed read"), and the section each caller names.
 */
final class StrictQueryTest extends TestCase {
	/** The callers, each with its own injected database. */
	private const CALLERS = [StreamRecords::class, StreamReplica::class, BlocklistDelta::class, ReplicaBuilder::class];

	protected function tearDown(): void {
		foreach (self::CALLERS as $rClass) {
			(new ReflectionProperty($rClass, 'db'))->setValue(null, null);
		}
	}

	public function testAStatementThatRunsPassesItsArgumentsAndThrowsNothing(): void {
		$rDb = new StrictQueryDb();
		StrictQuery::run($rDb, 'streams', 'SELECT 1 WHERE `a` = ? AND `b` = ?;', 7, 'x');
		$this->assertSame([['SELECT 1 WHERE `a` = ? AND `b` = ?;', [7, 'x']]], $rDb->rQueries);
	}

	public function testOnlyFalseIsAFailure(): void {
		foreach ([0, null, '', []] as $rResult) {
			StrictQuery::run(new StrictQueryDb($rResult), 'streams', 'SELECT 1;');
		}
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('replica: a read failed');
		StrictQuery::run(new StrictQueryDb(false), 'replica', 'SELECT 1;');
	}

	public function testEachCallerNamesItsSection(): void {
		$rCases = [
			'streams (StreamRecords)' => [StreamRecords::class, static fn() => StreamRecords::held(5, null), 'streams: a read failed'],
			'streams (StreamReplica)' => [StreamReplica::class, static fn() => StreamReplica::prune(), 'streams: a read failed'],
			'blocklist' => [BlocklistDelta::class, static fn() => BlocklistDelta::head(), 'blocklist: a read failed'],
			'replica' => [ReplicaBuilder::class, static fn() => ReplicaBuilder::serversData(), 'replica: a read failed'],
		];
		foreach ($rCases as $rName => [$rClass, $rCall, $rMessage]) {
			$rDb = new StrictQueryDb(false);
			$rClass::setDb($rDb);
			try {
				$rCall();
				$this->fail($rName . ': a failed read did not throw');
			} catch (\RuntimeException $rE) {
				$this->assertSame($rMessage, $rE->getMessage(), $rName);
			}
			$this->assertCount(1, $rDb->rQueries, $rName . ': it read on after a failed read');
		}
	}
}
