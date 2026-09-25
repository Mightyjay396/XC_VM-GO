<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\Crypto\ClusterCrypto;

/**
 * Enrolment on MAIN.
 *
 * SSH path (plan, section 6): the install flow generates the node's keys on
 * the LB, reads the public halves and a fresh per-epoch key back over the
 * verified SSH session, and calls issueFirst(). MAIN mints epoch 1 and returns
 * everything the LB needs, which the install flow copies over SSH. The node
 * then proves possession with `enrol_complete` within 30 minutes, when an
 * unused first token dies.
 */
final class EnrolmentService {
	/**
	 * @param array<string, mixed> $rSettings
	 * @param array<string, mixed> $rMain The main server's `servers` row.
	 * @return array{node_uuid: string, gen: int, epoch: int, token_sealed: string, exp: int, refresh_at: int, cluster: array<string, mixed>}
	 */
	public static function issueFirst(ClusterCrypto $rCrypto, int $rServerID, string $rNodeUuid, string $rSignPub, string $rBoxPub, string $rAgentEphPub, array $rSettings, array $rMain): array {
		if (!self::validUuid($rNodeUuid) || strlen($rSignPub) !== 32 || strlen($rBoxPub) !== 32 || strlen($rAgentEphPub) !== 32) {
			throw new \InvalidArgumentException('enrolment keys');
		}
		$rMode = ($rSettings['lb_new_node_mode'] ?? 'legacy') === 'api' ? 2 : 1;
		$rGen = NodeRegistry::startEnrolment($rServerID, $rNodeUuid, $rSignPub, $rBoxPub, $rMode, $rCrypto)['gen'];
		$rNode = NodeRegistry::byServer($rServerID);
		$rIssued = TokenService::issue($rCrypto, (array) $rNode, 1, $rAgentEphPub);
		ClusterAudit::log('node.enrol_start', $rServerID, ['node' => $rNodeUuid, 'gen' => $rGen, 'mode' => $rMode], 'install');
		return [
			'node_uuid' => $rNodeUuid,
			'gen' => $rGen,
			'epoch' => 1,
			'token_sealed' => (string) $rIssued['token_sealed'],
			'exp' => (int) $rIssued['exp'],
			'refresh_at' => (int) $rIssued['refresh_at'],
			'cluster' => self::clusterJson($rCrypto, $rServerID, $rNodeUuid, $rSettings, $rMain),
		];
	}

	/**
	 * `cluster.json` for a node: MAIN URLs and policy, panel public keys and
	 * the node's identity. Panel-signed (tag `cfg`) by the caller when written.
	 *
	 * @return array<string, mixed>
	 */
	public static function clusterJson(ClusterCrypto $rCrypto, int $rServerID, string $rNodeUuid, array $rSettings, array $rMain): array {
		$rInfo = $rCrypto->info();
		return [
			'v' => 1,
			'typ' => 'xcvm-cluster',
			'node_uuid' => $rNodeUuid,
			'server_id' => $rServerID,
			'panel_sign_pub' => base64_encode((string) ($rInfo['panel_sign_pub'] ?? '')),
			'panel_box_pub' => base64_encode((string) ($rInfo['panel_box_pub'] ?? '')),
			'panel_fp' => bin2hex((string) ($rInfo['panel_fp'] ?? '')),
			'proto' => ['min' => ClusterApi::PROTO_MIN, 'max' => ClusterApi::PROTO_MAX],
			'policy' => ClusterPolicy::current($rSettings, $rMain),
			'iat' => ClusterClock::now(),
		];
	}

	/** SAS: six base32 groups of SHA-256(node_uuid ‖ ed25519_pub ‖ x25519_pub). */
	public static function sas(string $rNodeUuid, string $rSignPub, string $rBoxPub): string {
		$rHash = hash('sha256', $rNodeUuid . $rSignPub . $rBoxPub, true);
		$rAlphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$rBits = '';
		foreach (str_split(substr($rHash, 0, 15)) as $rByte) {
			$rBits .= str_pad(decbin(ord($rByte)), 8, '0', STR_PAD_LEFT);
		}
		$rOut = '';
		foreach (str_split($rBits, 5) as $rChunk) {
			$rOut .= $rAlphabet[bindec($rChunk)];
		}
		return implode('-', str_split(substr($rOut, 0, 24), 4));
	}

	public static function validUuid(string $rUuid): bool {
		return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $rUuid);
	}
}
