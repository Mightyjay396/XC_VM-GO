<?php

use XcVm\Core\Config\DomainResolver;
use PHPUnit\Framework\TestCase;

/**
 * DomainResolver::resolve() — builds a server's public broadcast base URL.
 *
 * Covers the non-proxied path (enable_proxy = 0, use_mdomain_in_lists = 0),
 * which is where the protocol/domain/port logic lives: forced vs kept protocol,
 * stripping the port from the Host header, and the domain_name / server_ip
 * fallbacks when no Host is present. The proxied branch (ConnectionTracker +
 * CacheReader, random proxy pick) is left for an integration-style test.
 *
 * resolve() reads the request via $_SERVER and the server/settings tables via
 * the $rServers/$rSettings globals, so each test seeds those and tearDown
 * restores the environment.
 */
final class DomainResolverTest extends TestCase {

	private array $serverBackup;

	protected function setUp(): void {
		$this->serverBackup = $_SERVER;

		$GLOBALS['rServers'] = [
			1 => [
				'enable_proxy'         => 0,
				'server_protocol'      => 'http',
				'http_broadcast_port'  => 8080,
				'https_broadcast_port' => 8443,
				'domain_name'          => 'my.tv',
				'server_ip'            => '198.51.100.9',
				'server_type'          => 0,
				'is_main'              => 1,
			],
		];
		$GLOBALS['rSettings'] = [
			'keep_protocol'        => 0,
			'use_mdomain_in_lists' => 0,
		];

		unset($_SERVER['SERVER_PORT'], $_SERVER['HTTPS']);
		$_SERVER['HTTP_HOST'] = 'cdn.example.tv';
	}

	protected function tearDown(): void {
		$_SERVER = $this->serverBackup;
		unset($GLOBALS['rServers'], $GLOBALS['rSettings']);
	}

	public function testForcedSslBuildsHttpsUrlFromHost(): void {
		$this->assertSame('https://cdn.example.tv:8443/', DomainResolver::resolve(1, true));
	}

	public function testUsesServerProtocolWhenNotKeepingProtocol(): void {
		$this->assertSame('http://cdn.example.tv:8080/', DomainResolver::resolve(1));
	}

	public function testStripsPortFromHostHeader(): void {
		$_SERVER['HTTP_HOST'] = 'cdn.example.tv:9000';
		$this->assertSame('http://cdn.example.tv:8080/', DomainResolver::resolve(1));
	}

	public function testKeepProtocolUsesHttpsOnPort443(): void {
		$GLOBALS['rSettings']['keep_protocol'] = 1;
		$_SERVER['SERVER_PORT'] = 443;
		$this->assertSame('https://cdn.example.tv:8443/', DomainResolver::resolve(1));
	}

	public function testKeepProtocolStaysHttpOnPlainPort(): void {
		$GLOBALS['rSettings']['keep_protocol'] = 1;
		$_SERVER['SERVER_PORT'] = 80;
		$this->assertSame('http://cdn.example.tv:8080/', DomainResolver::resolve(1));
	}

	public function testFallsBackToDomainNameWhenHostEmpty(): void {
		$_SERVER['HTTP_HOST'] = '';
		$GLOBALS['rServers'][1]['domain_name'] = 'first.tv,second.tv';

		// First entry of the comma-separated list is used.
		$this->assertSame('http://first.tv:8080/', DomainResolver::resolve(1));
	}

	public function testDomainNameHttpSchemePrefixIsStripped(): void {
		$_SERVER['HTTP_HOST'] = '';
		$GLOBALS['rServers'][1]['domain_name'] = 'http://first.tv/';

		// NOTE: only the http:// prefix strips cleanly. An https:// prefix would
		// leave 'https:first.tv' because '/' is removed before 'https://' in the
		// str_replace search order — a latent bug in DomainResolver, captured here
		// so a future fix updates this expectation deliberately.
		$this->assertSame('http://first.tv:8080/', DomainResolver::resolve(1));
	}

	public function testFallsBackToServerIpWhenNoHostOrDomain(): void {
		$_SERVER['HTTP_HOST'] = '';
		$GLOBALS['rServers'][1]['domain_name'] = '';

		$this->assertSame('http://198.51.100.9:8080/', DomainResolver::resolve(1));
	}
}
