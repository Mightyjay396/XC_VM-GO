<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\ServerSyncOpensslExtraCommand;
use XcVm\Core\Config\OpensslExtra;

/**
 * server:sync-openssl-extra — queues MAIN's OPENSSL_EXTRA for the streaming
 * LBs that report another one. A node that does not report a fingerprint runs
 * a build whose root cron cannot apply the value, and an offline node would
 * leave it waiting in the signals table, so both need --force; proxies and the
 * MAIN never get it.
 */
final class ServerSyncOpensslExtraCommandTest extends TestCase {

	private function lb(array $rOverride = []): array {
		return array_merge([
			'id'              => 2,
			'is_main'         => 0,
			'server_type'     => 0,
			'server_online'   => true,
			'server_hardware' => json_encode(['cores' => 4, OpensslExtra::HARDWARE_KEY => OpensslExtra::fingerprint('lb-extra')]),
		], $rOverride);
	}

	private function skip(array $rServer, bool $rForce): ?string {
		return ServerSyncOpensslExtraCommand::skipReason($rServer, OpensslExtra::fingerprint('main-extra'), $rForce);
	}

	public function testAnLbThatReportsAnotherValueIsSynced(): void {
		$this->assertNull($this->skip($this->lb(), false));
		$this->assertNull($this->skip($this->lb(), true));
	}

	public function testAnLbInSyncIsSyncedOnlyWhenForced(): void {
		$rInSync = $this->lb(['server_hardware' => json_encode([OpensslExtra::HARDWARE_KEY => OpensslExtra::fingerprint('main-extra')])]);

		$this->assertSame('already in sync', $this->skip($rInSync, false));
		$this->assertNull($this->skip($rInSync, true));
	}

	public function testAnOutdatedOrOfflineLbIsSyncedOnlyWhenForced(): void {
		$rOutdated = $this->lb(['server_hardware' => json_encode(['cores' => 4])]);
		$rOffline = $this->lb(['server_online' => false]);

		$this->assertStringStartsWith('unknown (node not updated)', (string) $this->skip($rOutdated, false));
		$this->assertNull($this->skip($rOutdated, true));
		$this->assertStringStartsWith('offline', (string) $this->skip($rOffline, false));
		$this->assertNull($this->skip($rOffline, true));
	}

	public function testProxiesAndTheMainAreNeverSynced(): void {
		$this->assertNotNull($this->skip($this->lb(['server_type' => 1]), true));
		$this->assertNotNull($this->skip($this->lb(['is_main' => 1]), true));
	}
}
