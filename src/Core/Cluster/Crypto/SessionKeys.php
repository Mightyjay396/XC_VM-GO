<?php

namespace XcVm\Core\Cluster\Crypto;

/**
 * One epoch's session: the four keys derived from its token and the metadata
 * the extension authenticated with them. Returned by ClusterCrypto::session()
 * for the node a request names; never stored.
 */
final class SessionKeys {
	public function __construct(
		public readonly string $rNodeUuid,
		public readonly int $rServerID,
		public readonly int $rGen,
		public readonly string $rNodeSignPub,
		public readonly int $rEpoch,
		public readonly int $rNbf,
		public readonly int $rExp,
		public readonly string $rKid,
		public readonly bool $rLicensed,
		public readonly string $rMacUp,
		public readonly string $rMacDown,
		public readonly string $rEncUp,
		public readonly string $rEncDown,
	) {
		foreach ([$rMacUp, $rMacDown, $rEncUp, $rEncDown] as $rKey) {
			if (strlen($rKey) !== 32) {
				throw new \InvalidArgumentException('session key length');
			}
		}
	}

	/** @param array<string, mixed> $rA As `XC_VM::cluster_session()` returns it. */
	public static function fromArray(array $rA): self {
		return new self(
			(string) $rA['node_uuid'], (int) $rA['server_id'], (int) $rA['gen'], (string) $rA['node_sign_pub'],
			(int) $rA['epoch'], (int) $rA['nbf'], (int) $rA['exp'], (string) $rA['kid'], (bool) $rA['licensed'],
			(string) $rA['k_mac_up'], (string) $rA['k_mac_down'], (string) $rA['k_enc_up'], (string) $rA['k_enc_down'],
		);
	}

	/** Keep the keys out of var_dump, logs and exception traces. */
	public function __debugInfo(): array {
		return ['node_uuid' => $this->rNodeUuid, 'epoch' => $this->rEpoch, 'exp' => $this->rExp, 'keys' => '[redacted]'];
	}
}
