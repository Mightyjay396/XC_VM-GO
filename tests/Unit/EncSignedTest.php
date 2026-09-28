<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\Crypto\Enc;
use XcVm\Core\Cluster\Crypto\FileDigest;
use XcVm\Core\Cluster\Crypto\NodeSig;
use XcVm\Core\Cluster\Crypto\PanelSig;
use XcVm\Core\Cluster\Crypto\Ticket;

/**
 * `b64url(doc) "." b64url(sig)`, the wire form tickets and file digests
 * share (Enc::joinSigned / Enc::splitSigned): each keeps its own cap.
 */
final class EncSignedTest extends TestCase {
	public function testAJoinedWireSplitsBackIntoItsDocAndSig(): void {
		$rDoc = '{"typ":"xcvm-file","v":1}';
		$rSig = random_bytes(64);
		$rWire = Enc::joinSigned($rDoc, $rSig);
		$this->assertSame(Enc::b64url($rDoc) . '.' . Enc::b64url($rSig), $rWire);
		$this->assertSame([$rDoc, $rSig], Enc::splitSigned($rWire, 4096));
		$this->assertSame($rWire, Ticket::wire($rDoc, $rSig));
		$this->assertSame($rWire, FileDigest::header($rDoc, $rSig));
	}

	public function testTheCapCountsTheWholeWireInclusively(): void {
		$rWire = Enc::joinSigned(str_repeat('d', 100), str_repeat('s', 50));
		$this->assertNotNull(Enc::splitSigned($rWire, strlen($rWire)));
		$this->assertNull(Enc::splitSigned($rWire, strlen($rWire) - 1));
	}

	public function testAnythingButTwoStrictB64urlHalvesIsRefused(): void {
		$rDoc = Enc::b64url('doc');
		$rSig = Enc::b64url('sig');
		foreach ([
			'', '.', $rDoc, $rDoc . '.', '.' . $rSig, $rDoc . '.' . $rSig . '.', $rDoc . '..' . $rSig,
			$rDoc . '.' . $rSig . '.' . $rSig, base64_encode('doc?') . '.' . $rSig, $rDoc . '.' . base64_encode('s'),
			$rDoc . '.+' . $rSig, $rDoc . ' .' . $rSig, "{$rDoc}.{$rSig}\n",
		] as $rWire) {
			$this->assertNull(Enc::splitSigned($rWire, 4096), var_export($rWire, true));
		}
	}

	public function testEachFormKeepsItsOwnCap(): void {
		$this->assertSame(2048, FileDigest::MAX_HEADER);
		$this->assertSame(4096, Ticket::MAX_WIRE);
		$rKeys = sodium_crypto_sign_keypair();
		$rSk = sodium_crypto_sign_secretkey($rKeys);
		$rPub = sodium_crypto_sign_publickey($rKeys);
		$rSha = str_repeat('a', 64);
		// A validly signed digest, its tid grown until the header passes the cap.
		[$rUnder, $rOver] = self::around(FileDigest::MAX_HEADER, static function (int $rPad) use ($rSk, $rSha): array {
			$rTid = str_repeat('t', $rPad);
			$rDoc = FileDigest::document($rTid, 3, 10, $rSha, 1700000000);
			return [FileDigest::header($rDoc, NodeSig::sign($rSk, 'digest', $rDoc)), $rTid];
		});
		$this->assertNotNull(FileDigest::verify($rUnder[0], $rUnder[1], null, $rPub), 'at most MAX_HEADER bytes');
		$this->assertNull(FileDigest::verify($rOver[0], $rOver[1], null, $rPub), 'past MAX_HEADER');
		$rNow = time();
		[$rUnder, $rOver] = self::around(Ticket::MAX_WIRE, static function (int $rPad) use ($rSk, $rNow): array {
			$rDoc = Ticket::document('fil', 'ticket-0001', $rNow, $rNow + 60, ['pad' => str_repeat('p', $rPad)]);
			return [Ticket::wire($rDoc, sodium_crypto_sign_detached(PanelSig::input('fil', $rDoc), $rSk)), ''];
		});
		$this->assertNotNull(Ticket::verify($rPub, 'fil', $rUnder[0], $rNow), 'at most MAX_WIRE bytes');
		$this->assertNull(Ticket::verify($rPub, 'fil', $rOver[0], $rNow), 'past MAX_WIRE');
	}

	/**
	 * The longest wire $rMake builds within $rMax bytes and the first past it.
	 *
	 * @param callable(int): array{0: string, 1: string} $rMake
	 * @return array{0: array{0: string, 1: string}, 1: array{0: string, 1: string}}
	 */
	private static function around(int $rMax, callable $rMake): array {
		$rLast = null;
		for ($rPad = 1; ; $rPad++) {
			$rMade = $rMake($rPad);
			if (strlen($rMade[0]) > $rMax) {
				return [$rLast, $rMade];
			}
			$rLast = $rMade;
		}
	}
}
