<?php

use XcVm\Core\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Request — the HTTP input abstraction over $_GET/$_POST/$_SERVER/$_COOKIE.
 *
 * Every instance is built from injected arrays (no superglobals touched), so
 * these exercise the real merge/precedence rules, the typed accessors, client
 * IP resolution through proxy headers, and — most importantly — the static
 * sanitizers that neutralise NUL bytes, path traversal and HTML/script payloads
 * on the way in.
 */
final class RequestTest extends TestCase {

	public function testPostTakesPrecedenceOverGetInMergedInput(): void {
		$r = new Request(['a' => '1', 'shared' => 'fromGet'], ['b' => '2', 'shared' => 'fromPost']);

		$this->assertSame('fromGet', $r->get('shared'), 'get() reads query only');
		$this->assertSame('fromPost', $r->post('shared'), 'post() reads post only');
		$this->assertSame('fromPost', $r->input('shared'), 'merged input: POST wins');
		$this->assertSame('1', $r->input('a'));
		$this->assertSame('2', $r->input('b'));
	}

	public function testInputDefaultsHasAndAll(): void {
		$r = new Request(['a' => '1'], ['b' => '2']);

		$this->assertNull($r->input('missing'));
		$this->assertSame('fallback', $r->input('missing', 'fallback'));
		$this->assertTrue($r->has('a'));
		$this->assertFalse($r->has('missing'));
		$this->assertSame(['a' => '1', 'b' => '2'], $r->all());
	}

	public function testGetIntCoercesValues(): void {
		$r = new Request([], ['n' => '42abc', 'clean' => '7']);

		$this->assertSame(42, $r->getInt('n'));
		$this->assertSame(7, $r->getInt('clean'));
		$this->assertSame(0, $r->getInt('missing'));
		$this->assertSame(99, $r->getInt('missing', 99));
	}

	public function testGetBoolCoercesValues(): void {
		$r = new Request([], ['on' => 'true', 'num' => '1', 'off' => '0', 'word' => 'no']);

		$this->assertTrue($r->getBool('on'));
		$this->assertTrue($r->getBool('num'));
		$this->assertFalse($r->getBool('off'));
		$this->assertFalse($r->getBool('word'));
		$this->assertFalse($r->getBool('missing'));
		$this->assertTrue($r->getBool('missing', true));
	}

	public function testServerAccessorsAndRequestHelpers(): void {
		$r = new Request([], [], [
			'REQUEST_METHOD'       => 'POST',
			'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
			'REQUEST_URI'          => '/admin/index.php',
			'HTTP_USER_AGENT'      => 'UnitAgent/1.0',
		], ['sid' => 'abc']);

		$this->assertSame('POST', $r->method());
		$this->assertTrue($r->isPost());
		$this->assertTrue($r->isAjax(), 'case-insensitive XMLHttpRequest');
		$this->assertSame('/admin/index.php', $r->uri());
		$this->assertSame('UnitAgent/1.0', $r->userAgent());
		$this->assertSame('abc', $r->cookie('sid'));
		$this->assertNull($r->cookie('missing'));
	}

	public function testDefaultsWhenServerIsEmpty(): void {
		$r = new Request();

		$this->assertSame('GET', $r->method());
		$this->assertFalse($r->isPost());
		$this->assertFalse($r->isAjax());
		$this->assertSame('/', $r->uri());
		$this->assertSame('', $r->userAgent());
		$this->assertSame('', $r->host());
	}

	public function testHostPrefersHttpHostThenServerName(): void {
		$this->assertSame('example.tv', (new Request([], [], ['HTTP_HOST' => 'example.tv', 'SERVER_NAME' => 'internal']))->host());
		$this->assertSame('internal', (new Request([], [], ['SERVER_NAME' => 'internal']))->host());
	}

	public function testClientIpTakesFirstValidForwardedForEntry(): void {
		$r = new Request([], [], ['HTTP_X_FORWARDED_FOR' => '203.0.113.5, 10.0.0.1', 'REMOTE_ADDR' => '10.0.0.1']);
		$this->assertSame('203.0.113.5', $r->ip());
	}

	public function testClientIpSkipsInvalidHeadersAndFallsBack(): void {
		$withRealIp = new Request([], [], ['HTTP_X_FORWARDED_FOR' => 'not-an-ip', 'HTTP_X_REAL_IP' => '198.51.100.7']);
		$this->assertSame('198.51.100.7', $withRealIp->ip(), 'invalid XFF skipped, X-Real-IP used');

		$none = new Request([], [], []);
		$this->assertSame('0.0.0.0', $none->ip(), 'no usable header → sentinel');
	}

	// ── Sanitization (security-relevant) ─────────────────────────────

	public function testConstructorStripsNullBytesAndNeutralisesTraversal(): void {
		$r = new Request(['path' => "../secret\0.txt"]);

		$value = $r->input('path');
		$this->assertStringNotContainsString(chr(0), $value, 'NUL byte removed');
		$this->assertStringNotContainsString('../', $value, 'traversal neutralised');
		$this->assertStringContainsString('&#46;&#46;/', $value);
	}

	public function testParseCleanValueNeutralisesHtmlPayloads(): void {
		$this->assertStringNotContainsString('<script', Request::parseCleanValue('<script>alert(1)</script>'));
		$this->assertStringContainsString('&#60;script', Request::parseCleanValue('<SCRIPT>x</SCRIPT>'), 'case-insensitive');

		$comment = Request::parseCleanValue('a<!--evil-->b');
		$this->assertStringNotContainsString('<!--', $comment);
		$this->assertStringNotContainsString('-->', $comment);

		$this->assertSame("O'Brien", Request::parseCleanValue("O\\'Brien"), 'stripslashes');
		$this->assertSame("a\nb", Request::parseCleanValue("a\r\nb"), 'CRLF normalised to LF');
		$this->assertSame('trimmed', Request::parseCleanValue('  trimmed  '));
		$this->assertSame('', Request::parseCleanValue(''));
	}

	public function testParseCleanKeySanitises(): void {
		$this->assertSame('page_id', Request::parseCleanKey('page_id'), 'valid key preserved');
		$this->assertSame('username', Request::parseCleanKey('user..name'), 'double-dot stripped');
		$this->assertSame('', Request::parseCleanKey('__proto__'), '__x__ pattern removed');
		$this->assertStringStartsWith('a&lt;b', Request::parseCleanKey('a<b'), 'htmlspecialchars applied');
		$this->assertSame('', Request::parseCleanKey(''));
	}

	public function testCleanGlobalsScrubsRecursivelyInPlace(): void {
		$data = [
			'nul'  => "a\0b",
			'rtl'  => 'x&#8238;y',
			'deep' => ['trav' => '../etc/passwd'],
		];
		Request::cleanGlobals($data);

		$this->assertSame('ab', $data['nul']);
		$this->assertSame('xy', $data['rtl'], 'RTL override removed');
		$this->assertSame('&#46;&#46;/etc/passwd', $data['deep']['trav']);
	}
}
