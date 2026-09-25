<?php

namespace XcVm\Core\Cluster\Crypto;

/**
 * The panel's handle on `xcvm_core`'s cluster API (ADR-002, API version 1).
 * Everything that needs the cluster root goes through here: token and lease
 * issue, session keys, panel signatures, the revocation floor, the pin blob
 * for a new LB, machine sealing. PHP never holds PRK, CK_B or `z`.
 *
 * Obtain it from ClusterCryptoFactory, which refuses when the extension is
 * missing or out of range. Every refusal throws ClusterRefusedException with
 * the extension's reason code. BOX, SEAL, the canonical MAC and signature
 * verification are plain PHP (sodium + OpenSSL) in the sibling classes.
 */
class ClusterCrypto {
	/** @return array<string, mixed> api, ext_version, licensed, kid, clock_ok, initialised, panel keys. */
	public function info(): array {
		return (array) \XC_VM::cluster_info();
	}

	/** Create MAIN's cluster root (idempotent). @return array{created: bool, panel_sign_pub: string, panel_box_pub: string, panel_fp: string} */
	public function init(): array {
		return $this->call('cluster_init');
	}

	/**
	 * Mint an epoch token for a node.
	 *
	 * @param array{node_uuid: string, server_id: int, gen: int, epoch: int, node_sign_pub: string, agent_eph_pub: string, rotation_min: int} $rParams
	 * @return array<string, mixed> epoch_record, token_sealed, epoch, iat, nbf, exp, refresh_at, rotation_min, grace_min, kid
	 */
	public function tokenIssue(array $rParams): array {
		return $this->call('cluster_token_issue', $rParams);
	}

	/** The session of a stored epoch record, for the node the request authenticated as. */
	public function session(string $rEpochRecord, string $rNodeUuid, bool $rHard = false): SessionKeys {
		return SessionKeys::fromArray($this->call('cluster_session', $rEpochRecord, $rNodeUuid, $rHard));
	}

	/** Raise a node's revocation floor. @return array{gen: int, min_epoch: int} The floor in force. */
	public function nodeGen(string $rNodeUuid, int $rGen, int $rMinEpoch = 0): array {
		return $this->call('cluster_node_gen', $rNodeUuid, $rGen, $rMinEpoch);
	}

	/** "R" or "G", as the extension classifies the record. */
	public function recordClass(string $rTag, string $rPayload): string {
		return $this->call('cluster_record_class', $rTag, $rPayload);
	}

	/** The panel's 64-byte signature over a record (granting records need a licence). */
	public function sign(string $rTag, string $rPayload): string {
		PanelSig::input($rTag, ''); // the closed registry, checked before crossing into the extension
		return $this->call('cluster_sign', $rTag, $rPayload);
	}

	/** @param array{node_uuid: string, server_id: int, gen: int, token_exp: int, tolerance_h: int} $rParams @return array{payload: string, sig: string, exp: int} */
	public function leaseIssue(array $rParams): array {
		return $this->call('cluster_lease_issue', $rParams);
	}

	/** The XCVT pin blob carrying the panel keys to the LB with this install_id. */
	public function pack(string $rTargetInstallID): string {
		return $this->call('cluster_pack', $rTargetInstallID);
	}

	/** Seal to this machine; purposes are namespaced `php:` by the extension. */
	public function sealLocal(string $rPurpose, string $rData, string $rContext = ''): string {
		return $this->call('cluster_seal_local', $rPurpose, $rData, $rContext);
	}

	public function openLocal(string $rPurpose, string $rBlob, string $rContext = ''): string {
		return $this->call('cluster_open_local', $rPurpose, $rBlob, $rContext);
	}

	/** Open a pre-token SEAL body addressed to the panel box key (`enrol`, `enrol_code`, `rekey`). */
	public function openSealed(string $rPurpose, string $rSealed, string $rContext = ''): string {
		return $this->call('cluster_open_sealed', $rPurpose, $rSealed, $rContext);
	}

	/**
	 * @param mixed ...$rArgs
	 * @return mixed
	 */
	protected function call(string $rMethod, ...$rArgs) {
		$rResult = \XC_VM::$rMethod(...$rArgs);
		if ($rResult === false) {
			throw new ClusterRefusedException((string) (\XC_VM::cluster_last_error() ?? 'UNKNOWN'), $rMethod);
		}
		return $rResult;
	}
}
