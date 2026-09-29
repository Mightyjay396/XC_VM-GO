<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\Crypto\Box;
use XcVm\Core\Cluster\Crypto\PanelSig;
use XcVm\Core\Cluster\Crypto\Seal;
use XcVm\Tests\Support\ClusterReference as Ref;

/**
 * The panel's BOX, SEAL and panel-signature code against the shared vectors
 * (tests/Support/cluster_vectors.json), which xcvm_core generates from its
 * shipped Rust and the Go agent must also pass. A byte of difference here
 * means the panel and the extension no longer speak the same protocol.
 */
final class ClusterVectorsTest extends TestCase {
	private static array $rV;

	public static function setUpBeforeClass(): void {
		self::$rV = Ref::vectors();
	}

	public function testVectorFileVersion(): void {
		$this->assertSame(1, self::$rV['version']);
	}

	public function testSealMatchesTheExtension(): void {
		$v = self::$rV['seal_v1'];
		$this->assertSame($v['recipient_pub'], bin2hex(sodium_crypto_scalarmult_base(hex2bin($v['recipient_sk']))));
		$rSealed = Seal::sealWith(hex2bin($v['eph_sk']), hex2bin($v['nonce']), hex2bin($v['recipient_pub']), $v['purpose'], $v['context'], $v['plaintext']);
		$this->assertSame($v['sealed'], bin2hex($rSealed));
		$this->assertSame($v['plaintext'], Seal::open(hex2bin($v['recipient_sk']), $v['purpose'], $v['context'], $rSealed));
	}

	public function testSealBindsPurposeContextAndKey(): void {
		$v = self::$rV['seal_v1'];
		$rSealed = hex2bin($v['sealed']);
		$rSk = hex2bin($v['recipient_sk']);
		$this->assertNull(Seal::open($rSk, 'bundle', $v['context'], $rSealed), 'other purpose');
		$this->assertNull(Seal::open($rSk, $v['purpose'], 'other-node', $rSealed), 'other context');
		$this->assertNull(Seal::open(random_bytes(32), $v['purpose'], $v['context'], $rSealed), 'other key');
		$rFlip = $rSealed;
		$rFlip[50] = chr(ord($rFlip[50]) ^ 1);
		$this->assertNull(Seal::open($rSk, $v['purpose'], $v['context'], $rFlip), 'tampered');
		$this->assertNull(Seal::open($rSk, $v['purpose'], $v['context'], 'xb1' . substr($rSealed, 3)), 'wrong magic');
	}

	public function testSealRefusesALowOrderRecipient(): void {
		$this->expectException(InvalidArgumentException::class);
		Seal::seal(str_repeat("\0", 32), 'token', 'x', 'pt');
	}

	public function testBoxMatchesTheExtension(): void {
		$v = self::$rV['box_v1'];
		$rBoxed = Box::boxWith(hex2bin($v['key']), hex2bin($v['nonce']), $v['context'], $v['plaintext']);
		$this->assertSame($v['boxed'], bin2hex($rBoxed));
		$this->assertSame($v['plaintext'], Box::open(hex2bin($v['key']), $v['context'], $rBoxed));
		$this->assertNull(Box::open(hex2bin($v['key']), $v['context'] . ' ', $rBoxed), 'context is bound');
		$this->assertNull(Box::open(hex2bin($v['key']), $v['context'], 'plain body'), 'plaintext refused');
	}

	public function testPanelSignaturesMatchTheExtension(): void {
		$s = self::$rV['sign'];
		$rPub = hex2bin($s['public']);
		$this->assertSame($s['public'], bin2hex(Ref::panelPub(hex2bin($s['seed']))));
		foreach ($s['cases'] as $c) {
			$this->assertSame($c['sig'], bin2hex(Ref::panelSign(hex2bin($s['seed']), $c['tag'], $c['payload'])), $c['tag']);
			$this->assertTrue(PanelSig::verify($rPub, $c['tag'], $c['payload'], hex2bin($c['sig'])), $c['tag']);
		}
	}

	public function testBindingAndClusterKey(): void {
		$b = self::$rV['binding'];
		$rB = Ref::binding($b['jti'], hex2bin($b['activation_key_sha256']));
		$this->assertSame($b['b'], bin2hex($rB));
		$this->assertSame($b['kid'], substr(bin2hex($rB), 0, 8));
		$this->assertSame($b['ck'], bin2hex(Ref::ck(hex2bin($b['prk']), $rB)));
	}

	/** The agent's side of a token, end to end: open, verify, derive, then speak BOX. */
	public function testTokenChain(): void {
		$t = self::$rV['token'];
		$this->assertSame($t['agent_eph_pub'], bin2hex(sodium_crypto_scalarmult_base(hex2bin($t['agent_eph_sk']))));
		$this->assertSame($t['z'], bin2hex(sodium_crypto_scalarmult(hex2bin($t['ext_eph_sk']), hex2bin($t['agent_eph_pub']))));

		$rMsg = Ref::tokenMsg($t['node_uuid'], $t['server_id'], $t['gen'], hex2bin($t['node_sign_pub']), $t['epoch'], $t['nbf'], $t['exp'], hex2bin($t['z']));
		$this->assertSame($t['token_msg'], bin2hex($rMsg));
		$rCk = Ref::ck(hex2bin($t['prk']), hex2bin($t['b']));
		$this->assertSame($t['t'], hash_hmac('sha256', $rMsg, $rCk));

		$rBody = Seal::open(hex2bin($t['agent_eph_sk']), 'token', $t['node_uuid'], hex2bin($t['token_sealed']));
		$this->assertNotNull($rBody, 'the token opens with the per-epoch key');
		$rLen = unpack('N', substr($rBody, 0, 4))[1];
		$rDoc = substr($rBody, 4, $rLen);
		$this->assertSame($t['doc'], $rDoc);
		$this->assertTrue(PanelSig::verify(hex2bin($t['panel_sign_pub']), 'tok', $rDoc, substr($rBody, 4 + $rLen)));

		$rKeys = Ref::sessionKeys(hex2bin(json_decode($rDoc, true)['token']));
		foreach (['mac_up', 'mac_down', 'enc_up', 'enc_down'] as $k) {
			$this->assertSame($t['k_' . $k], bin2hex($rKeys[$k]), $k);
		}
		$rUp = Box::box($rKeys['enc_up'], 'ctx', 'heartbeat');
		$this->assertSame('heartbeat', Box::open($rKeys['enc_up'], 'ctx', $rUp));
		$this->assertNull(Box::open($rKeys['enc_down'], 'ctx', $rUp), 'directions use different keys');
	}

	/**
	 * The Go agent keeps its own copy of the shared files
	 * (XC_VM_Fanout/internal/clustercrypto/testdata/), and each side only ever
	 * tests itself against the copy it holds — so a regenerated file that is
	 * not copied leaves them speaking different protocols while every suite
	 * passes. These digests are the tripwire: they are recorded in the Go test
	 * too, and xcvm_core's vectors.rs records those of the two files it
	 * generates (cluster_vectors.json, and cluster_commands.json, its command
	 * registry), which its tests/conformance/fixtures/ holds byte for byte.
	 * Whichever side changes first fails until all are updated.
	 *
	 * Regenerating the vectors is a protocol change (ADR 0004): copy the files
	 * to the agent's testdata, update the digests in every test, raise `proto`
	 * and keep accepting N−1.
	 */
	public function testTheVectorFilesAreTheOnesTheAgentHolds(): void {
		$rFiles = ['cluster_vectors.json', 'cluster_canonical_vectors.json', 'cluster_commands.json', 'cluster_dataplane_vectors.json'];
		$this->assertSame([
			'cluster_vectors.json' => '6cb3272fc1f97e2bc547105b8d5e42c77dcbf805e53d3eae78c89798d8f89645',
			'cluster_canonical_vectors.json' => 'ee0fd4b297f02e2e1c1277a1433d1febe9c520414ce107addb524ff2b16799c3',
			'cluster_commands.json' => 'e091471a6087c75cc85f1a7ce7e7f89b59b3e1bea50bbae6505b93f82b69fa62',
			'cluster_dataplane_vectors.json' => '7016c21b9600dedc1745f4644772ff53e899ae8f83a14c27357c95172e7498b0',
		], array_combine($rFiles, array_map(static fn(string $rName): string => (string) hash_file('sha256', dirname(__DIR__) . '/Support/' . $rName), $rFiles)));
	}
}
