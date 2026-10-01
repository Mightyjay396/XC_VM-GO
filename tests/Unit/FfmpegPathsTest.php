<?php

use XcVm\Streaming\Codec\FfmpegPaths;
use PHPUnit\Framework\TestCase;

final class FfmpegPathsTest extends TestCase {
	protected function setUp(): void {
		$this->resetFfmpegPaths();
	}

	public function testResolveUsesExpectedBinariesForKnownVersion() {
		FfmpegPaths::resolve('8.0');
		$this->assertSame(FFMPEG_BIN_80, FfmpegPaths::cpu());
		$this->assertSame(FFMPEG_BIN_80, FfmpegPaths::gpu());
		$this->assertSame(FFPROBE_BIN_80, FfmpegPaths::probe());
	}

	public function testResolveFallsBackToLegacyVersion() {
		FfmpegPaths::resolve('unknown');
		$this->assertSame(FFMPEG_BIN_40, FfmpegPaths::cpu());
		$this->assertSame(FFMPEG_BIN_40, FfmpegPaths::gpu());
		$this->assertSame(FFPROBE_BIN_40, FfmpegPaths::probe());
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg(BIN_PATH . 'ffmpeg_bin'));
		@unlink(dirname(FFMPEG_BIN_40) . '/BUILD_INFO');
	}

	/** A version the node lacks takes the newest build of its major (8.0 until 8.1 is fetched), never a backup folder; another major falls back to 4.0. */
	public function testAVersionTheNodeLacksTakesTheNewestOfItsMajor() {
		foreach (['8.0', '7.1', '8.9.backup'] as $rDir) {
			mkdir(BIN_PATH . 'ffmpeg_bin/' . $rDir, 0755, true);
			touch(BIN_PATH . 'ffmpeg_bin/' . $rDir . '/ffmpeg');
			touch(BIN_PATH . 'ffmpeg_bin/' . $rDir . '/ffprobe');
		}
		FfmpegPaths::resolve('8.1', '8.1');
		$this->assertSame(BIN_PATH . 'ffmpeg_bin/8.0/ffmpeg', FfmpegPaths::cpu());
		$this->assertSame(BIN_PATH . 'ffmpeg_bin/8.0/ffprobe', FfmpegPaths::probe());
		$this->assertSame(BIN_PATH . 'ffmpeg_bin/8.0/ffmpeg', FfmpegPaths::gpu());

		mkdir(BIN_PATH . 'ffmpeg_bin/8.1');
		touch(BIN_PATH . 'ffmpeg_bin/8.1/ffmpeg');
		$this->resetFfmpegPaths();
		FfmpegPaths::resolve('8.1');
		$this->assertSame(BIN_PATH . 'ffmpeg_bin/8.1/ffmpeg', FfmpegPaths::cpu(), 'its own build once fetched');
		$this->resetFfmpegPaths();
		FfmpegPaths::resolve('8.0');
		$this->assertSame(BIN_PATH . 'ffmpeg_bin/8.0/ffmpeg', FfmpegPaths::cpu(), 'the setting not migrated yet keeps its build');
		$this->resetFfmpegPaths();
		FfmpegPaths::resolve('9.0');
		$this->assertSame(FFMPEG_BIN_40, FfmpegPaths::cpu());
	}

	/** -nofix_dts is XUI's switch: only its 4.0 (no BUILD_INFO) takes it, not a rebuilt one. */
	public function testOnlyXuisBuildTakesNofixDts() {
		$this->assertTrue(FfmpegPaths::fixDts());
		file_put_contents(dirname(FFMPEG_BIN_40) . '/BUILD_INFO', "FFmpeg   : 4.4.5\n");
		$this->assertFalse(FfmpegPaths::fixDts());
	}

	private function resetFfmpegPaths() {
		$reflection = new ReflectionClass(FfmpegPaths::class);
		foreach (array('cpu', 'gpu', 'probe') as $propertyName) {
			$property = $reflection->getProperty($propertyName);
			$property->setAccessible(true);
			$property->setValue(null, null);
		}
		$resolved = $reflection->getProperty('resolved');
		$resolved->setAccessible(true);
		$resolved->setValue(null, false);
	}
}
