<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\OpensslExtra;
use XcVm\Core\Util\Encryption;

/**
 * Encryption::readToken — after a node is moved onto MAIN's OPENSSL_EXTRA,
 * the tokens it minted with the value it replaced (HLS segment and key links,
 * VOD links reused for seeks) keep opening until the replaced value's window
 * closes. Only the OPENSSL_EXTRA context falls back, and only a token that
 * fails with the current value tries it.
 *
 * tests/bootstrap.php defines OPENSSL_EXTRA as 'test-openssl-extra'.
 */
final class EncryptionPreviousExtraTest extends TestCase {

	private const PLAIN = 'live/user/pass/1/ts';

	private string $rFile;

	protected function setUp(): void {
		$this->rFile = sys_get_temp_dir() . '/xcvm_openssl_extra_prev_' . uniqid();
		OpensslExtra::usePrevFile($this->rFile);
	}

	protected function tearDown(): void {
		OpensslExtra::usePrevFile(null);
		@unlink($this->rFile);
	}

	private function previous(string $rValue, int $rValidUntil): void {
		file_put_contents($this->rFile, json_encode(['value' => $rValue, 'valid_until' => $rValidUntil]));
		OpensslExtra::usePrevFile($this->rFile);
	}

	public function testATokenMintedWithTheReplacedValueOpensOnlyWhileItIsAccepted(): void {
		$rToken = Encryption::seal(self::PLAIN, 'k', 'old-extra');
		$this->assertFalse(Encryption::readToken($rToken, 'k', OPENSSL_EXTRA, false), 'no previous value');

		$this->previous('old-extra', time() + 600);
		$this->assertSame(self::PLAIN, Encryption::readToken($rToken, 'k', OPENSSL_EXTRA, false));

		$this->previous('old-extra', time() - 1);
		$this->assertFalse(Encryption::readToken($rToken, 'k', OPENSSL_EXTRA, false), 'window closed');
	}

	public function testALegacyTokenFallsBackOnlyWhereLegacyTokensAreAccepted(): void {
		$rToken = Encryption::encrypt(self::PLAIN, 'k', 'old-extra');
		$this->previous('old-extra', time() + 600);

		$this->assertSame(self::PLAIN, Encryption::readToken($rToken, 'k', OPENSSL_EXTRA, true));
		$this->assertFalse(Encryption::readToken($rToken, 'k', OPENSSL_EXTRA, false));
	}

	public function testOnlyTheOpensslExtraContextFallsBack(): void {
		$this->previous('old-extra', time() + 600);

		$this->assertFalse(Encryption::readToken(Encryption::seal(self::PLAIN, 'k', 'old-extra'), 'k', 'd', false));
		$this->assertFalse(Encryption::readToken(Encryption::encrypt(self::PLAIN, 'k', 'old-extra'), 'k', 'd', true));
		$this->assertFalse(Encryption::readToken(Encryption::seal(self::PLAIN, 'other-key', OPENSSL_EXTRA), 'k', OPENSSL_EXTRA, false), 'another key is still refused');
	}

	public function testATokenMintedWithTheCurrentValueStillOpens(): void {
		$rSealed = Encryption::mintToken(self::PLAIN, 'k', OPENSSL_EXTRA, true);
		$rLegacy = Encryption::mintToken(self::PLAIN, 'k', OPENSSL_EXTRA, false);

		$this->assertSame(self::PLAIN, Encryption::readToken($rSealed, 'k', OPENSSL_EXTRA, false));
		$this->assertSame(self::PLAIN, Encryption::readToken($rLegacy, 'k', OPENSSL_EXTRA, true));

		$this->previous('old-extra', time() + 600);
		$this->assertSame(self::PLAIN, Encryption::readToken($rSealed, 'k', OPENSSL_EXTRA, false));
		$this->assertSame(self::PLAIN, Encryption::readToken($rLegacy, 'k', OPENSSL_EXTRA, true));
	}
}
