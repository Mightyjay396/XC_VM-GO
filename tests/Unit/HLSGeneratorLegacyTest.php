<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Util\Encryption;
use XcVm\Streaming\Delivery\HLSGenerator;

/**
 * HLSGenerator::generateHLS — the pre-fanout client playlist, built from the
 * on-disk playlist while fanout is switched off.
 */
class HLSGeneratorLegacyTest extends TestCase {
	private string $rDir;
	private const PASS = 'test-live-pass';

	protected function setUp(): void {
		if (!defined('SERVER_ID')) {
			define('SERVER_ID', 1);
		}
		if (!defined('STREAMS_PATH')) {
			define('STREAMS_PATH', sys_get_temp_dir() . '/xcvm-hls-legacy-test/');
		}
		if (!is_dir(STREAMS_PATH)) {
			mkdir(STREAMS_PATH, 0777, true);
		}
		$this->rDir = STREAMS_PATH;
	}

	protected function tearDown(): void {
		@unlink($this->rDir . '9901_.m3u8');
		@unlink($this->rDir . '9901_.iv');
	}

	private function settings(array $rOver = []): array {
		return array_merge(['live_streaming_pass' => self::PASS, 'encrypt_hls' => 0, 'allow_cdn_access' => 0, 'secure_stream_tokens' => 0], $rOver);
	}

	private function playlist(string $rBody): string {
		$rPath = $this->rDir . '9901_.m3u8';
		file_put_contents($rPath, $rBody);
		return $rPath;
	}

	private function build(array $rSettings, string $rPath) {
		return HLSGenerator::generateHLS($rSettings, $rPath, 'user', 'pass', 9901, 'uuid-1', '10.0.0.1', null, '', 'h264', 0, 1, null);
	}

	public function testMissingPlaylistOrNoSegmentsIsFalse(): void {
		$this->assertFalse($this->build($this->settings(), $this->rDir . 'nope_.m3u8'));
		$this->assertFalse($this->build($this->settings(), $this->playlist("#EXTM3U\n#EXT-X-TARGETDURATION:10\n")));
	}

	public function testEverySegmentBecomesATokenThatSegmentPhpReads(): void {
		$rOut = $this->build($this->settings(), $this->playlist("#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-MEDIA-SEQUENCE:10\n#EXTINF:10.0,\n9901_10.ts\n#EXTINF:10.0,\n9901_11.ts\n"));
		$this->assertIsString($rOut);
		$this->assertStringContainsString('#EXT-X-MEDIA-SEQUENCE:10', $rOut);
		$this->assertStringNotContainsString('9901_10.ts', $rOut);
		preg_match_all('#^/hls/(\S+)$#m', $rOut, $rM);
		$this->assertCount(2, $rM[1]);
		// The payload segment.php splits on '/': user/pass/ip/stream/segment/uuid/server/codec/on_demand.
		$rParts = explode('/', (string) Encryption::readToken($rM[1][1], self::PASS, OPENSSL_EXTRA, true));
		$this->assertSame(['user', 'pass', '10.0.0.1', '9901', '9901_11.ts', 'uuid-1', '1', 'h264', '0'], $rParts);
	}

	public function testOverlappingNamesAreRewrittenOneLineAtATime(): void {
		// 9901_1.ts is a substring of 19901_1.ts-like names; each line must get its own token.
		$rOut = $this->build($this->settings(), $this->playlist("#EXTM3U\n#EXTINF:2.0,\n9901_1.ts\n#EXTINF:2.0,\n9901_11.ts\n"));
		preg_match_all('#^/hls/(\S+)$#m', $rOut, $rM);
		$this->assertCount(2, $rM[1]);
		$rSegs = array_map(fn($t) => explode('/', (string) Encryption::readToken($t, self::PASS, OPENSSL_EXTRA, true))[4], $rM[1]);
		$this->assertSame(['9901_1.ts', '9901_11.ts'], $rSegs);
	}

	public function testCdnAccessKeepsTheNameAndAddsTheToken(): void {
		$rOut = $this->build($this->settings(['allow_cdn_access' => 1]), $this->playlist("#EXTM3U\n#EXTINF:10.0,\n9901_5.ts\n"));
		$this->assertMatchesRegularExpression('#^/hls/9901_5\.ts\?token=\S+$#m', $rOut);
	}

	public function testEncryptedHlsDeclaresTheKeyAfterTheHeader(): void {
		file_put_contents($this->rDir . '9901_.iv', str_repeat("\x02", 16));
		$rOut = $this->build($this->settings(['encrypt_hls' => 1]), $this->playlist("#EXTM3U\n#EXTINF:10.0,\n9901_5.ts\n"));
		$rLines = explode("\n", $rOut);
		$this->assertSame('#EXTM3U', $rLines[0]);
		$this->assertStringStartsWith('#EXT-X-KEY:METHOD=AES-128,URI="/key/', $rLines[1]);
		$this->assertStringEndsWith(',IV=0x' . str_repeat('02', 16), $rLines[1]);
	}

	public function testFmp4InitMapIsTokenized(): void {
		$rOut = $this->build($this->settings(), $this->playlist("#EXTM3U\n#EXT-X-MAP:URI=\"9901_init.mp4\"\n#EXTINF:10.0,\n9901_5.m4s\n"));
		$this->assertMatchesRegularExpression('#\#EXT-X-MAP:URI="/hls/[^"]+"#', $rOut);
		$this->assertStringNotContainsString('9901_init.mp4', $rOut);
	}
}
