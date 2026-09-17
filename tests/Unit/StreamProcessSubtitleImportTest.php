<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Stream\StreamProcess;

/**
 * StreamProcess::buildSubtitleImport() — movies and episodes saved without
 * subtitles store movie_subtitles as NULL, and startMovie() hands that value
 * over. With a `string` parameter it threw, killing the queue daemon that
 * encodes the movie queue.
 */
final class StreamProcessSubtitleImportTest extends TestCase {

	private function build(?string $rJson): array {
		$rMethod = new ReflectionMethod(StreamProcess::class, 'buildSubtitleImport');
		$rMethod->setAccessible(true);
		return $rMethod->invoke(null, $rJson, []);
	}

	public function testNoSubtitlesImportsNothing(): void {
		$this->assertSame(['', ''], $this->build(null));
		$this->assertSame(['', ''], $this->build(''));
		$this->assertSame(['', ''], $this->build('{"files":[]}'));
	}
}
