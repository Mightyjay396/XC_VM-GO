<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\Crypto\FileDigest;
use XcVm\Core\Cluster\Crypto\NodeSig;
use XcVm\Core\Cluster\Crypto\RelayAuth;
use XcVm\Core\Cluster\Crypto\Ticket;
use XcVm\Tests\Support\ClusterReference as Ref;

/**
 * The data plane's wire formats are the panel's (ADR 0004, "Who owns which
 * part of the wire"): tests/Support/cluster_dataplane_vectors.json fixes the
 * relay and file tickets, X-XCVM-Relay-Auth and X-XCVM-File-Digest, and the Go
 * agent passes the same file (internal/clustercrypto/testdata). This test is
 * the file's generator in reverse: PHP must produce every byte of it.
 */
final class ClusterDataplaneVectorsTest extends TestCase {
	/** @var array<string, mixed> */
	private static array $rV;

	public static function setUpBeforeClass(): void {
		self::$rV = json_decode((string) file_get_contents(dirname(__DIR__) . '/Support/cluster_dataplane_vectors.json'), true);
	}

	public function testThePanelKeyIsTheSharedVectorsOne(): void {
		$rShared = json_decode((string) file_get_contents(dirname(__DIR__) . '/Support/cluster_vectors.json'), true);
		$this->assertSame($rShared['sign']['seed'], self::$rV['panel_seed']);
		$this->assertSame(self::$rV['panel_pub'], bin2hex(Ref::panelPub(hex2bin(self::$rV['panel_seed']))));
		$this->assertSame(self::$rV['node_pub'], bin2hex(sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair(hex2bin(self::$rV['node_seed'])))));
	}

	public function testTickets(): void {
		$rSeed = hex2bin(self::$rV['panel_seed']);
		foreach (['relay_ticket', 'file_ticket'] as $rName) {
			$rT = self::$rV[$rName];
			$rDoc = Ticket::document($rT['tag'], $rT['tid'], $rT['iat'], $rT['exp'], $rT['fields']);
			$this->assertSame($rT['doc'], $rDoc, $rName);
			$this->assertSame($rT['wire'], Ticket::wire($rDoc, Ref::panelSign($rSeed, $rT['tag'], $rDoc)), $rName);
			$rOk = Ticket::verify(hex2bin(self::$rV['panel_pub']), $rT['tag'], $rT['wire'], $rT['iat'] + 60);
			$this->assertSame($rT['tid'], $rOk['tid'] ?? null, $rName);
		}
	}

	public function testRelayAndFileAuth(): void {
		$rSk = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(hex2bin(self::$rV['node_seed'])));
		$rA = self::$rV['relay_auth'];
		$rWire = self::$rV['relay_ticket']['wire'];
		$this->assertSame($rA['message'], bin2hex(RelayAuth::message($rWire, $rA['method'], $rA['target'], $rA['ts_ms'], hex2bin($rA['nonce']))));
		$this->assertSame($rA['header'], RelayAuth::header($rSk, $rWire, $rA['method'], $rA['target'], $rA['ts_ms'], hex2bin($rA['nonce'])));
		$this->assertNotNull(RelayAuth::verify(hex2bin(self::$rV['node_pub']), $rA['header'], $rWire, 'GET', $rA['target'], $rA['ts_ms']));
		$rF = self::$rV['file_auth'];
		$this->assertSame($rF['header'], RelayAuth::header($rSk, self::$rV['file_ticket']['wire'], $rF['method'], $rF['target'], $rF['ts_ms'], hex2bin($rF['nonce'])));
	}

	public function testFileDigest(): void {
		$rD = self::$rV['file_digest'];
		$rDoc = FileDigest::document($rD['tid'], $rD['owner_sid'], strlen($rD['body']), $rD['sha256'], $rD['iat'], $rD['offset'], $rD['total']);
		$this->assertSame($rD['doc'], $rDoc);
		$this->assertSame(hash('sha256', $rD['body']), $rD['sha256']);
		$rSk = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(hex2bin(self::$rV['node_seed'])));
		$this->assertSame($rD['node_header'], FileDigest::header($rDoc, NodeSig::sign($rSk, 'digest', $rDoc)));
		$this->assertSame($rD['panel_header'], FileDigest::header($rDoc, Ref::panelSign(hex2bin(self::$rV['panel_seed']), 'dig', $rDoc)));
		$rOk = FileDigest::verify($rD['node_header'], $rD['tid'], null, hex2bin(self::$rV['node_pub']));
		$this->assertTrue(FileDigest::chunkMatches($rOk, $rD['offset'], $rD['body']));
		$this->assertFalse(FileDigest::chunkMatches($rOk, $rD['offset'] + 1, $rD['body']), 'moved');
		$this->assertFalse(FileDigest::chunkMatches($rOk, $rD['offset'], 'y' . substr($rD['body'], 1)), 'tampered');
	}
}
