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
			$rHeader = DataPlaneTrust::signDigest(self::TID, 1, 0, 3, 'abc', 1800000000);
		} finally {
			DataPlaneTrust::useSources(null, null, null);
		}
		$rDigest = FileDigest::verify((string) $rHeader, self::TID, Ref::panelPub($rSeed));
		$this->assertTrue(FileDigest::chunkMatches((array) $rDigest, 0, 'abc'));
	}

	public function testTheAgentIsNeverAskedForAMalformedChunk(): void {
		// Refused before any socket call: null, as "no agent" is.
		$this->assertNull(AgentDataPlane::fileDigest(self::TID, 5, 10, str_repeat('a', 64), null, 0, null));
		$this->assertNull(AgentDataPlane::fileDigest(self::TID, 5, 10, str_repeat('a', 64), null, 5, 10));
	}
}
