<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Stream\PlaylistGenerator;

/**
 * Coverage for PlaylistGenerator::encryptedPlaySuffix — the /play/<token> URL
 * suffix used by the Encrypt Playlists feature. A VOD entry keeps its
 * container extension; a live entry keeps its output extension, except the
 * Cloudflare TS case which stays a bare token (rewritten to .ts by nginx).
 */
final class PlaylistGeneratorEncryptedUrlTest extends TestCase {

	private function suffix(array $rChannelInfo, array $rSettings, string $rOutputExt): string {
		$rM = new ReflectionMethod(PlaylistGenerator::class, 'encryptedPlaySuffix');
		$rM->setAccessible(true);
		return $rM->invoke(null, $rChannelInfo, $rSettings, $rOutputExt);
	}

	public function testVodKeepsContainerExtension(): void {
		$this->assertSame('.mp4', $this->suffix(['live' => 0, 'target_container' => 'mp4'], ['cloudflare' => 0], 'ts'));
	}

	public function testLiveKeepsOutputExtension(): void {
		$this->assertSame('.m3u8', $this->suffix(['live' => 1, 'target_container' => 'ts'], ['cloudflare' => 0], 'm3u8'));
		// Cloudflare set but a non-ts output still keeps its extension.
		$this->assertSame('.m3u8', $this->suffix(['live' => 1, 'target_container' => 'ts'], ['cloudflare' => 1], 'm3u8'));
	}

	public function testCloudflareTsLiveStaysBareToken(): void {
		$this->assertSame('', $this->suffix(['live' => 1, 'target_container' => 'ts'], ['cloudflare' => 1], 'ts'));
	}
}
