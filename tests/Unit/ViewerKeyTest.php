<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\ViewerKey;
use XcVm\Core\Config\StreamSecret;
use XcVm\Core\Util\Encryption;

/**
 * Per-node viewer-token keys (H1): a token MAIN mints for a node that reports
 * holding its key opens with that key only; a node that does not report, or
 * reports another key, gets the shared secret's token as before; a node reads
 * its own key first.
 */
final class ViewerKeyTest extends TestCase {
	private const SECRET = 'fleet-secret';

	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm_viewer_' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir);
		ViewerKey::useFile($this->rDir . 'viewer_key');
		StreamSecret::useFile($this->rDir . 'stream_secret.prev');
	}

	protected function tearDown(): void {
		ViewerKey::useFile(null);
		StreamSecret::useFile(null);
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/** Servers 5 and 6, 5 reporting $rFp. */
	private function servers(?string $rFp): array {
		return [5 => ['id' => 5, ViewerKey::FP => $rFp], 6 => ['id' => 6, ViewerKey::FP => null]];
	}

	private function settings(): array {
		return ['live_streaming_pass' => self::SECRET, 'secure_stream_tokens' => 1];
	}

	public function testEachNodesKeyIsItsOwn(): void {
		$this->assertNotSame(ViewerKey::derive(self::SECRET, 5), ViewerKey::derive(self::SECRET, 6));
		$this->assertNotSame(ViewerKey::derive(self::SECRET, 5), ViewerKey::derive('another-secret', 5));
		$this->assertSame(ViewerKey::derive(self::SECRET, 5), ViewerKey::derive(self::SECRET, 5));
		$this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', ViewerKey::kid(ViewerKey::derive(self::SECRET, 5)));
	}

	public function testATokenForAReportingNodeOpensWithItsKeyAlone(): void {
		$rKey = ViewerKey::derive(self::SECRET, 5);
		$rToken = ViewerKey::mint('{"stream_id":1}', $this->servers(ViewerKey::kid($rKey)), 5, $this->settings());

		$this->assertSame('{"stream_id":1}', Encryption::open($rToken, $rKey, OPENSSL_EXTRA));
		$this->assertFalse(Encryption::open($rToken, self::SECRET, OPENSSL_EXTRA), 'not with the shared secret');
		$this->assertFalse(Encryption::open($rToken, ViewerKey::derive(self::SECRET, 6), OPENSSL_EXTRA), 'not with another node\'s key');
	}

	public function testANodeThatDoesNotReportItsCurrentKeyGetsTheSharedSecretsToken(): void {
		foreach ([null, '', ViewerKey::kid(ViewerKey::derive('another-secret', 5)), ViewerKey::kid(ViewerKey::derive(self::SECRET, 6))] as $rFp) {
			$rToken = ViewerKey::mint('x', $this->servers($rFp), 5, $this->settings());
			$this->assertSame('x', Encryption::open($rToken, self::SECRET, OPENSSL_EXTRA), var_export($rFp, true));
		}
		$this->assertSame('x', Encryption::open(ViewerKey::mint('x', $this->servers(null), 6, $this->settings()), self::SECRET, OPENSSL_EXTRA));
		$this->assertSame('x', Encryption::open(ViewerKey::mint('x', $this->servers(null), null, $this->settings()), self::SECRET, OPENSSL_EXTRA), 'no server: the shared secret');
	}

	public function testANodeNotYetOnTheRotatedSecretGetsTokensForTheKeyItHolds(): void {
		$rOld = ViewerKey::derive('old-secret', 5);
		StreamSecret::replaced('old-secret', self::SECRET);
		$rToken = ViewerKey::mint('x', $this->servers(ViewerKey::kid($rOld)), 5, $this->settings());
		$this->assertSame('x', Encryption::open($rToken, $rOld, OPENSSL_EXTRA));

		// The section carries it as the previous key while the window is open.
		$rEntry = ViewerKey::entry(self::SECRET, 5, StreamSecret::previousEntry());
		$this->assertSame([ViewerKey::derive(self::SECRET, 5), $rOld], [$rEntry['current'], $rEntry['previous']]);

		// Past the window, the old key is no one's.
		$this->assertNull(ViewerKey::keyFor(ViewerKey::kid($rOld), self::SECRET, 5, time() + StreamSecret::PREVIOUS_WINDOW + 1));
	}

	public function testTheNodeKeepsItsKeysAndReadsWithThemFirst(): void {
		$this->assertSame([], ViewerKey::own(), 'none yet (as on MAIN)');
		$rEntry = ViewerKey::entry(self::SECRET, 5, ['value' => 'old-secret', 'valid_until' => time() + 60]);

		$this->assertTrue(ViewerKey::adopt($rEntry));
		$this->assertNull(ViewerKey::adopt($rEntry), 'the same entry again: nothing to write');
		$this->assertSame(0600, fileperms(ViewerKey::file()) & 0777);
		$this->assertSame([$rEntry['current'], $rEntry['previous']], ViewerKey::own());
		$this->assertSame([$rEntry['current']], ViewerKey::own(time() + 61), 'the previous key only inside its window');

		// What MAIN minted for this node reads here whatever shared secret the node holds.
		$rToken = ViewerKey::mint('{"a":1}', $this->servers($rEntry['kid']), 5, $this->settings());
		$this->assertSame('{"a":1}', Encryption::readToken($rToken, 'not-the-fleet-secret', OPENSSL_EXTRA, false));
		// The shared secret's tokens still read, while the node holds it.
		$this->assertSame('y', Encryption::readToken(Encryption::seal('y', self::SECRET, OPENSSL_EXTRA), self::SECRET, OPENSSL_EXTRA, false));
		// Another node's token does not.
		$rOther = Encryption::seal('z', ViewerKey::derive(self::SECRET, 6), OPENSSL_EXTRA);
		$this->assertFalse(Encryption::readToken($rOther, 'not-the-fleet-secret', OPENSSL_EXTRA, false));
	}

	public function testTheNodesOwnTokensUseItsOwnKeyOnceItHasOne(): void {
		// None yet (MAIN, or a node that has not applied it): the shared secret's, as before.
		$this->assertSame('seg', Encryption::open(ViewerKey::mintOwn('seg', $this->settings()), self::SECRET, OPENSSL_EXTRA));
		$this->assertSame('seg', Encryption::decrypt(ViewerKey::mintOwn('seg', $this->settings(), false), self::SECRET, OPENSSL_EXTRA), 'the legacy format when asked');

		$rEntry = ViewerKey::entry(self::SECRET, 5, null);
		ViewerKey::adopt($rEntry);
		$rToken = ViewerKey::mintOwn('seg', $this->settings(), false);
		$this->assertSame('seg', Encryption::open($rToken, $rEntry['current'], OPENSSL_EXTRA), 'sealed with its own key, whatever the setting');
		$this->assertFalse(Encryption::open($rToken, self::SECRET, OPENSSL_EXTRA));
		$this->assertSame('seg', Encryption::readToken($rToken, self::SECRET, OPENSSL_EXTRA, false), 'and read back on the node');
	}

	public function testWithoutTheSharedSecretOnlyTheNodesOwnKeyOpens(): void {
		$rEntry = ViewerKey::entry(self::SECRET, 5, null);
		ViewerKey::adopt($rEntry);
		$this->assertSame('ok', Encryption::readToken(Encryption::seal('ok', $rEntry['current'], OPENSSL_EXTRA), '', OPENSSL_EXTRA, true));
		// Under an empty key anyone who knows the context could seal one: refused.
		foreach (['', null] as $rNone) {
			$this->assertFalse(Encryption::readToken(Encryption::seal('forged', '', OPENSSL_EXTRA), $rNone, OPENSSL_EXTRA, true));
			$this->assertFalse(Encryption::readToken(Encryption::encrypt('forged', '', OPENSSL_EXTRA), $rNone, OPENSSL_EXTRA, true));
		}
		$this->assertFalse(Encryption::readToken(Encryption::seal('old', self::SECRET, OPENSSL_EXTRA), '', OPENSSL_EXTRA, true), 'the shared secret\'s tokens are not this node\'s to read');
	}

	public function testALegacyPasswordChecksAgainstTheValueOrElseItsHash(): void {
		$this->assertTrue(ViewerKey::passMatches(self::SECRET, self::SECRET));
		$this->assertFalse(ViewerKey::passMatches(self::SECRET, 'wrong'));
		$this->assertFalse(ViewerKey::passMatches('', self::SECRET), 'no value and no hash');
		$this->assertNull(ViewerKey::adoptPassHash(null), 'nothing to forget');

		$this->assertTrue(ViewerKey::adoptPassHash(hash('sha256', self::SECRET)));
		$this->assertNull(ViewerKey::adoptPassHash(hash('sha256', self::SECRET)));
		$this->assertSame(0600, fileperms($this->rDir . 'stream_pass_hash') & 0777);
		$this->assertTrue(ViewerKey::passMatches('', self::SECRET));
		$this->assertTrue(ViewerKey::passMatches(null, self::SECRET));
		$this->assertFalse(ViewerKey::passMatches('', 'wrong'));
		$this->assertFalse(ViewerKey::passMatches('', ''), 'an empty password never');
		$this->assertFalse(ViewerKey::passMatches('', hash('sha256', self::SECRET)), 'the hash is no password');

		$this->assertTrue(ViewerKey::adoptPassHash(null), 'the value back: the hash goes');
		$this->assertFalse(ViewerKey::passMatches('', self::SECRET));
	}

	public function testANodeSendingAViewerToItselfMintsWithItsOwnKey(): void {
		if (!defined('SERVER_ID')) {
			define('SERVER_ID', 1);
		}
		$rEntry = ViewerKey::entry(self::SECRET, (int) SERVER_ID, null);
		ViewerKey::adopt($rEntry);
		$rToken = ViewerKey::mint('off-air', [], (int) SERVER_ID, ['live_streaming_pass' => '']);
		$this->assertSame('off-air', Encryption::open($rToken, $rEntry['current'], OPENSSL_EXTRA));
	}
}
