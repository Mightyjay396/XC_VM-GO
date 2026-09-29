<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\AgentDataPlane;
use XcVm\Core\Cluster\Crypto\FileDigest;
use XcVm\Core\Cluster\Crypto\NodeSig;
use XcVm\Core\Cluster\DataPlaneTrust;
use XcVm\Tests\Support\ClusterReference as Ref;

/**
 * X-XCVM-File-Digest per chunk (ADR 0004, Phase 8): a digest names where its
 * chunk starts and the file's size, so a chunk moved, cut or altered no
 * longer matches, and a whole-file digest (no offset) still reads as before.
 */
final class FileDigestTest extends TestCase {
	private const TID = 'f166666-9-0123456789abcdef0123456789abcdef';

	public function testAChunkDigestBindsItsOffsetAndTheFileSize(): void {
		$rSk = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
		$rPub = sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair(substr($rSk, 0, 32)));
		$rChunk = str_repeat('c', 1000);
		$rDoc = FileDigest::document(self::TID, 5, 1000, hash('sha256', $rChunk), 1800000000, 4194304, 4195304);
		$this->assertSame('{"iat":1800000000,"offset":4194304,"owner_sid":5,"sha256":"' . hash('sha256', $rChunk) . '","size":1000,"tid":"' . self::TID . '","total":4195304,"typ":"xcvm-file-digest","v":1}', $rDoc);
		$rDigest = FileDigest::verify(FileDigest::header($rDoc, NodeSig::sign($rSk, 'digest', $rDoc)), self::TID, null, $rPub);
		$this->assertNotNull($rDigest);
		$this->assertTrue(FileDigest::chunkMatches($rDigest, 4194304, $rChunk));
		$this->assertFalse(FileDigest::chunkMatches($rDigest, 0, $rChunk), 'moved');
		$this->assertFalse(FileDigest::chunkMatches($rDigest, 4194304, substr($rChunk, 1)), 'cut');
		$this->assertFalse(FileDigest::chunkMatches($rDigest, 4194304, 'd' . substr($rChunk, 1)), 'altered');
	}

	public function testAChunkDigestNamesTheRequestItAnswers(): void {
		$rSeed = random_bytes(32);
		$rNonce = str_repeat('ab', 16);
		$rDoc = FileDigest::document(self::TID, 1, 3, hash('sha256', 'abc'), 1800000000, 0, 3, $rNonce);
		$this->assertSame('{"iat":1800000000,"nonce":"' . $rNonce . '","offset":0,"owner_sid":1,"sha256":"' . hash('sha256', 'abc') . '","size":3,"tid":"' . self::TID . '","total":3,"typ":"xcvm-file-digest","v":1}', $rDoc);
		$this->assertSame($rNonce, FileDigest::verify(FileDigest::header($rDoc, Ref::panelSign($rSeed, 'dig', $rDoc)), self::TID, Ref::panelPub($rSeed))['nonce'] ?? null);
		foreach ([[null, null, $rNonce], [0, 3, 'AB' . substr($rNonce, 2)], [0, 3, substr($rNonce, 2)]] as [$rOffset, $rTotal, $rBad]) {
			try {
				FileDigest::document(self::TID, 1, 3, hash('sha256', 'abc'), 1, $rOffset, $rTotal, $rBad);
				$this->fail('accepted nonce ' . $rBad . ' at offset ' . var_export($rOffset, true));
			} catch (InvalidArgumentException) {
				$this->addToAssertionCount(1);
			}
		}
		// A signed document that says so anyway is refused at verification.
		foreach ([['nonce' => $rNonce], ['nonce' => 5, 'offset' => 0, 'total' => 3], ['nonce' => null, 'offset' => 0, 'total' => 3], ['nonce' => 'xyz', 'offset' => 0, 'total' => 3]] as $rOver) {
			$rDoc = json_encode(['iat' => 1, 'owner_sid' => 1, 'sha256' => hash('sha256', 'abc'), 'size' => 3, 'tid' => self::TID, 'typ' => 'xcvm-file-digest', 'v' => 1] + $rOver);
			$this->assertNull(FileDigest::verify(FileDigest::header($rDoc, Ref::panelSign($rSeed, 'dig', $rDoc)), self::TID, Ref::panelPub($rSeed)), (string) json_encode($rOver));
		}
	}

	public function testOffsetAndTotalComeTogetherAndAgree(): void {
		foreach ([[0, null], [null, 10], [-1, 10], [10, 5]] as [$rOffset, $rTotal]) {
			try {
				FileDigest::document(self::TID, 5, 10, str_repeat('a', 64), 1, $rOffset, $rTotal);
				$this->fail('accepted offset ' . var_export($rOffset, true) . ', total ' . var_export($rTotal, true));
			} catch (InvalidArgumentException) {
				$this->addToAssertionCount(1);
			}
		}
		// A signed document that says so anyway is refused at verification.
		$rSeed = random_bytes(32);
		$rDoc = json_encode(['iat' => 1, 'offset' => 50, 'owner_sid' => 1, 'sha256' => str_repeat('a', 64), 'size' => 10, 'tid' => self::TID, 'total' => 20, 'typ' => 'xcvm-file-digest', 'v' => 1]);
		$this->assertNull(FileDigest::verify(FileDigest::header($rDoc, Ref::panelSign($rSeed, 'dig', $rDoc)), self::TID, Ref::panelPub($rSeed)), 'a chunk past the file\'s end');
		$rDoc = json_encode(['iat' => 1, 'offset' => 0, 'owner_sid' => 1, 'sha256' => str_repeat('a', 64), 'size' => 10, 'tid' => self::TID, 'typ' => 'xcvm-file-digest', 'v' => 1]);
		$this->assertNull(FileDigest::verify(FileDigest::header($rDoc, Ref::panelSign($rSeed, 'dig', $rDoc)), self::TID, Ref::panelPub($rSeed)), 'an offset without the total');
	}

	public function testAWholeFileDigestReadsAsBefore(): void {
		$rSeed = random_bytes(32);
		$rDoc = FileDigest::document(self::TID, 1, 3, hash('sha256', 'abc'), 1);
		$this->assertStringNotContainsString('offset', $rDoc);
		$rDigest = FileDigest::verify(FileDigest::header($rDoc, Ref::panelSign($rSeed, 'dig', $rDoc)), self::TID, Ref::panelPub($rSeed));
		$this->assertNotNull($rDigest);
		$this->assertFalse(FileDigest::chunkMatches($rDigest, 0, 'abc'), 'a whole-file digest is no chunk\'s');
	}

	public function testMainSignsItsOwnChunksAsThePanel(): void {
		$rSeed = random_bytes(32);
		DataPlaneTrust::useSources(null, null, null, static fn(string $rDoc): string => Ref::panelSign($rSeed, 'dig', $rDoc), true);
		try {
			$rHeader = DataPlaneTrust::signDigest(self::TID, 1, 0, 3, 'abc', 1800000000, str_repeat("\x5a", 16));
		} finally {
			DataPlaneTrust::useSources(null, null, null);
		}
		$rDigest = FileDigest::verify((string) $rHeader, self::TID, Ref::panelPub($rSeed));
		$this->assertTrue(FileDigest::chunkMatches((array) $rDigest, 0, 'abc'));
		$this->assertSame(str_repeat('5a', 16), $rDigest['nonce'] ?? null);
	}

	public function testTheAgentIsNeverAskedForAMalformedChunk(): void {
		// Refused before any socket call: null, as "no agent" is.
		$this->assertNull(AgentDataPlane::fileDigest(self::TID, 5, 10, str_repeat('a', 64), null, 0, null));
		$this->assertNull(AgentDataPlane::fileDigest(self::TID, 5, 10, str_repeat('a', 64), null, 5, 10));
		$this->assertNull(AgentDataPlane::fileDigest(self::TID, 5, 10, str_repeat('a', 64), null, null, null, str_repeat('a', 32)), 'a nonce on a whole file');
		$this->assertNull(AgentDataPlane::fileDigest(self::TID, 5, 10, str_repeat('a', 64), null, 0, 10, 'not-hex'), 'a malformed nonce');
	}
}
