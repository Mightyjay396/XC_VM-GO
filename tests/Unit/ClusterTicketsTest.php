<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\Crypto\FileDigest;
use XcVm\Core\Cluster\Crypto\NodeSig;
use XcVm\Core\Cluster\Crypto\RelayAuth;
use XcVm\Core\Cluster\Crypto\Ticket;
use XcVm\Tests\Support\ClusterReference as Ref;

/**
 * Data-plane credentials: panel-signed relay and file tickets, the child's
 * per-connect relay proof, and owner-signed file digests.
 */
final class ClusterTicketsTest extends TestCase {
	private string $rSeed;
	private string $rPanelPub;

	protected function setUp(): void {
		$this->rSeed = random_bytes(32);
		$this->rPanelPub = Ref::panelPub($this->rSeed);
	}

	private function relayTicket(int $rIat = 1000, int $rExp = 2000): string {
		$rDoc = Ticket::document('rly', 'tid_0123456789', $rIat, $rExp, ['child_sid' => 5, 'child_gen' => 2, 'parent_sid' => 1, 'stream_id' => 77]);
		return Ticket::wire($rDoc, Ref::panelSign($this->rSeed, 'rly', $rDoc));
	}

	public function testRelayTicketVerifies(): void {
		$rDoc = Ticket::verify($this->rPanelPub, 'rly', $this->relayTicket(), 1500);
		$this->assertSame(['child_gen' => 2, 'child_sid' => 5, 'exp' => 2000, 'iat' => 1000, 'parent_sid' => 1, 'stream_id' => 77, 'tid' => 'tid_0123456789', 'typ' => 'xcvm-relay', 'v' => 1], $rDoc);
	}

	public function testTicketTimeKindAndSignatureAreChecked(): void {
		$this->assertNull(Ticket::verify($this->rPanelPub, 'rly', $this->relayTicket(), 2000), 'expired');
		$this->assertNull(Ticket::verify($this->rPanelPub, 'rly', $this->relayTicket(), 879), 'not yet valid');
		$this->assertNotNull(Ticket::verify($this->rPanelPub, 'rly', $this->relayTicket(), 880), 'skew allowed');
		$this->assertNull(Ticket::verify($this->rPanelPub, 'fil', $this->relayTicket(), 1500), 'a relay ticket is not a file ticket');
		$this->assertNull(Ticket::verify(Ref::panelPub(random_bytes(32)), 'rly', $this->relayTicket(), 1500), 'other panel');
		[$rDocPart, $rSig] = explode('.', $this->relayTicket());
		$rForged = rtrim(strtr(base64_encode(str_replace('"stream_id":77', '"stream_id":78', base64_decode(strtr($rDocPart, '-_', '+/')))), '+/', '-_'), '=');
		$this->assertNull(Ticket::verify($this->rPanelPub, 'rly', $rForged . '.' . $rSig, 1500), 'tampered');
	}

	public function testTicketLifetimeIsCapped(): void {
		$this->expectException(InvalidArgumentException::class);
		Ticket::document('fil', 'tid_0123456789', 0, 21601, []);
	}

	public function testRelayAuthProvesTheChildKey(): void {
		$rPair = sodium_crypto_sign_keypair();
		$rSk = sodium_crypto_sign_secretkey($rPair);
		$rPub = sodium_crypto_sign_publickey($rPair);
		$rTicket = $this->relayTicket();
		$rNow = 1800000000000;
		$rHeader = RelayAuth::header($rSk, $rTicket, 'GET', '/relay/k/77.ts', $rNow);
		$rOk = RelayAuth::verify($rPub, $rHeader, $rTicket, 'GET', '/relay/k/77.ts', $rNow + 1000);
		$this->assertSame($rNow, $rOk['ts_ms']);
		$this->assertNull(RelayAuth::verify($rPub, $rHeader, $rTicket, 'GET', '/relay/k/78.ts', $rNow), 'other target');
		$this->assertNull(RelayAuth::verify($rPub, $rHeader, $this->relayTicket(1000, 2001), 'GET', '/relay/k/77.ts', $rNow), 'other ticket');
		$this->assertNull(RelayAuth::verify($rPub, $rHeader, $rTicket, 'GET', '/relay/k/77.ts', $rNow + 90001), 'stale');
		$this->assertNull(RelayAuth::verify(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()), $rHeader, $rTicket, 'GET', '/relay/k/77.ts', $rNow), 'other key');
	}

	public function testFileDigestFromMainOrANode(): void {
		$rFile = tempnam(sys_get_temp_dir(), 'xcvm-dig');
		file_put_contents($rFile, 'offair video bytes');
		try {
			$rDoc = FileDigest::document('tid_file_0001', 1, filesize($rFile), hash_file('sha256', $rFile), 1000);
			$rMain = FileDigest::header($rDoc, Ref::panelSign($this->rSeed, 'dig', $rDoc));
			$rDigest = FileDigest::verify($rMain, 'tid_file_0001', $this->rPanelPub);
			$this->assertNotNull($rDigest);
			$this->assertTrue(FileDigest::matches($rDigest, $rFile));
			$this->assertNull(FileDigest::verify($rMain, 'tid_other_0001', $this->rPanelPub), 'bound to its ticket');

			$rPair = sodium_crypto_sign_keypair();
			$rNode = FileDigest::header($rDoc, NodeSig::sign(sodium_crypto_sign_secretkey($rPair), 'digest', $rDoc));
			$this->assertNotNull(FileDigest::verify($rNode, 'tid_file_0001', null, sodium_crypto_sign_publickey($rPair)));
			$this->assertNull(FileDigest::verify($rNode, 'tid_file_0001', $this->rPanelPub), 'a node cannot sign as MAIN');

			file_put_contents($rFile, 'swapped');
			$this->assertFalse(FileDigest::matches($rDigest, $rFile));
		} finally {
			unlink($rFile);
		}
	}
}
