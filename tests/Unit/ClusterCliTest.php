<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\Crypto\ClusterCrypto;
use XcVm\Core\Cluster\Crypto\ClusterCryptoFactory;
use XcVm\Domain\Cluster\ClusterCli;

/**
 * What the cluster CLI commands share: the crypto or the line saying why
 * there is none.
 */
final class ClusterCliTest extends TestCase {
	protected function tearDown(): void {
		ClusterCryptoFactory::useProbe(null);
	}

	public function testWithoutTheExtensionEachCallerPrintsItsOwnLine(): void {
		ClusterCryptoFactory::useProbe(static fn() => null);
		$rCases = [
			[[], "Cluster API unavailable: cluster crypto unavailable: NO_EXTENSION. Exiting\n"],
			[[ClusterCli::UNAVAILABLE], "Cluster API unavailable: cluster crypto unavailable: NO_EXTENSION\n"],
			[["Cluster API unavailable (%s); the node stays legacy\n"], "Cluster API unavailable (cluster crypto unavailable: NO_EXTENSION); the node stays legacy\n"],
		];
		foreach ($rCases as [$rArgs, $rLine]) {
			ob_start();
			$rCrypto = ClusterCli::crypto(...$rArgs);
			$this->assertSame($rLine, ob_get_clean());
			$this->assertNull($rCrypto);
		}
	}

	public function testWithTheExtensionItPrintsNothing(): void {
		ClusterCryptoFactory::useProbe(static fn() => ['api' => ClusterCryptoFactory::API_MAX, 'ext_version' => '3.1.0']);
		ob_start();
		$rCrypto = ClusterCli::crypto();
		$this->assertSame('', ob_get_clean());
		$this->assertInstanceOf(ClusterCrypto::class, $rCrypto);
	}
}
