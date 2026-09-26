<?php

namespace XcVm\Cli\Commands;

/**
 * One SSH session to a node, for `server:enrol` and `cluster:reenrol`:
 * connect, read the host key, log in by password, then SshChannel's two
 * primitives. The ssh2 extension here; the tests give the commands a fake.
 * A session can be connected again, to the next node, once closed. MAIN only.
 *
 * @package XC_VM_CLI_Commands
 */
class SshSession {
	/** @var resource|false */
	private $rConn = false;

	public function connect(string $rHost, int $rPort): bool {
		$this->rConn = @ssh2_connect($rHost, $rPort);
		return $this->rConn !== false;
	}

	/** The host key's SHA-1, in hex, as libssh2 reports it. */
	public function hostKey(): string {
		return (string) @ssh2_fingerprint($this->rConn, SSH2_FINGERPRINT_SHA1 | SSH2_FINGERPRINT_HEX);
	}

	public function login(string $rUsername, string $rPassword): bool {
		return (bool) @ssh2_auth_password($this->rConn, $rUsername, $rPassword);
	}

	/** @return array{output: string, error: string} */
	public function run(string $rCommand): array {
		return SshChannel::run($this->rConn, $rCommand);
	}

	public function send(string $rLocal, string $rRemote, bool $rWarn = false): bool {
		return SshChannel::send($this->rConn, $rLocal, $rRemote, $rWarn);
	}

	public function close(): void {
		if ($this->rConn !== false && function_exists('ssh2_disconnect')) {
			@ssh2_disconnect($this->rConn);
		}
		$this->rConn = false;
	}
}
