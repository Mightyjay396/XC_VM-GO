<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\SshSession;

/**
 * A failed SSH connect says why (libssh2's first warning, not only "Failed to
 * connect"), and what to do when it is the key exchange: the bundled libssh2
 * (before 1.11) signs RSA host keys only with ssh-rsa, which OpenSSH 8.8+
 * refuses, so a node offering only an RSA host key cannot be installed.
 */
final class SshConnectErrorTest extends TestCase {
	public function testAKeyExchangeFailureNamesTheHostKeyRemedy(): void {
		$rText = SshSession::explain(['Error starting up SSH connection(-5): Unable to exchange encryption keys', 'Unable to connect to 192.0.2.7']);
		$this->assertStringStartsWith('Error starting up SSH connection(-5): Unable to exchange encryption keys; Unable to connect to 192.0.2.7', $rText);
		$this->assertStringContainsString('ssh-keygen -A', $rText);
	}

	public function testAnyOtherFailureIsReportedAsLibssh2SaysIt(): void {
		$this->assertSame('Unable to connect to 192.0.2.7', SshSession::explain(['Unable to connect to 192.0.2.7']));
		$this->assertSame('', SshSession::explain([]));
	}
}
