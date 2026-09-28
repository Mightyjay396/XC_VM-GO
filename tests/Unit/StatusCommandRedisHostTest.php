<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\StatusCommand;

/**
 * StatusCommand::configureRedisLb() points a load balancer's config.enc at
 * MAIN's Redis. The host is MAIN's raw `private_ip` or `server_ip`, never the
 * `private_url_ip` URL (`http://<ip>:<port>/`) built from it, which Redis
 * cannot connect to.
 */
final class StatusCommandRedisHostTest extends TestCase {
	/** @return array<int, array<string, mixed>> */
	private static function servers(array $rMain): array {
		return [
			2 => ['id' => 2, 'is_main' => 0, 'server_ip' => '203.0.113.2', 'private_ip' => '10.0.0.2'],
			1 => ['id' => 1, 'is_main' => 1] + $rMain,
		];
	}

	public function testThePrivateAddressWinsAndIsNeverAUrl(): void {
		$rHost = StatusCommand::mainRedisHost(self::servers([
			'server_ip' => '203.0.113.1',
			'private_ip' => '10.0.0.1',
			'private_url_ip' => 'http://10.0.0.1:80/',
		]));
		$this->assertSame('10.0.0.1', $rHost);
	}

	public function testWithoutAPrivateAddressThePublicOne(): void {
		$this->assertSame('203.0.113.1', StatusCommand::mainRedisHost(self::servers(['server_ip' => '203.0.113.1', 'private_ip' => ''])));
		$this->assertSame('203.0.113.1', StatusCommand::mainRedisHost(self::servers(['server_ip' => ' 203.0.113.1 ', 'private_ip' => null])));
		$this->assertSame('main.example.net', StatusCommand::mainRedisHost(self::servers(['server_ip' => 'main.example.net'])));
		$this->assertSame('2001:db8::1', StatusCommand::mainRedisHost(self::servers(['server_ip' => '203.0.113.1', 'private_ip' => '2001:db8::1'])));
	}

	public function testAnythingButAnAddressOrAHostNameIsRefused(): void {
		$this->assertSame('203.0.113.1', StatusCommand::mainRedisHost(self::servers(['server_ip' => '203.0.113.1', 'private_ip' => 'http://10.0.0.1:80/'])), 'a URL in the column falls back to server_ip');
		$this->assertNull(StatusCommand::mainRedisHost(self::servers(['server_ip' => 'http://203.0.113.1/', 'private_ip' => ''])));
		$this->assertNull(StatusCommand::mainRedisHost(self::servers(['server_ip' => '', 'private_ip' => '10.0.0.1; rm -rf /'])));
		$this->assertNull(StatusCommand::mainRedisHost([2 => ['id' => 2, 'is_main' => 0, 'server_ip' => '203.0.113.2']]), 'no MAIN row');
	}
}
