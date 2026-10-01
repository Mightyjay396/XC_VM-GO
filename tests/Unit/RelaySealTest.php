<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\Crypto\Seal;
use XcVm\Core\Cluster\DataPlaneTrust;
use XcVm\Core\Cluster\RelaySeal;

/**
 * AEAD-framed relays (D11): the frames a parent seals a relay's bytes in, and
 * the session key a child seals to it. The frame vector is the one the agent's
 * reader is tested against (XC_VM_Fanout, relayseal_test.go).
 */
final class RelaySealTest extends TestCase {
	/** Key 01×32: "hello " then "relay", one frame each. */
	private const VECTOR = '00000016d750f4b01a1be970c070804b4898dca2248d84850a1b00000015c9bffce6a50e56800e39e15b2e13e53deb43cdad05';

	protected function setUp(): void {
		if (!defined('SERVER_ID')) {
			define('SERVER_ID', 1);
		}
	}

	protected function tearDown(): void {
		DataPlaneTrust::useSources(null, null, null);
	}

	public function testTheFramesMatchTheAgentsVector(): void {
		$rSeal = new RelaySeal(str_repeat("\x01", 32));
		$this->assertSame(self::VECTOR, bin2hex($rSeal->frames('hello ') . $rSeal->frames('relay')));
		$this->assertSame('hello relay', RelaySeal::open(str_repeat("\x01", 32), (string) hex2bin(self::VECTOR)));
	}

	public function testALongWriteIsSplitAndNothingAlteredOpens(): void {
		$rKey = random_bytes(32);
		$rData = random_bytes(RelaySeal::FRAME * 2 + 100);
		$rFrames = (new RelaySeal($rKey))->frames($rData);
		$this->assertSame(3 * (4 + 16) + strlen($rData), strlen($rFrames), 'three frames, each 20 bytes over its plaintext');
		$this->assertSame($rData, RelaySeal::open($rKey, $rFrames));

		$rFlipped = $rFrames;
		$rFlipped[100] = chr(ord($rFlipped[100]) ^ 1);
		$this->assertNull(RelaySeal::open($rKey, $rFlipped), 'a changed byte');
		$this->assertNull(RelaySeal::open($rKey, substr($rFrames, 0, -1)), 'cut short');
		$rFirst = 4 + RelaySeal::FRAME + 16;
		$this->assertNull(RelaySeal::open($rKey, substr($rFrames, $rFirst, $rFirst) . substr($rFrames, 0, $rFirst)), 'reordered');
		$this->assertNull(RelaySeal::open(random_bytes(32), $rFrames), 'another key');
	}

	public function testANodeOpensTheKeyAChildSealedToItForThatStreamOnly(): void {
		$rSk = random_bytes(32);
		DataPlaneTrust::useSources(null, null, null, null, false, $rSk);
		$rKey = random_bytes(32);
		$rParam = rtrim(strtr(base64_encode(Seal::seal(sodium_crypto_scalarmult_base($rSk), RelaySeal::PURPOSE, RelaySeal::context((int) SERVER_ID, 42), $rKey)), '+/', '-_'), '=');

		$this->assertSame($rKey, RelaySeal::openKey($rParam, 42));
		$this->assertNull(RelaySeal::openKey($rParam, 43), 'another stream');
		$this->assertNull(RelaySeal::openKey('not-a-sealed-key', 42));
		$this->assertTrue(RelaySeal::supported(), 'a node with its box key opens relay keys');

		DataPlaneTrust::useSources(null, null, null, null, false, null);
	}
}
