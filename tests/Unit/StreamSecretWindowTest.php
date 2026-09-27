<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\StreamSecret;
use XcVm\Core\Util\Encryption;

/**
 * The viewer-token secret can be replaced without breaking the links already in
 * players' hands: the value replaced is kept for StreamSecret::PREVIOUS_WINDOW
 * and `Encryption::readToken()` tries it once the current one fails. Before
 * this, changing `live_streaming_pass` in the settings form invalidated every
 * stream link, HLS key URL and admin preview token at once, which is why a
 * leaked secret could not be rotated without an outage.
 */
final class StreamSecretWindowTest extends TestCase {

	private const NOW = 1800000000;

	private const DEVICE = 'test-openssl-extra';

	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-secret-' . bin2hex(random_bytes(4));
		mkdir($this->rDir);
		StreamSecret::useFile($this->rDir . '/stream_secret.prev');
	}

	protected function tearDown(): void {
		StreamSecret::useFile(null);
		array_map('unlink', glob($this->rDir . '/*') ?: []);
		rmdir($this->rDir);
	}

	public function testATokenMintedUnderTheReplacedSecretStillOpens(): void {
		$rOld = 'old-secret';
		$rNew = 'new-secret';
		$rSealed = Encryption::mintToken('7/user/pass', $rOld, self::DEVICE, true);
		$rLegacy = Encryption::mintToken('7/user/pass', $rOld, self::DEVICE, false);

		// Nothing replaced yet: the old secret's token is unreadable, as it must be.
		$this->assertFalse(Encryption::readToken($rSealed, $rNew, self::DEVICE, true));

		$this->assertTrue(StreamSecret::replaced($rOld, $rNew, self::NOW));
		$this->assertSame('7/user/pass', Encryption::readToken($rSealed, $rNew, self::DEVICE, true));
		$this->assertSame('7/user/pass', Encryption::readToken($rLegacy, $rNew, self::DEVICE, true), 'the legacy format too');
		// And the current secret keeps working, without paying for the fallback.
		$this->assertSame('8/a/b', Encryption::readToken(Encryption::mintToken('8/a/b', $rNew, self::DEVICE, true), $rNew, self::DEVICE, false));
	}

	public function testTheWindowCloses(): void {
		StreamSecret::replaced('old-secret', 'new-secret', self::NOW, 600);

		$this->assertSame('old-secret', StreamSecret::previous(self::NOW + 600));
		$this->assertNull(StreamSecret::previous(self::NOW + 601));
		$this->assertNull(StreamSecret::previousEntry(self::NOW + 601));
	}

	public function testNothingIsKeptForANonChange(): void {
		$this->assertFalse(StreamSecret::replaced('same', 'same', self::NOW), 'the value did not change');
		$this->assertFalse(StreamSecret::replaced('', 'new', self::NOW), 'there was no secret to keep');
		$this->assertFileDoesNotExist($this->rDir . '/stream_secret.prev');
		$this->assertNull(StreamSecret::previous(self::NOW));
	}

	public function testANodeAdoptsWhatMainDatedAndNothingElse(): void {
		// MAIN's `secrets` section: the previous value with the end of its window.
		$this->assertTrue(StreamSecret::adopt('mains-old', self::NOW + 300, self::NOW));
		$this->assertSame('mains-old', StreamSecret::previous(self::NOW));

		// The same entry again writes nothing, so a window is never extended.
		$this->assertNull(StreamSecret::adopt('mains-old', self::NOW + 300, self::NOW));
		// An expired or absent one is not adopted, and does not erase what is held.
		$this->assertNull(StreamSecret::adopt('older', self::NOW - 1, self::NOW));
		$this->assertNull(StreamSecret::adopt(null, null, self::NOW));
		$this->assertSame('mains-old', StreamSecret::previous(self::NOW));
	}

	public function testTheFileIsOnlyReadableByItsOwner(): void {
		StreamSecret::replaced('old-secret', 'new-secret', self::NOW);
		$this->assertSame('0600', substr(sprintf('%o', fileperms($this->rDir . '/stream_secret.prev')), -4));
	}

	public function testAValueWrittenByAnotherProcessIsPickedUp(): void {
		// The php-fpm worker that reads a viewer's token is not the process that
		// replaced the secret: a cached "there is none" must not outlive the write.
		$this->assertNull(StreamSecret::previous(self::NOW));

		$rFile = $this->rDir . '/stream_secret.prev';
		file_put_contents($rFile, (string) json_encode(['value' => 'written-elsewhere', 'valid_until' => self::NOW + 60]));
		touch($rFile, self::NOW);
		clearstatcache(true, $rFile);

		$this->assertSame('written-elsewhere', StreamSecret::previous(self::NOW));
	}

	public function testAGarbageFileIsNoPreviousSecret(): void {
		file_put_contents($this->rDir . '/stream_secret.prev', 'not json');
		StreamSecret::useFile($this->rDir . '/stream_secret.prev');
		$this->assertNull(StreamSecret::previous(self::NOW));

		file_put_contents($this->rDir . '/stream_secret.prev', json_encode(['value' => '', 'valid_until' => self::NOW + 60]));
		StreamSecret::useFile($this->rDir . '/stream_secret.prev');
		$this->assertNull(StreamSecret::previous(self::NOW), 'an empty secret is never tried');
	}
}
