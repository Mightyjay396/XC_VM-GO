<?php

use PHPUnit\Framework\TestCase;
use XcVm\Streaming\Fanout\FanoutClient;

/**
 * VOD and timeshift bytes handed to the xc_fanout daemon (its "files"
 * feature): the manifest a hand-over leaves for the daemon, and the feature
 * answer kept a minute so vod.php does not ask the daemon on every request.
 */
final class FanoutFileHandOverTest extends TestCase {
	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm_files_' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir . 'files', 0777, true);
		FanoutClient::useFilesPaths($this->rDir . 'files/', $this->rDir . 'fanout_features');
	}

	protected function tearDown(): void {
		FanoutClient::useFilesPaths(null);
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	public function testAHandOverLeavesTheDaemonAManifestAndNoPathInTheUrl(): void {
		$rParts = [['path' => '/home/xc_vm/content/vod/7.mp4', 'offset' => 0, 'length' => -1]];
		$this->assertTrue(FanoutClient::handOverFile(7, 'uuid-1', $rParts, 'video/mp4', 150, -5, 'bytes=10-', 1800000000));

		$rFiles = glob($this->rDir . 'files/*.json');
		$this->assertCount(1, $rFiles);
		$this->assertMatchesRegularExpression('#/[0-9a-f]{32}\.json$#', $rFiles[0], 'a random name, the only thing nginx is told');
		$this->assertSame(0600, fileperms($rFiles[0]) & 0777);
		$this->assertSame([
			'type' => 'video/mp4',
			'parts' => $rParts,
			'limit_perc' => 100,
			'rate' => 0,
			'range' => 'bytes=10-',
			'expires' => 1800000060,
		], json_decode((string) file_get_contents($rFiles[0]), true), 'the throttle clamped, the token\'s range kept, a minute to be read');
	}

	public function testNoManifestNoHandOver(): void {
		FanoutClient::useFilesPaths($this->rDir . 'missing/', $this->rDir . 'fanout_features');
		$this->assertFalse(FanoutClient::handOverFile(7, 'u', [['path' => '/x', 'offset' => 0, 'length' => -1]], 'video/mp4', 0, 0));
	}

	public function testTheFeatureAnswerIsKeptAMinute(): void {
		file_put_contents($this->rDir . 'fanout_features', json_encode(['at' => 1800000000, 'features' => ['remux', 'files']]));
		$this->assertTrue(FanoutClient::supportsFiles(1800000059));
		file_put_contents($this->rDir . 'fanout_features', json_encode(['at' => 1800000000, 'features' => ['remux']]));
		$this->assertFalse(FanoutClient::supportsFiles(1800000059), 'an older daemon: this worker serves the file');
		// Past the minute, the daemon is asked again; none here, so no.
		file_put_contents($this->rDir . 'fanout_features', json_encode(['at' => 1800000000, 'features' => ['files']]));
		$this->assertFalse(FanoutClient::supportsFiles(1800000061));
	}

	public function testADaemonThatFetchesSourcesSaysSo(): void {
		file_put_contents($this->rDir . 'fanout_features', json_encode(['at' => 1800000000, 'features' => ['files']]));
		$this->assertFalse(FanoutClient::supports('file_urls', 1800000001), 'files only: a direct proxy stays in PHP');
		file_put_contents($this->rDir . 'fanout_features', json_encode(['at' => 1800000000, 'features' => ['files', 'file_urls']]));
		$this->assertTrue(FanoutClient::supports('file_urls', 1800000001));

		$this->assertTrue(FanoutClient::handOverFile(9, 'u', [['url' => 'https://source.example/m.mp4', 'offset' => 0, 'length' => -1]], 'video/mp4', 0, 3145728, '', 1800000000));
		$rDoc = json_decode((string) file_get_contents(glob($this->rDir . 'files/*.json')[0]), true);
		$this->assertSame([['url' => 'https://source.example/m.mp4', 'offset' => 0, 'length' => -1]], $rDoc['parts']);
		$this->assertSame([0, 3145728], [$rDoc['limit_perc'], $rDoc['rate']], 'paced from the first byte');
	}
}
