<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\Crypto\NodeSig;
use XcVm\Core\Cluster\Crypto\PanelSig;
use XcVm\Tests\Support\ClusterReference as Ref;

/**
 * A signature is valid for exactly one tag, and a node key can never produce
 * something that verifies as a panel record (different domain strings).
 */
final class SignTagSeparationTest extends TestCase {
	public function testASignatureVerifiesUnderItsOwnTagOnly(): void {
		$rSeed = random_bytes(32);
		$rPub = Ref::panelPub($rSeed);
		$rSig = Ref::panelSign($rSeed, 'den', '{"reason":"X"}');
		$this->assertTrue(PanelSig::verify($rPub, 'den', '{"reason":"X"}', $rSig));
		foreach (array_diff(PanelSig::TAGS, ['den']) as $rTag) {
			$this->assertFalse(PanelSig::verify($rPub, $rTag, '{"reason":"X"}', $rSig), $rTag);
		}
		$this->assertFalse(PanelSig::verify($rPub, 'den', '{"reason":"Y"}', $rSig));
	}

	public function testTheRegistryIsClosed(): void {
		$this->assertFalse(PanelSig::verify(random_bytes(32), 'xyz', 'p', random_bytes(64)));
		$this->expectException(InvalidArgumentException::class);
		PanelSig::input('xyz', 'p');
	}

	public function testNodeSignaturesNeverPassAsPanelRecords(): void {
		$rPair = sodium_crypto_sign_keypair();
		$rSk = sodium_crypto_sign_secretkey($rPair);
		$rPub = sodium_crypto_sign_publickey($rPair);
		$rSig = NodeSig::sign($rSk, 'request', 'payload');
		$this->assertTrue(NodeSig::verify($rPub, 'request', 'payload', $rSig));
		$this->assertFalse(NodeSig::verify($rPub, 'relay', 'payload', $rSig), 'purpose is bound');
		foreach (PanelSig::TAGS as $rTag) {
			$this->assertFalse(PanelSig::verify($rPub, $rTag, 'payload', $rSig), $rTag);
		}
		// And the reverse: a panel signature is not a node signature.
		$rPanel = sodium_crypto_sign_detached(PanelSig::input('cmd', 'payload'), $rSk);
		$this->assertFalse(NodeSig::verify($rPub, 'request', 'payload', $rPanel));
	}
}
