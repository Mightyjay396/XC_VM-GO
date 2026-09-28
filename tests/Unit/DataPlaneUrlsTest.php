<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\DataPlane;
use XcVm\Core\Cluster\DataPlaneTrust;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Domain\Stream\StreamProcess;

/**
 * The URLs a node pulls with (ADR 0004, Phase 8): with its DATAPLANE flow on,
 * a relay and a file on another server go through the agent's loopback proxy
 * and no URL carries the stream secret (acceptance: `live_streaming_pass` in
 * no /proc/<pid>/cmdline and no `current_source`). A parent or owner that
 * cannot check a ticket (a legacy server) keeps the legacy URL, as does
 * every URL while the flow is off.
 */
final class DataPlaneUrlsTest extends TestCase {
	private const SECRET = 'the-stream-secret';
	private const KEY = 'kLoopbackKey0123456789abcdef';

	private string $rDir;

	/** @var array<int, array<string, mixed>> */
	private array $rServers;

	protected function setUp(): void {
		if (!defined('SERVER_ID')) {
			define('SERVER_ID', 1);
		}
		$this->rDir = sys_get_temp_dir() . '/xcvm-dp-' . bin2hex(random_bytes(4));
		mkdir($this->rDir);
		file_put_contents($this->rDir . '/relay.key', self::KEY . "\n");
		DataPlane::useKeyFile($this->rDir . '/relay.key');
		$this->flows(NodeFlows::STREAMS | NodeFlows::CONTENT | NodeFlows::DATAPLANE);
		// 20: MAIN; 21: an active node; 22: a legacy server no ticket can name.
		$rNode = static fn(int $rSid): array => ['sid' => $rSid, 'gen' => 1, 'state' => 'active', 'ed_pub' => str_repeat('x', 32), 'dataplane' => false];
		DataPlaneTrust::useSources(static fn(int $rSid): ?array => $rSid === 21 ? $rNode(21) : null, null, null, null, false);
		$rRow = static fn(int $rID, int $rMain = 0): array => [
			'is_main' => $rMain, 'private_url_ip' => null, 'public_url_ip' => 'http://10.0.0.' . $rID . ':8080/',
			'api_url' => 'http://10.0.0.' . $rID . ':8080/api?password=' . self::SECRET,
		];
		$this->rServers = [(int) SERVER_ID => $rRow((int) SERVER_ID), 20 => $rRow(20, 1), 21 => $rRow(21), 22 => $rRow(22)];
	}

	protected function tearDown(): void {
		NodeFlows::usePath(null);
		DataPlane::useKeyFile(null);
		DataPlaneTrust::useSources(null, null, null);
		foreach (glob($this->rDir . '/*') ?: [] as $rFile) {
			unlink($rFile);
		}
		rmdir($this->rDir);
	}

	private function flows(int $rFlows): void {
		file_put_contents($this->rDir . '/flows.json', json_encode(['mode' => 1, 'flows' => $rFlows, 'state' => 'active']));
		NodeFlows::usePath($this->rDir . '/flows.json');
	}

	public function testWithTheDataPlaneOnNoUrlCarriesTheSecret(): void {
		foreach ([20, 21] as $rParent) {
			$rURL = DataPlane::relayUrl($this->rServers, $rParent, 77, self::SECRET);
			$this->assertSame('http://127.0.0.1:31290/relay/' . self::KEY . '/77.ts', $rURL, 'parent ' . $rParent);
			$this->assertSame('http://127.0.0.1:31290/relay/' . self::KEY . '/77.ts?prebuffer=1', DataPlane::relayUrl($this->rServers, $rParent, 77, self::SECRET, true));
			$rFile = DataPlane::fileUrl($this->rServers, $rParent, '/media/movies/a film.mkv');
			$this->assertSame('http://127.0.0.1:31290/xfile/' . self::KEY . '/' . DataPlane::ref($rParent, '/media/movies/a film.mkv') . '.mkv', $rFile);
			$this->assertStringNotContainsString(self::SECRET, $rURL . $rFile);
			$this->assertStringNotContainsString('movies', $rFile, 'no path on the loopback either');
		}
		// The ref is stable: the URL an encoder holds outlives the ticket.
		$this->assertSame(DataPlane::ref(21, '/a.mkv'), DataPlane::ref(21, '/a.mkv'));
		$this->assertNotSame(DataPlane::ref(21, '/a.mkv'), DataPlane::ref(20, '/a.mkv'));
	}

	public function testTheStreamBuildersUseThem(): void {
		$rSubs = (new ReflectionMethod(StreamProcess::class, 'buildSubtitleImport'))->invoke(null, json_encode(['files' => ['/media/subs/a.srt'], 'names' => ['en'], 'charset' => ['UTF-8'], 'location' => 21]), $this->rServers);
		$this->assertStringContainsString('127.0.0.1:31290/xfile/', $rSubs[0]);
		$this->assertStringNotContainsString(self::SECRET, $rSubs[0]);
		[$rOwner, $rSource] = (new ReflectionMethod(StreamProcess::class, 'resolveChannelSource'))->invoke(null, 's:21:/media/does-not-exist-here.mkv', $this->rServers);
		$this->assertSame(21, $rOwner);
		$this->assertStringStartsWith('http://127.0.0.1:31290/xfile/', $rSource);

		// No builder writes the relay or getFile URL itself any more.
		$rRoot = dirname(__DIR__, 2) . '/src/';
		foreach (['Domain/Stream/StreamProcess.php', 'Cli/Commands/LoopbackCommand.php', 'Cli/Commands/MonitorCommand.php'] as $rFile) {
			$rCode = (string) file_get_contents($rRoot . $rFile);
			$this->assertStringNotContainsString("'admin/live?stream='", $rCode, $rFile);
			$this->assertStringNotContainsString("'&action=getFile", $rCode, $rFile);
		}
	}

	public function testALegacyServerOrTheFlowOffKeepsTheLegacyUrl(): void {
		$this->assertStringContainsString('password=' . self::SECRET, DataPlane::relayUrl($this->rServers, 22, 77, self::SECRET), 'a parent that checks no ticket');
		$this->assertStringContainsString('action=getFile', DataPlane::fileUrl($this->rServers, 22, '/a.mkv'));
		$this->flows(NodeFlows::STREAMS | NodeFlows::CONTENT);
		$this->assertSame('http://10.0.0.21:8080/admin/live?stream=77&password=' . self::SECRET . '&extension=ts', DataPlane::relayUrl($this->rServers, 21, 77, self::SECRET));
		$this->assertSame('http://10.0.0.21:8080/api?password=' . self::SECRET . '&action=getFile&filename=%2Fa.mkv', DataPlane::fileUrl($this->rServers, 21, '/a.mkv'));
	}
}
