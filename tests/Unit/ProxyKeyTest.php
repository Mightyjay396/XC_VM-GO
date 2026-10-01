<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Server\ProxyKey;

/**
 * A proxy's control channel with MAIN (ProxyKey, D8): the key, the signed
 * request, the answer bound to its nonce, and MAIN's secret. The vectors are
 * the ones XC_VM_Proxy's check runs against (tests/proxy_auth_check.php).
 */
final class ProxyKeyTest extends TestCase {
	private const KEY = "\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02";

	private const NONCE = '00000000000000000000000000000000';

	private const BODY = 'server_id=7&stats%5Bcpu%5D=1';

	private const HEADER = '1800000000000.00000000000000000000000000000000.af67cd2b8e51c1a449759f4fbf3aed523a75c26c203d9727fb230832529b42fd';

	private const ANSWER = '{"payload":"[{\"action\":\"flush\"}]","mac":"be3ab90d1b93347ab25ee8dc3d9704014194f5a11beecdf39bf2f2af795dbe70"}';

	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm_proxykey_' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir);
		ProxyKey::useFile($this->rDir . 'proxy_secret');
	}

	protected function tearDown(): void {
		ProxyKey::useFile(null);
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	public function testTheVectorsTheProxyChecksAgainst(): void {
		$this->assertSame(self::HEADER, ProxyKey::requestAuth(self::KEY, 7, 1800000000000, self::NONCE, self::BODY));
		$this->assertSame(self::ANSWER, ProxyKey::answer(self::KEY, 7, self::NONCE, [['action' => 'flush']]));
	}

	public function testEachProxyAndInstallHasItsOwnKey(): void {
		$this->assertNotSame(ProxyKey::derive('s', 7, 1), ProxyKey::derive('s', 8, 1));
		$this->assertNotSame(ProxyKey::derive('s', 7, 1), ProxyKey::derive('s', 7, 2), 'a reinstall: the old key opens nothing');
		$this->assertSame(32, strlen(ProxyKey::derive('s', 7, 1)));
	}

	public function testOnlyTheProxysOwnFreshRequestVerifies(): void {
		$rNow = 1800000000000;
		$this->assertSame(['ts' => $rNow, 'nonce' => self::NONCE], ProxyKey::verifyRequest(self::KEY, 7, self::HEADER, self::BODY, $rNow + 1000));
		$this->assertNull(ProxyKey::verifyRequest(str_repeat("\x03", 32), 7, self::HEADER, self::BODY, $rNow), 'another key');
		$this->assertNull(ProxyKey::verifyRequest(self::KEY, 8, self::HEADER, self::BODY, $rNow), 'another proxy');
		$this->assertNull(ProxyKey::verifyRequest(self::KEY, 7, self::HEADER, self::BODY . '&x=1', $rNow), 'a changed body');
		$this->assertNull(ProxyKey::verifyRequest(self::KEY, 7, self::HEADER, self::BODY, $rNow + ProxyKey::SKEW_MS + 1), 'too old');
		$this->assertNull(ProxyKey::verifyRequest(self::KEY, 7, 'garbage', self::BODY, $rNow));
	}

	public function testAdmissionNeedsAKeyedProxyAndAFreshNonce(): void {
		file_put_contents($this->rDir . 'proxy_secret', str_repeat('ab', 32));
		$rKey = ProxyKey::forServer(7, 3);
		$rHeader = ProxyKey::requestAuth((string) $rKey, 7, 1800000000000, self::NONCE, self::BODY);
		$rClaims = [];
		$rClaim = static function (string $rNode, string $rNonce, int $rTs) use (&$rClaims): bool {
			$rClaims[] = [$rNode, $rNonce, $rTs];
			return count($rClaims) === 1;
		};
		$rRow = ['id' => 7, 'server_type' => 1, 'proxy_key_gen' => 3];

		$this->assertSame(['id' => 7, 'key' => $rKey, 'nonce' => self::NONCE], ProxyKey::admit($rRow, $rHeader, self::BODY, 1800000000000, $rClaim));
		$this->assertSame([['proxy-7', self::NONCE, 1800000000000]], $rClaims);
		$this->assertNull(ProxyKey::admit($rRow, $rHeader, self::BODY, 1800000000000, $rClaim), 'a replay: the nonce is spent');
		$this->assertNull(ProxyKey::admit(['proxy_key_gen' => 2] + $rRow, $rHeader, self::BODY, 1800000000000, $rClaim), 'the key of an earlier install');
		$this->assertNull(ProxyKey::admit(['server_type' => 2] + $rRow, $rHeader, self::BODY, 1800000000000, $rClaim), 'not a proxy');
		$this->assertNull(ProxyKey::admit(['proxy_key_gen' => 0] + $rRow, $rHeader, self::BODY, 1800000000000, $rClaim), 'no key yet');
		$this->assertCount(2, $rClaims, 'no nonce burnt before the MAC verified');
	}

	public function testTheSecretIsMadeOnceAndNeverReplaced(): void {
		$rFirst = ProxyKey::secret();
		$this->assertSame(32, strlen((string) $rFirst));
		$this->assertSame(0600, fileperms($this->rDir . 'proxy_secret') & 0777);
		$this->assertSame($rFirst, ProxyKey::secret());
		file_put_contents($this->rDir . 'proxy_secret', 'not a secret');
		$this->assertNull(ProxyKey::secret(), 'an unreadable secret is not a missing one');
		$this->assertSame("not a secret", file_get_contents($this->rDir . 'proxy_secret'));
	}
}
