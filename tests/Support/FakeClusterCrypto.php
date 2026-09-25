<?php

namespace XcVm\Tests\Support;

use XcVm\Core\Cluster\ClusterSettings;
use XcVm\Core\Cluster\Crypto\ClusterCrypto;
use XcVm\Core\Cluster\Crypto\ClusterRefusedException;
use XcVm\Core\Cluster\Crypto\Enc;
use XcVm\Core\Cluster\Crypto\Seal;
use XcVm\Core\Cluster\Crypto\SessionKeys;

/**
 * `xcvm_core`'s cluster half, in PHP, for the MAIN API tests. It speaks the
 * extension's wire formats (token doc, SEAL to the agent's per-epoch key,
 * panel signatures) through ClusterReference, so the test agent can open and
 * use what it issues exactly as the Go agent will. The epoch record is plain
 * JSON here; the real one is sealed to the machine.
 */
class FakeClusterCrypto extends ClusterCrypto {
	public string $rSeed;

	public string $rPrk;

	public string $rB;

	/** The panel box key (X25519) that pre-token bodies are sealed to. */
	public string $rBoxSk;

	public bool $rLicensed = true;

	/** @var array<string, int> node uuid => generation floor */
	public array $rFloor = [];

	/** Set to a reason code to make session() refuse. */
	public ?string $rRefuse = null;

	public bool $rInitialised = false;

	/** Set to a reason code to make tokenIssue() refuse (LICENCE, …). */
	public ?string $rRefuseIssue = null;

	public function __construct() {
		$this->rSeed = str_repeat("\x42", 32);
		$this->rPrk = str_repeat("\x07", 32);
		$this->rB = str_repeat("\x09", 16);
		$this->rBoxSk = str_repeat("\x0b", 32);
	}

	public function info(): array {
		$rPub = ClusterReference::panelPub($this->rSeed);
		return [
			'api' => 1, 'ext_version' => 'fake', 'licensed' => $this->rLicensed, 'kid' => bin2hex(substr($this->rB, 0, 4)),
			'clock_ok' => true, 'initialised' => true, 'panel_sign_pub' => $rPub,
			'panel_box_pub' => sodium_crypto_scalarmult_base($this->rBoxSk), 'panel_fp' => hash('sha256', $rPub, true),
		];
	}

	public function init(): array {
		$rInfo = $this->info();
		$rCreated = !$this->rInitialised;
		$this->rInitialised = true;
		return ['created' => $rCreated, 'panel_sign_pub' => $rInfo['panel_sign_pub'], 'panel_box_pub' => $rInfo['panel_box_pub'], 'panel_fp' => $rInfo['panel_fp']];
	}

	public function tokenIssue(array $rParams): array {
		if ($this->rRefuseIssue !== null) {
			throw new ClusterRefusedException($this->rRefuseIssue, 'cluster_token_issue');
		}
		$rNow = \XcVm\Domain\Cluster\ClusterClock::now();
		$rRotation = (int) $rParams['rotation_min'];
		$rGrace = ClusterSettings::graceMin($rRotation);
		$rNbf = $rNow - 120;
		$rExp = $rNow + ($rRotation + $rGrace) * 60;
		$rRefreshAt = $rNow + intdiv($rRotation * 60, 2);
		$rExtSk = random_bytes(32);
		$rZ = sodium_crypto_scalarmult($rExtSk, (string) $rParams['agent_eph_pub']);
		$rMsg = ClusterReference::tokenMsg((string) $rParams['node_uuid'], (int) $rParams['server_id'], (int) $rParams['gen'], (string) $rParams['node_sign_pub'], (int) $rParams['epoch'], $rNbf, $rExp, $rZ);
		$rT = hash_hmac('sha256', $rMsg, ClusterReference::ck($this->rPrk, $this->rB), true);
		$rDoc = (string) json_encode([
			'v' => 1, 'typ' => 'xcvm-token', 'node_uuid' => $rParams['node_uuid'], 'server_id' => (int) $rParams['server_id'],
			'gen' => (int) $rParams['gen'], 'epoch' => (int) $rParams['epoch'], 'iat' => $rNow, 'nbf' => $rNbf, 'exp' => $rExp,
			'kid' => bin2hex(substr($this->rB, 0, 4)), 'rotation_min' => $rRotation, 'grace_min' => $rGrace,
			'refresh_at' => $rRefreshAt, 'token' => bin2hex($rT),
		], JSON_UNESCAPED_SLASHES);
		$rBody = Enc::u32(strlen($rDoc)) . $rDoc . ClusterReference::panelSign($this->rSeed, 'tok', $rDoc);
		$rRecord = (string) json_encode([
			'node_uuid' => $rParams['node_uuid'], 'server_id' => (int) $rParams['server_id'], 'gen' => (int) $rParams['gen'],
			'node_sign_pub' => bin2hex((string) $rParams['node_sign_pub']), 'epoch' => (int) $rParams['epoch'],
			'nbf' => $rNbf, 'exp' => $rExp, 't' => bin2hex($rT),
		]);
		return [
			'epoch_record' => $rRecord,
			'token_sealed' => Seal::seal((string) $rParams['agent_eph_pub'], 'token', (string) $rParams['node_uuid'], $rBody),
			'epoch' => (int) $rParams['epoch'], 'iat' => $rNow, 'nbf' => $rNbf, 'exp' => $rExp, 'refresh_at' => $rRefreshAt,
			'rotation_min' => $rRotation, 'grace_min' => $rGrace, 'kid' => bin2hex(substr($this->rB, 0, 4)),
		];
	}

	public function session(string $rEpochRecord, string $rNodeUuid, bool $rHard = false): SessionKeys {
		$rR = json_decode($rEpochRecord, true);
		if (!is_array($rR) || $rR['node_uuid'] !== $rNodeUuid) {
			throw new ClusterRefusedException('RECORD', 'cluster_session');
		}
		if ($this->rRefuse !== null) {
			throw new ClusterRefusedException($this->rRefuse, 'cluster_session');
		}
		if ($rR['gen'] < ($this->rFloor[$rNodeUuid] ?? 0)) {
			throw new ClusterRefusedException('REVOKED', 'cluster_session');
		}
		$rNow = \XcVm\Domain\Cluster\ClusterClock::now();
		if ($rNow >= $rR['exp'] || $rNow < $rR['nbf']) {
			throw new ClusterRefusedException('EXPIRED', 'cluster_session');
		}
		$rK = ClusterReference::sessionKeys((string) hex2bin($rR['t']));
		return new SessionKeys(
			$rNodeUuid,
			(int) $rR['server_id'],
			(int) $rR['gen'],
			(string) hex2bin($rR['node_sign_pub']),
			(int) $rR['epoch'],
			(int) $rR['nbf'],
			(int) $rR['exp'],
			bin2hex(substr($this->rB, 0, 4)),
			$this->rLicensed,
			$rK['mac_up'],
			$rK['mac_down'],
			$rK['enc_up'],
			$rK['enc_down']
		);
	}

	public function nodeGen(string $rNodeUuid, int $rGen, int $rMinEpoch = 0): array {
		$this->rFloor[$rNodeUuid] = max($this->rFloor[$rNodeUuid] ?? 0, $rGen);
		return ['gen' => $this->rFloor[$rNodeUuid], 'min_epoch' => $rMinEpoch];
	}

	public function sign(string $rTag, string $rPayload): string {
		if (!$this->rLicensed && !in_array($rTag, \XcVm\Core\Cluster\Crypto\PanelSig::RESTRICTIVE_TAGS, true)) {
			throw new ClusterRefusedException('LICENCE', 'cluster_sign');
		}
		return ClusterReference::panelSign($this->rSeed, $rTag, $rPayload);
	}

	public function openSealed(string $rPurpose, string $rSealed, string $rContext = ''): string {
		if (!in_array($rPurpose, ['enrol', 'enrol_code', 'rekey'], true)) {
			throw new ClusterRefusedException('PURPOSE', 'cluster_open_sealed');
		}
		$rPlain = Seal::open($this->rBoxSk, $rPurpose, $rContext, $rSealed);
		if ($rPlain === null) {
			throw new ClusterRefusedException('SEAL', 'cluster_open_sealed');
		}
		return $rPlain;
	}
}
