<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\EventSpool;
use XcVm\Core\Cluster\LogSink;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\EventIngest;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Domain\Stream\StreamStateWriter;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * Phase 5, logs and stream state: on a node whose LOGS / STREAMS flow is on,
 * LogSink and StreamStateWriter spool redacted events for the agent instead
 * of writing MAIN's database; MAIN's EventIngest applies a node's batch in
 * order, at most once, and only to that node's own rows.
 */
final class ClusterEventsTest extends TestCase {
	private TestDb $rDb;

	private string $rDir;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec((string) preg_replace(['/^--.*$/m', '/,\s*(UNIQUE )?KEY `\w+` \([^)]*\)/', '/ unsigned| COLLATE \w+/', '/\) ENGINE=[^;]*;/'], ['', '', '', ');'], (string) file_get_contents(dirname(__DIR__, 2) . '/src/migrations/database/up/029_create_cluster_nodes.sql')));
		$this->rDb->exec('CREATE TABLE `cluster_audit` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `time` int, `server_id` int, `actor` varchar(64), `event` varchar(64), `detail` text, `ip` varchar(64))');
		$this->rDb->exec('CREATE TABLE `streams_servers` (`server_stream_id` INTEGER PRIMARY KEY, `stream_id` int, `server_id` int, `pid` int, `stream_status` int, `current_source` text, `on_demand` int DEFAULT 0)');
		$this->rDb->exec('CREATE TABLE `streams_logs` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `stream_id` int, `server_id` int, `action` varchar(500), `source` varchar(1024), `date` int)');
		$this->rDb->exec('CREATE TABLE `lines_logs` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `stream_id` int, `user_id` int, `client_status` varchar(255), `query_string` text, `user_agent` text, `ip` varchar(64), `extra_data` text, `date` int)');
		$this->rDb->query('INSERT INTO `streams_servers` (`server_stream_id`, `stream_id`, `server_id`, `pid`, `stream_status`) VALUES (11, 100, 5, 0, 0), (12, 100, 6, 0, 0)');
		DatabaseFactory::set($this->rDb);
		ClusterClock::fix(1800000000000);
		NodeRegistry::startEnrolment(5, '11111111-1111-4111-a111-111111111111', random_bytes(32), random_bytes(32), 1);
		NodeRegistry::update(5, ['state' => 'active', 'mode' => 1, 'flows' => NodeRegistry::FLOW_LOGS | NodeRegistry::FLOW_STREAMS]);

		$this->rDir = sys_get_temp_dir() . '/xcvm-events-' . bin2hex(random_bytes(4));
		mkdir($this->rDir);
		EventSpool::useDir($this->rDir . '/spool/');
	}

	protected function tearDown(): void {
		EventSpool::useDir(null);
		NodeFlows::usePath(null);
		ClusterClock::fix(null);
		DatabaseFactory::reset();
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	private function flows(int $rFlows, int $rAge = 0): void {
		file_put_contents($this->rDir . '/flows.json', json_encode(['mode' => 1, 'flows' => $rFlows, 'state' => 'active']));
		touch($this->rDir . '/flows.json', time() - $rAge);
		NodeFlows::usePath($this->rDir . '/flows.json');
	}

	/** @return list<array<string, mixed>> the spooled events of a lane, in file order */
	private function spooled(string $rLane): array {
		$rFiles = glob($this->rDir . '/spool/' . $rLane . '/*.ndjson') ?: [];
		sort($rFiles);
		$rOut = [];
		foreach ($rFiles as $rFile) {
			foreach (array_filter(explode("\n", (string) file_get_contents($rFile))) as $rLine) {
				$rOut[] = json_decode($rLine, true);
			}
		}
		return $rOut;
	}

	private function rows(string $rSql): array {
		$this->rDb->query($rSql);
		return $this->rDb->get_rows();
	}

	private function row(string $rSql): array {
		return $this->rows($rSql)[0] ?? [];
	}

	private function val(string $rSql): mixed {
		$rRow = $this->row($rSql);
		return $rRow === [] ? null : reset($rRow);
	}

	private function node(): array {
		return NodeRegistry::byServer(5);
	}

	// ── Node side ────────────────────────────────────────────────────────

	public function testLogsGoToTheSpoolRedactedWhenTheFlowIsOn(): void {
		$this->flows(NodeFlows::LOGS);
		$this->assertTrue(LogSink::write('client', [['stream_id' => 1, 'user_id' => 2, 'query_string' => 'username=alice&password=s3cret', 'ip' => '1.2.3.4', 'date' => 7, 'bogus' => 'x']]));
		$this->assertSame(0, (int) $this->val('SELECT COUNT(*) FROM `lines_logs`'), 'MAIN\'s database is not written');
		$rEvents = $this->spooled('p1');
		$this->assertCount(1, $rEvents);
		$this->assertSame('log.client', $rEvents[0]['type']);
		$rRow = $rEvents[0]['d']['rows'][0];
		$this->assertSame('username=***&password=***', $rRow['query_string']);
		$this->assertArrayNotHasKey('bogus', $rRow, 'only the type\'s columns');
		$this->assertSame([], glob($this->rDir . '/spool/p1/.*.tmp') ?: [], 'no half-written file is left');
	}

	public function testLogsFallBackToSqlWhenTheFlowIsOffOrTheAgentStopped(): void {
		$this->flows(NodeFlows::STREAMS);
		LogSink::write('stream', [['stream_id' => 1, 'server_id' => 5, 'action' => 'start', 'source' => 'http://u:p@src/1', 'date' => 1]]);
		$this->flows(NodeFlows::LOGS, EventSpool::STALE_AFTER + 30);
		LogSink::write('stream', [['stream_id' => 1, 'server_id' => 5, 'action' => 'stop', 'source' => '', 'date' => 2]]);
		$this->assertSame(2, (int) $this->val('SELECT COUNT(*) FROM `streams_logs`'));
		$this->assertSame([], $this->spooled('p1'));
	}

	public function testStreamStateGoesToTheSpoolWhenTheFlowIsOn(): void {
		$this->flows(NodeFlows::STREAMS);
		$this->assertTrue(StreamStateWriter::update(100, 5, ['pid' => 42, 'current_source' => 'http://bob:pw@origin/live/bob/pw/1.ts']));
		$this->assertTrue(StreamStateWriter::updateRow(11, ['stream_status' => 1]));
		$this->assertSame(0, (int) $this->val('SELECT `pid` FROM `streams_servers` WHERE `server_stream_id` = 11'));
		$rEvents = $this->spooled('p0');
		$this->assertSame(['stream.state', 'stream.state'], array_column($rEvents, 'type'));
		$this->assertSame(['stream_id' => 100, 'server_id' => 5], array_intersect_key($rEvents[0]['d'], ['stream_id' => 1, 'server_id' => 1]));
		$this->assertStringNotContainsString('pw', $rEvents[0]['d']['fields']['current_source']);
		$this->assertSame(11, $rEvents[1]['d']['ssid']);
	}

	public function testStreamStateStaysSqlOnALegacyNode(): void {
		$this->flows(0);
		StreamStateWriter::update(100, 5, ['pid' => 42]);
		$this->assertSame(42, (int) $this->val('SELECT `pid` FROM `streams_servers` WHERE `server_stream_id` = 11'));
		$this->assertSame([], $this->spooled('p0'));
	}

	// ── MAIN side ────────────────────────────────────────────────────────

	public function testP0IsGapCheckedAndAppliedOnce(): void {
		$rState = static fn(int $rPid) => ['type' => 'stream.state', 'd' => ['stream_id' => 100, 'server_id' => 5, 'fields' => ['pid' => $rPid]]];
		$this->assertSame(['ok' => false, 'useq' => 0, 'expected_useq' => 1], EventIngest::ingest($this->node(), 'p0', 3, [$rState(1)]));

		$rOut = EventIngest::ingest($this->node(), 'p0', 1, [$rState(1), $rState(2)]);
		$this->assertSame([true, 2, 2, 0], [$rOut['ok'], $rOut['useq'], $rOut['applied'], $rOut['dropped']]);
		$this->assertSame(2, (int) $this->val('SELECT `pid` FROM `streams_servers` WHERE `server_stream_id` = 11'));

		// The same batch again (its reply was lost): nothing is applied twice.
		$this->rDb->query('UPDATE `streams_servers` SET `pid` = 9 WHERE `server_stream_id` = 11');
		$this->assertSame(0, EventIngest::ingest($this->node(), 'p0', 1, [$rState(1), $rState(2)])['applied']);
		$this->assertSame(9, (int) $this->val('SELECT `pid` FROM `streams_servers` WHERE `server_stream_id` = 11'));
		$this->assertSame(2, (int) $this->node()['useq_p0']);
	}

	public function testANodeWritesOnlyItsOwnRowsAndStateColumns(): void {
		$rOut = EventIngest::ingest($this->node(), 'p0', 1, [
			['type' => 'stream.state', 'd' => ['ssid' => 12, 'fields' => ['pid' => 66]]],                        // node 6's row
			['type' => 'stream.state', 'd' => ['stream_id' => 100, 'server_id' => 6, 'fields' => ['pid' => 66]]], // ditto
			['type' => 'stream.state', 'd' => ['stream_id' => 100, 'fields' => ['on_demand' => 1]]],              // desired state
			['type' => 'stream.state', 'd' => ['ssid' => 11, 'fields' => ['pid' => 7, 'on_demand' => 1]]],
			['type' => 'log.stream', 'd' => ['rows' => [['stream_id' => 1]]]],                                    // wrong lane
		]);
		$this->assertSame(5, $rOut['useq']);
		$this->assertSame(0, (int) $this->val('SELECT `pid` FROM `streams_servers` WHERE `server_stream_id` = 12'));
		$this->assertSame([7, 0], array_map('intval', array_values($this->row('SELECT `pid`, `on_demand` FROM `streams_servers` WHERE `server_stream_id` = 11'))));
		$this->assertSame(0, (int) $this->val('SELECT COUNT(*) FROM `streams_logs`'));
	}

	public function testP1SkipsWhatWasAppliedAndForcesTheSendersServerID(): void {
		$rLog = static fn(string $rAction) => ['type' => 'log.stream', 'd' => ['rows' => [['stream_id' => 1, 'server_id' => 99, 'action' => $rAction, 'source' => 'http://u:p@src/x', 'date' => 1]]]];
		EventIngest::ingest($this->node(), 'p1', 1, [$rLog('a'), $rLog('b')]);
		// Overlaps what was applied, after a gap (the node dropped 3..4): fine on P1.
		$rOut = EventIngest::ingest($this->node(), 'p1', 2, [$rLog('b'), $rLog('c')]);
		$this->assertSame([3, 1], [$rOut['useq'], $rOut['applied']]);
		$this->assertSame(7, EventIngest::ingest($this->node(), 'p1', 6, [$rLog('d'), ['type' => 'skip', 'd' => ['count' => 2]]])['useq']);
		$rRows = $this->rows('SELECT `action`, `server_id`, `source` FROM `streams_logs` ORDER BY `id`');
		$this->assertSame(['a', 'b', 'c', 'd'], array_column($rRows, 'action'));
		$this->assertSame([5], array_values(array_unique(array_map('intval', array_column($rRows, 'server_id')))));
		$this->assertSame('http://***@src/x', $rRows[0]['source']);
		$this->assertSame(1, (int) $this->val("SELECT COUNT(*) FROM `cluster_audit` WHERE `event` = 'events.skip'"));
	}

	public function testAFlowThatIsOffRefusesItsEvents(): void {
		NodeRegistry::update(5, ['flows' => NodeRegistry::FLOW_STREAMS]);
		$rOut = EventIngest::ingest($this->node(), 'p1', 1, [['type' => 'log.stream', 'd' => ['rows' => [['stream_id' => 1, 'action' => 'x']]]], ['type' => 'nope', 'd' => []]]);
		$this->assertSame([2, 0, 2], [$rOut['useq'], $rOut['applied'], $rOut['dropped']]);
		$this->assertSame(0, (int) $this->val('SELECT COUNT(*) FROM `streams_logs`'));
	}
}
