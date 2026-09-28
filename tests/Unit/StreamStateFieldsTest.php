<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\EventSpool;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Core\Cluster\Redactor;
use XcVm\Core\Cluster\StreamRuntime;
use XcVm\Core\Cluster\StreamStateFields;
use XcVm\Domain\Stream\StreamRowMerge;
use XcVm\Domain\Stream\StreamStateWriter;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\AgentUser;
use XcVm\Tests\Support\InstallSchema;

/**
 * A stream's runtime state leaves the node, and lands in its store, with the
 * current source redacted and every other column as is: one helper, and every
 * path that carries the fields goes through it (the writer's event and its
 * resend, MAIN's merge of that event, a kept write, a seed from MAIN's rows).
 */
final class StreamStateFieldsTest extends TestCase {
	private const SOURCE = 'http://user:pass@src.example/live/bob/s3cret/1.ts?token=abc';

	private string $rDir;

	private int $rSid;

	private string $rRuntimeDir;

	protected function setUp(): void {
		if (!defined('SERVER_ID')) {
			define('SERVER_ID', 5);
		}
		$this->rSid = (int) SERVER_ID;
		$this->rDir = sys_get_temp_dir() . '/xcvm-statefields-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir . 'cluster/spool', 0777, true);
		AgentUser::own($this->rDir);
		EventSpool::useDir($this->rDir . 'cluster/spool/');
		$this->rRuntimeDir = StreamRuntime::dir();
		StreamRuntime::useDir($this->rDir . 'cluster/runtime/');
		NodeRole::useMainBuild(false);
		$rFile = $this->rDir . 'cluster/flows.json';
		file_put_contents($rFile, json_encode(['mode' => 1, 'flows' => NodeFlows::STREAMS | NodeFlows::CONTENT, 'state' => 'active']));
		NodeFlows::usePath($rFile);
		clearstatcache();
	}

	protected function tearDown(): void {
		StreamStateWriter::useSink(null);
		StreamRuntime::useDir($this->rRuntimeDir);
		EventSpool::useDir(null);
		NodeFlows::usePath(null);
		NodeRole::useMainBuild(null);
		DatabaseFactory::reset();
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/** @return list<array<string, mixed>> the spooled P0 events, in file order, and the spool emptied */
	private function drain(): array {
		$rFiles = glob($this->rDir . 'cluster/spool/p0/*.ndjson') ?: [];
		sort($rFiles);
		$rOut = [];
		foreach ($rFiles as $rFile) {
			foreach (array_filter(explode("\n", (string) file_get_contents($rFile))) as $rLine) {
				$rOut[] = json_decode($rLine, true);
			}
			unlink($rFile);
		}
		return $rOut;
	}

	// ── The helper ───────────────────────────────────────────────────

	public function testTheSourceAloneIsRedacted(): void {
		$rRedacted = Redactor::redact(self::SOURCE);
		$this->assertNotSame(self::SOURCE, $rRedacted);
		$this->assertSame($rRedacted, StreamStateFields::value('current_source', self::SOURCE));
		$this->assertSame(self::SOURCE, StreamStateFields::value('stream_info', self::SOURCE), 'another column is kept as is');
		$this->assertNull(StreamStateFields::value('current_source', null));
		$this->assertSame(7, StreamStateFields::value('current_source', 7), 'only a string is redacted');
		$this->assertSame(self::SOURCE, StreamStateFields::value(0, self::SOURCE));

		$this->assertSame(['pid' => 1, 'current_source' => $rRedacted, 'stream_info' => self::SOURCE], StreamStateFields::redact(['pid' => 1, 'current_source' => self::SOURCE, 'stream_info' => self::SOURCE]), 'keys and order kept');
		$this->assertSame(['pid' => 1, 'current_source' => null], StreamStateFields::redact(['pid' => 1, 'current_source' => null]));
		$this->assertSame(['pid' => 1], StreamStateFields::redact(['pid' => 1]), 'no source is added');
		$this->assertSame(['current_source' => $rRedacted], StreamStateFields::redact(StreamStateFields::redact(['current_source' => self::SOURCE])), 'idempotent');
	}

	public function testPickDropsWhatItDoesNotNameAndRedacts(): void {
		$this->assertSame(
			['pid' => 5, 'current_source' => Redactor::redact(self::SOURCE)],
			StreamStateFields::pick(['server_id' => 3, 'pid' => 5, 'parent_id' => 2, 'current_source' => self::SOURCE], ['pid', 'current_source', 'bitrate'])
		);
		$this->assertSame([], StreamStateFields::pick(['server_id' => 3], ['pid']));
	}

	// ── Every path that carries the fields ───────────────────────────

	public function testEveryPathRedactsTheCurrentSource(): void {
		$rRedacted = Redactor::redact(self::SOURCE);

		// A seed copies MAIN's rows into the node's store.
		$rDb = new TestDb();
		$rDb->exec(InstallSchema::table('streams_servers'));
		$rDb->exec(InstallSchema::table('streams'));
		$rDb->query('INSERT INTO `streams_servers` (`server_stream_id`, `stream_id`, `server_id`, `pid`, `current_source`) VALUES (1, 10, ?, 4242, ?)', $this->rSid, self::SOURCE);
		$this->assertTrue(StreamRuntime::seed($rDb), 'seeded');
		$this->assertSame($rRedacted, StreamRuntime::get(10)['current_source'], 'seed');

		// A write with STREAMS on: its event, and what the store keeps.
		$this->assertTrue(StreamStateWriter::update(20, $this->rSid, ['pid' => 7, 'current_source' => self::SOURCE]));
		$rEvents = $this->drain();
		$this->assertSame(['stream_id' => 20, 'server_id' => $this->rSid, 'fields' => ['pid' => 7, 'current_source' => $rRedacted]], $rEvents[0]['d'], 'the writer\'s event');
		$this->assertSame($rRedacted, StreamRuntime::get(20)['current_source'], 'the store');

		// MAIN's merge of a node's event.
		$this->assertSame(['pid' => 7, 'current_source' => $rRedacted], StreamRowMerge::eventFields(['pid' => 7, 'server_id' => 9, 'current_source' => self::SOURCE]), 'MAIN\'s merge');

		// resend() of an entry the store holds unredacted (written before the store redacted).
		$rStore = $this->rDir . 'cluster/runtime/';
		file_put_contents($rStore . 'streams/30.json', json_encode(['id' => 30, 'ssid' => null, 'fields' => ['pid' => 8, 'current_source' => self::SOURCE], 'unsent' => ['pid', 'current_source']]));
		file_put_contents($rStore . 'unsent', json_encode(['at' => time(), 'token' => 'x']));
		AgentUser::own($rStore);
		$this->assertSame(1, StreamStateWriter::resend());
		$rEvents = $this->drain();
		$this->assertSame(['stream_id' => 30, 'server_id' => $this->rSid, 'fields' => ['pid' => 8, 'current_source' => $rRedacted]], $rEvents[0]['d'], 'resend()');
	}
}
