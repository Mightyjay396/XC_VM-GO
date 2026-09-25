<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\Crypto\Box;
use XcVm\Core\Cluster\Crypto\Canonical;
use XcVm\Core\Cluster\Crypto\ClusterCryptoFactory;
use XcVm\Core\Cluster\Crypto\ClusterRefusedException;
use XcVm\Core\Cluster\Crypto\PanelSig;
use XcVm\Core\Cluster\Crypto\Seal;
use XcVm\Tests\Support\ClusterReference as Ref;

/**
 * The panel facade against a real xcvm_core: init, token issue, the agent's
 * side of the token, the session MAIN derives, and a BOX round trip in both
 * directions. The panel and the extension must agree on every key.
 *
 * Opt-in: it needs a test-hooks build (XCVM_TEST_VERDICT_PK_HEX, to fake a
 * licence) and XCVM_CONFIG_DIR pointing at a throwaway directory, so it never
 * touches a real install:
 *
 *   XCVM_CONFIG_DIR=$(mktemp -d) php -d extension=…/.build_ext/dev/xcvm_core.so \
 *     tests/phpunit.phar -c tests/phpunit.xml.dist --filter ClusterExtensionIntegrationTest
 */
final class ClusterExtensionIntegrationTest extends TestCase {
	private string $rDir;
	private string $rVerdictSk;

	protected function setUp(): void {
		$rDir = (string) getenv('XCVM_CONFIG_DIR');
		if (!class_exists('XC_VM', false) || !method_exists('XC_VM', 'cluster_session')) {
			$this->markTestSkipped('xcvm_core with the cluster API is not loaded');
		}
		if ($rDir === '' || !str_starts_with(realpath($rDir) ?: '', realpath(sys_get_temp_dir()) ?: '/tmp') || file_exists($rDir . '/config.enc')) {
			$this->markTestSkipped('XCVM_CONFIG_DIR must be a throwaway directory under the temp dir');
		}
		$this->rDir = $rDir;
		$rPair = sodium_crypto_sign_keypair();
		$this->rVerdictSk = sodium_crypto_sign_secretkey($rPair);
		putenv('XCVM_TEST_VERDICT_PK_HEX=' . bin2hex(sodium_crypto_sign_publickey($rPair)));
		putenv('XCVM_TEST_CLUSTER_LIC_TTL=0');
		$rPayload = json_encode(['v' => 1, 'jti' => bin2hex(random_bytes(16)), 'hwid' => \XC_VM::install_id(), 'exp' => null, 'kid' => 1, 'iat' => time()], JSON_UNESCAPED_SLASHES);
		$rB64 = static fn(string $b) => rtrim(strtr(base64_encode($b), '+/', '-_'), '=');
		file_put_contents($this->rDir . '/activation_key', 'XCVM1.' . $rB64($rPayload) . '.' . $rB64(sodium_crypto_sign_detached($rPayload, $this->rVerdictSk)));
		if (!\XC_VM::license_valid()) {
			$this->markTestSkipped('not a test-hooks build (the fake licence was not accepted)');
		}
	}

	protected function tearDown(): void {
		putenv('XCVM_TEST_VERDICT_PK_HEX');
		putenv('XCVM_TEST_CLUSTER_LIC_TTL');
		if (isset($this->rDir)) {
			@unlink($this->rDir . '/activation_key');
		}
	}

	public function testPanelAndExtensionAgreeEndToEnd(): void {
		$rCrypto = ClusterCryptoFactory::create();
		$rRoot = $rCrypto->init();
		$this->assertSame(32, strlen($rRoot['panel_sign_pub']));
		$this->assertSame(1, $rCrypto->info()['api']);

		// The agent: static node key, per-epoch ephemeral key.
		$rNodePair = sodium_crypto_sign_keypair();
		$rEphSk = random_bytes(32);
		$rUuid = '0f8fad5b-d9cb-469f-a165-70867728950e';
		$rIssued = $rCrypto->tokenIssue([
			'node_uuid' => $rUuid, 'server_id' => 7, 'gen' => 1, 'epoch' => 1, 'rotation_min' => 60,
			'node_sign_pub' => sodium_crypto_sign_publickey($rNodePair), 'agent_eph_pub' => sodium_crypto_scalarmult_base($rEphSk),
		]);
		$this->assertSame(15, $rIssued['grace_min']);

		// Agent side: open with the ephemeral key, verify the panel signature, derive.
		$rBody = Seal::open($rEphSk, 'token', $rUuid, $rIssued['token_sealed']);
		$this->assertNotNull($rBody);
		$rLen = unpack('N', substr($rBody, 0, 4))[1];
		$rDoc = substr($rBody, 4, $rLen);
		$this->assertTrue(PanelSig::verify($rRoot['panel_sign_pub'], 'tok', $rDoc, substr($rBody, 4 + $rLen)));
		$rAgentKeys = Ref::sessionKeys(hex2bin(json_decode($rDoc, true)['token']));

		// MAIN side: the session of the stored epoch record.
		$rSession = $rCrypto->session($rIssued['epoch_record'], $rUuid);
		$this->assertSame($rAgentKeys['mac_up'], $rSession->rMacUp);
		$this->assertSame($rAgentKeys['enc_down'], $rSession->rEncDown);

		// A request up and a reply down, with the panel's canonical MAC.
		$rCtx = Canonical::request(['proto' => 1, 'agent' => 'test', 'method' => 'POST', 'path' => '/cluster/v1/heartbeat', 'query' => '',
			'content_type' => 'application/octet-stream', 'content_encoding' => '', 'node' => $rUuid, 'epoch' => 1, 'ts_ms' => time() * 1000, 'nonce' => random_bytes(16)]);
		$rUp = Box::box($rAgentKeys['enc_up'], $rCtx, '{"hb":1}');
		$this->assertTrue(Canonical::verifyMac($rSession->rMacUp, $rCtx, $rUp, Canonical::mac($rAgentKeys['mac_up'], $rCtx, $rUp)));
		$this->assertSame('{"hb":1}', Box::open($rSession->rEncUp, $rCtx, $rUp));
		$rDown = Box::box($rSession->rEncDown, $rCtx, '{"ok":true}');
		$this->assertSame('{"ok":true}', Box::open($rAgentKeys['enc_down'], $rCtx, $rDown));

		// A record sealed for this node does not open as another node.
		$this->expectException(ClusterRefusedException::class);
		$rCrypto->session($rIssued['epoch_record'], '11111111-2222-4333-8444-555555555555');
	}

	public function testSignedRecordsVerifyWithThePanelClass(): void {
		$rCrypto = ClusterCryptoFactory::create();
		$rPub = $rCrypto->init()['panel_sign_pub'];
		$rCmd = json_encode(['v' => 1, 'type' => 'conn.drop', 'exp' => time() + 300, 'iat' => time(), 'cmd_id' => str_repeat('a', 32), 'seq' => 1,
			'node_uuid' => '0f8fad5b-d9cb-469f-a165-70867728950e', 'gen' => 1, 'dedupe_key' => null, 'args' => ['uuid' => 'x']]);
		$this->assertSame('R', $rCrypto->recordClass('cmd', $rCmd));
		$this->assertTrue(PanelSig::verify($rPub, 'cmd', $rCmd, $rCrypto->sign('cmd', $rCmd)));
		$this->assertFalse(PanelSig::verify($rPub, 'blk', $rCmd, $rCrypto->sign('cmd', $rCmd)));
	}
}
