<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\Crypto\Canonical;

/**
 * The canonical request/response bytes are the panel's half of the wire
 * contract: frozen here by a vector file the Go agent also checks.
 */
final class CanonicalRequestTest extends TestCase {
	private static array $rV;

	public static function setUpBeforeClass(): void {
		self::$rV = json_decode((string) file_get_contents(dirname(__DIR__) . '/Support/cluster_canonical_vectors.json'), true);
	}

	private function request(array $rOver = []): array {
		$r = self::$rV['request'];
		return array_merge([
			'proto' => $r['proto'], 'agent' => $r['agent'], 'method' => $r['method'], 'path' => $r['path'], 'query' => $r['query'],
			'content_type' => $r['content_type'], 'content_encoding' => $r['content_encoding'], 'node' => $r['node'],
			'epoch' => $r['epoch'], 'ts_ms' => $r['ts_ms'], 'nonce' => hex2bin($r['nonce']),
		], $rOver);
	}

	public function testRequestAndResponseMatchTheVectors(): void {
		$r = self::$rV['request'];
		$rCtx = Canonical::request($this->request());
		$this->assertSame($r['context'], bin2hex($rCtx));
		$this->assertSame($r['canonical_query'], Canonical::query($r['query']));
		$this->assertSame($r['mac'], bin2hex(Canonical::mac(hex2bin($r['mac_key']), $rCtx, $r['body'])));

		$s = self::$rV['response'];
		$rRes = Canonical::response($rCtx, $s['status'], $s['content_type'], $s['ts_ms'], hex2bin($s['nonce']));
		$this->assertSame($s['context'], bin2hex($rRes));
		$this->assertSame($s['mac'], bin2hex(Canonical::mac(hex2bin($s['mac_key']), $rRes, $s['body'])));
	}

	public function testEveryCoveredFieldChangesTheMac(): void {
		$rKey = random_bytes(32);
		$rBase = Canonical::mac($rKey, Canonical::request($this->request()), 'body');
		foreach ([
			'proto' => 2, 'agent' => 'xc_agent/0.1.1', 'method' => 'GET', 'path' => '/cluster/v1/hello', 'query' => 'b=3',
			'content_type' => 'application/json', 'content_encoding' => 'gzip', 'node' => 'sid:12', 'epoch' => 4,
			'ts_ms' => 1800000000124, 'nonce' => str_repeat("\x01", 16),
		] as $rField => $rValue) {
			$this->assertNotSame($rBase, Canonical::mac($rKey, Canonical::request($this->request([$rField => $rValue])), 'body'), $rField);
		}
		$this->assertNotSame($rBase, Canonical::mac($rKey, Canonical::request($this->request()), 'bodY'), 'body');
		$this->assertTrue(Canonical::verifyMac($rKey, Canonical::request($this->request()), 'body', $rBase));
		$this->assertFalse(Canonical::verifyMac(random_bytes(32), Canonical::request($this->request()), 'body', $rBase));
	}

	public function testNormalisationThatMustNotChangeTheMac(): void {
		$rA = Canonical::request($this->request(['method' => 'POST', 'content_type' => 'application/octet-stream', 'query' => 'a=%41&b=2&a=x%20y']));
		$this->assertSame(Canonical::request($this->request()), $rA);
	}

	public function testResponseIsBoundToItsRequest(): void {
		$rNonce = random_bytes(16);
		$rOne = Canonical::response(Canonical::request($this->request()), 200, 'x', 1, $rNonce);
		$rTwo = Canonical::response(Canonical::request($this->request(['nonce' => random_bytes(16)])), 200, 'x', 1, $rNonce);
		$this->assertNotSame($rOne, $rTwo);
	}

	public function testWindowIsNinetySeconds(): void {
		$rNow = 1800000000000;
		$this->assertTrue(Canonical::withinWindow($rNow - 90000, $rNow));
		$this->assertTrue(Canonical::withinWindow($rNow + 90000, $rNow));
		$this->assertFalse(Canonical::withinWindow($rNow - 90001, $rNow));
		$this->assertFalse(Canonical::withinWindow($rNow + 90001, $rNow));
	}

	public function testPathsOutsideTheApiAreRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		Canonical::request($this->request(['path' => '/api']));
	}

	public function testHeaderParsing(): void {
		$rOk = [
			'x-xcvm-proto' => '1', 'X-XCVM-Agent' => 'xc_agent/0.1.0', 'X-XCVM-Node' => '0f8fad5b-d9cb-469f-a165-70867728950e',
			'X-XCVM-Epoch' => '3', 'X-XCVM-Ts' => '1800000000123', 'X-XCVM-Nonce' => str_repeat('ab', 16), 'X-XCVM-Sig' => str_repeat('cd', 32),
		];
		$rParsed = Canonical::parseHeaders($rOk);
		$this->assertSame(3, $rParsed['epoch']);
		$this->assertSame(16, strlen($rParsed['nonce']));
		$this->assertSame(32, strlen($rParsed['sig']));
		$this->assertNotNull(Canonical::parseHeaders(['X-XCVM-Node' => 'sid:12'] + $rOk), 'enrolment by code');
		foreach ([
			'X-XCVM-Node' => 'sid:0', 'X-XCVM-Epoch' => '-1', 'X-XCVM-Ts' => '12', 'X-XCVM-Nonce' => 'zz', 'X-XCVM-Sig' => 'ab',
			'X-XCVM-Proto' => '0', 'X-XCVM-Agent' => "bad agent",
		] as $rName => $rBad) {
			$rHeaders = $rOk;
			unset($rHeaders['x-xcvm-proto']);
			$rHeaders['X-XCVM-Proto'] = '1';
			$rHeaders[$rName] = $rBad;
			$this->assertNull(Canonical::parseHeaders($rHeaders), $rName);
		}
		$rMissing = $rOk;
		unset($rMissing['X-XCVM-Nonce']);
		$this->assertNull(Canonical::parseHeaders($rMissing));
	}
}
