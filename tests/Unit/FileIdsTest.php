<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\FileIds;

/**
 * Ids read from file names, as the agent's streams section (strict: a name
 * that is not an id makes the whole answer false), the stream caches and
 * the node's own store (a name that is not an id is skipped) read them.
 */
final class FileIdsTest extends TestCase {
	public function testAnIdIsOneToTenDigitsWithoutALeadingZero(): void {
		foreach (['1', '9', '10', '1234567890', '9999999999'] as $rName) {
			$this->assertTrue(FileIds::valid($rName), $rName);
		}
		foreach (['', '0', '01', '12345678901', '-1', '+1', '1a', ' 1', '1 ', "1\n", '1.json', '.1', 'index'] as $rName) {
			$this->assertFalse(FileIds::valid($rName), var_export($rName, true));
		}
	}

	public function testTheIdsComeOutAscendingWithTheSuffixTakenOff(): void {
		$this->assertSame([2, 10, 11], FileIds::of(['/d/streams/11.json', '/d/streams/2.json', '/d/streams/10.json'], '.json', true));
		$this->assertSame([2, 10, 11], FileIds::of(['/d/streams/11.json', '/d/streams/2.json', '/d/streams/10.json'], '.json', false));
		$this->assertSame([3, 7], FileIds::of(['/c/7', '/c/3'], '', false));
		$this->assertSame([], FileIds::of([], '.json', true));
	}

	public function testANameThatIsNotAnIdIsFalseWhenStrictAndSkippedOtherwise(): void {
		$rFiles = ['/d/streams/5.json', '/d/streams/notes.json', '/d/streams/05.json', '/d/streams/4.json'];
		$this->assertFalse(FileIds::of($rFiles, '.json', true));
		$this->assertSame([4, 5], FileIds::of($rFiles, '.json', false));
		$this->assertSame([7], FileIds::of(['/c/index', '/c/7'], '', false), 'the stream caches\' index is not an entry');
		$this->assertFalse(FileIds::of(['/d/streams/5.json.tmp'], '.json', true), 'the suffix is taken off only at the end');
	}
}
