<?php

namespace XcVm\Tests\Support;

use XcVm\Cli\Commands\SshSession;
use XcVm\Domain\Cluster\EnrolmentService;

/**
 * The fleet's side of SSH: one entry per node address, answering as a node
 * that runs this release and xc_agent does (uname, keygen, probe, install).
 */
final class FakeSshFleet extends SshSession {
	/** @var array<string, array{hostkey: string, password: string, ready?: bool, probe?: bool}> */
	public array $rNodes = [];

	/** @var list<string> connect/login/close events and "run <host> <cmd>" */
	public array $rLog = [];

	private ?string $rHost = null;

	public function connect(string $rHost, int $rPort): bool {
		$this->rLog[] = 'connect ' . $rHost . ':' . $rPort;
		$this->rHost = isset($this->rNodes[$rHost]) ? $rHost : null;
		return $this->rHost !== null;
	}

	public function hostKey(): string {
		return $this->rNodes[$this->rHost]['hostkey'];
	}

	public function login(string $rUsername, string $rPassword): bool {
		$this->rLog[] = 'login ' . $this->rHost . ' ' . $rUsername;
		return $rPassword === $this->rNodes[$this->rHost]['password'];
	}

	public function run(string $rCommand): array {
		$this->rLog[] = 'run ' . $this->rHost . ' ' . $rCommand;
		$rNode = $this->rNodes[$this->rHost];
		if (str_contains($rCommand, 'echo READY')) {
			return ['output' => ($rNode['ready'] ?? true) ? "READY\n" : '', 'error' => ''];
		}
		if ($rCommand === 'uname -m') {
			return ['output' => "x86_64\n", 'error' => ''];
		}
		if (preg_match('/ keygen .* -uuid ([0-9a-f-]{36})$/', $rCommand, $rM)) {
			$rSign = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
			$rBox = sodium_crypto_scalarmult_base(random_bytes(32));
			return ['output' => json_encode([
				'node_uuid' => $rM[1], 'sign_pub' => bin2hex($rSign), 'box_pub' => bin2hex($rBox),
				'eph_pub' => bin2hex(sodium_crypto_scalarmult_base(random_bytes(32))), 'sas' => EnrolmentService::sas($rM[1], $rSign, $rBox),
			]) . "\n", 'error' => ''
			];
		}
		if (str_contains($rCommand, ' probe ')) {
			return ['output' => ($rNode['probe'] ?? true) ? "OK http://10.0.0.1:25461/cluster/v1/\n" : "xc_agent probe: connection refused\n", 'error' => ''];
		}
		if (str_contains($rCommand, ' install ')) {
			return ['output' => "OK\n", 'error' => ''];
		}
		return ['output' => '', 'error' => ''];
	}

	public function send(string $rLocal, string $rRemote, bool $rWarn = false): bool {
		$this->rLog[] = 'send ' . $this->rHost . ' ' . $rRemote;
		return true;
	}

	public function close(): void {
		$this->rLog[] = 'close ' . $this->rHost;
		$this->rHost = null;
	}

	/** @return list<string> the commands run on $rHost */
	public function commands(string $rHost): array {
		$rOut = [];
		foreach ($this->rLog as $rLine) {
			if (str_starts_with($rLine, 'run ' . $rHost . ' ')) {
				$rOut[] = substr($rLine, strlen('run ' . $rHost . ' '));
			}
		}
		return $rOut;
	}
}
