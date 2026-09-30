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
	/** libssh2's key-exchange failure, and what it most often means on a node. */
	private const KEX_FAILED = 'Unable to exchange encryption keys';

	private const RSA_ONLY_HINT = 'the node may offer only an RSA host key, which this panel\'s SSH library (libssh2 before 1.11) cannot use with OpenSSH 8.8 or later: give the node an Ed25519 host key (ssh-keygen -A && systemctl restart ssh) and retry';

	/** @var resource|false */
	private $rConn = false;

	private string $rError = '';

	public function connect(string $rHost, int $rPort): bool {
		$this->rConn = self::open($rHost, $rPort, $this->rError);
		return $this->rConn !== false;
	}

	/** Why the last connect() failed ('' when it did not). */
	public function error(): string {
		return $this->rError;
	}

	/**
	 * ssh2_connect(), and on failure why: libssh2 says it in the first of its
	 * two warnings, which error_get_last() loses to the second.
	 *
	 * @return resource|false
	 */
	public static function open(string $rHost, int $rPort, ?string &$rError = null) {
		$rMessages = [];
		set_error_handler(static function (int $rNo, string $rMessage) use (&$rMessages): bool {
			$rMessages[] = preg_replace('/^ssh2_connect\(\): /', '', $rMessage);
			return true;
		});
		try {
			$rConn = ssh2_connect($rHost, $rPort);
		} finally {
			restore_error_handler();
		}
		$rError = $rConn === false ? self::explain($rMessages) : '';
		return $rConn;
	}

	/** @param list<string> $rMessages libssh2's warnings for one failed connect */
	public static function explain(array $rMessages): string {
		$rText = implode('; ', $rMessages);
		return str_contains($rText, self::KEX_FAILED) ? $rText . ' — ' . self::RSA_ONLY_HINT : $rText;
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
