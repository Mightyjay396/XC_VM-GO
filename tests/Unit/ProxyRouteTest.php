<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Server\ProxyRoute;

/**
 * The URL segment that routes a viewer through a proxy to its parent
 * (ProxyRoute): what its nginx holds, per install generation.
 */
final class ProxyRouteTest extends TestCase {
	public function testAProxyFromBeforeProxyKeyKeepsTheSegmentItsNginxHolds(): void {
		foreach ([[], [7 => ['proxy_key_gen' => 0]]] as $rServers) {
			$this->assertSame(md5('7_3_' . OPENSSL_EXTRA), ProxyRoute::segment(7, 3, $rServers));
		}
	}

	public function testAProxyInstalledWithAKeyIsRoutedByWhatItsInstallWrote(): void {
		$rServers = [7 => ['proxy_key_gen' => 2]];
		$this->assertSame(ProxyRoute::current(7, 3), ProxyRoute::segment(7, 3, $rServers));
		$this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', ProxyRoute::current(7, 3));
		$this->assertNotSame(md5('7_3_' . OPENSSL_EXTRA), ProxyRoute::current(7, 3));
		$this->assertNotSame(ProxyRoute::current(7, 3), ProxyRoute::current(7, 4), 'one per parent');
		$this->assertNotSame(ProxyRoute::current(7, 3), ProxyRoute::current(3, 7), 'proxy and parent are not interchangeable');
	}
}
