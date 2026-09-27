<?php

namespace XcVm\Core\Cluster;

use XcVm\Core\Cluster\Crypto\PanelSig;
use XcVm\Core\Cluster\Crypto\Seal;

/**
 * The node replica's records as the agent stored them, opened and checked
 * here for `cluster:apply --from-disk` (plan, section 9, "Storage and
 * boot"). At boot the agent may not run yet, and the `<name>.json` files it
 * wrote for PHP carry no signature, so the apply takes each section from
 * the record behind it instead, once it verifies:
 *
 * ```text
 * replica/<name>.rep          a whole section: the sealed `rep` record as MAIN sent it
 * replica/blocklist.rep       the blocklist's last whole section (`rep`, with its seq)
 * replica/blocklist.d/*.blk   the blocklist's deltas since, in name (seq) order (`blk`)
 * ../agent.json               the agent's state: node_uuid, node_box_sk, panel_sign_pub
 * ```
 *
 * A record is sealed (XCVM-SEAL-v1, purpose `replica`, context the node uuid)
 * to the node's box key and opens to `u32(len) ‖ payload ‖ sig`, where `sig`
 * is the panel's Ed25519 signature over the payload under its tag. It is
 * taken only if it opens with the key in agent.json, its signature verifies
 * under the panel key the agent pinned there, and it names this node and the
 * section asked for: the checks the agent made when it stored it. A section
 * is read only where the agent stored its `.json` (what owns() and the
 * readers go by); its data then comes from the record, not from the file.
 *
 * The keys are the agent's, in files only xc_vm reads (0600): root's pin of
 * the panel key (RootPin) would add nothing here, since whoever could plant
 * a record could as well write the caches the apply builds.
 */
final class ReplicaRecords {
	public const PURPOSE = 'replica';

	/** Largest record read: a section is at most a few MiB (the servers of a large fleet). */
	public const MAX_RECORD = 33554432;

	/**
	 * The node's keys from the agent's state file, which Go writes with the
	 * bytes as standard base64. Null when it is missing or incomplete.
	 *
	 * @return array{node: string, box_sk: string, sign_pub: string}|null
	 */
	public static function identity(string $rAgentFile): ?array {
		$rState = json_decode((string) @file_get_contents($rAgentFile), true);
		if (!is_array($rState) || !is_string($rState['node_uuid'] ?? null) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $rState['node_uuid'])) {
			return null;
		}
		$rBoxSk = is_string($rState['node_box_sk'] ?? null) ? base64_decode($rState['node_box_sk'], true) : false;
		$rSignPub = is_string($rState['panel_sign_pub'] ?? null) ? base64_decode($rState['panel_sign_pub'], true) : false;
		if ($rBoxSk === false || strlen($rBoxSk) !== 32 || $rSignPub === false || strlen($rSignPub) !== 32) {
			return null;
		}
		return ['node' => $rState['node_uuid'], 'box_sk' => $rBoxSk, 'sign_pub' => $rSignPub];
	}

	/**
	 * Open a record sealed to this node and verify the panel signature under
	 * $rTag: the signed payload, or null.
	 *
	 * @param array{node: string, box_sk: string, sign_pub: string} $rIdentity
	 */
	public static function open(string $rSealed, string $rTag, array $rIdentity): ?string {
		$rBody = Seal::open($rIdentity['box_sk'], self::PURPOSE, $rIdentity['node'], $rSealed);
		if ($rBody === null || strlen($rBody) < 4) {
			return null;
		}
		$rLength = unpack('N', substr($rBody, 0, 4))[1];
		if (4 + $rLength > strlen($rBody)) {
			return null;
		}
		$rPayload = substr($rBody, 4, $rLength);
		return PanelSig::verify($rIdentity['sign_pub'], $rTag, $rPayload, substr($rBody, 4 + $rLength)) ? $rPayload : null;
	}

	/**
	 * A whole section from its record: `{etag, data}` as the agent writes
	 * `<name>.json`. Null when the agent stored no `<name>.json`; false when
	 * the record is missing or does not verify for this node and section.
	 *
	 * @param array{node: string, box_sk: string, sign_pub: string}|null $rIdentity
	 * @return array{etag: string, data: array<mixed>}|false|null
	 */
	public static function whole(string $rDir, string $rName, ?array $rIdentity): array|false|null {
		if (!is_file($rDir . $rName . '.json')) {
			return null;
		}
		$rDoc = self::payload($rDir . $rName . '.rep', 'rep', $rIdentity);
		if ($rDoc === null || ($rDoc['section'] ?? null) !== $rName || !self::etag($rDoc['etag'] ?? null) || !is_array($rDoc['data'] ?? null)) {
			return false;
		}
		return ['etag' => $rDoc['etag'], 'data' => $rDoc['data']];
	}

	/**
	 * The blocklist as the agent materialises `blocklist.json`: the stored
	 * section with its deltas applied in seq order, each verified. Null when
	 * the agent stored no `blocklist.json`; false when a record is missing,
	 * does not verify, or a delta is out of order.
	 *
	 * @param array{node: string, box_sk: string, sign_pub: string}|null $rIdentity
	 * @return array{seq: int, etag: string, data: array<mixed>}|false|null
	 */
	public static function blocklist(string $rDir, ?array $rIdentity): array|false|null {
		if (!is_file($rDir . 'blocklist.json')) {
			return null;
		}
		$rDoc = self::payload($rDir . 'blocklist.rep', 'rep', $rIdentity);
		if ($rDoc === null || ($rDoc['section'] ?? null) !== 'blocklist' || !is_int($rDoc['seq'] ?? null) || !self::etag($rDoc['etag'] ?? null) || !is_array($rDoc['data'] ?? null) || !self::strings($rDoc['data']['ip'] ?? [])) {
			return false;
		}
		$rSeq = $rDoc['seq'];
		$rIPs = array_fill_keys($rDoc['data']['ip'] ?? [], true);
		$rDeltas = glob($rDir . 'blocklist.d/*.blk') ?: [];
		sort($rDeltas, SORT_STRING);
		foreach ($rDeltas as $rFile) {
			$rDelta = self::payload($rFile, 'blk', $rIdentity, false);
			if ($rDelta === null || !is_int($rDelta['seq'] ?? null) || $rDelta['seq'] <= $rSeq || !self::strings($rDelta['add'] ?? []) || !self::strings($rDelta['remove'] ?? [])) {
				return false;
			}
			foreach ($rDelta['remove'] ?? [] as $rIP) {
				unset($rIPs[$rIP]);
			}
			foreach ($rDelta['add'] ?? [] as $rIP) {
				$rIPs[$rIP] = true;
			}
			$rSeq = $rDelta['seq'];
		}
		$rList = array_map('strval', array_keys($rIPs));
		sort($rList, SORT_STRING);
		$rDoc['data']['ip'] = $rList;
		return ['seq' => $rSeq, 'etag' => $rDoc['etag'], 'data' => $rDoc['data']];
	}

	/**
	 * A record file's signed payload, decoded: null when it is missing, too
	 * large, does not open or verify, or (with $rNamesNode) names another node.
	 *
	 * @param array{node: string, box_sk: string, sign_pub: string}|null $rIdentity
	 * @return array<mixed>|null
	 */
	private static function payload(string $rFile, string $rTag, ?array $rIdentity, bool $rNamesNode = true): ?array {
		if ($rIdentity === null || !is_file($rFile) || (int) @filesize($rFile) > self::MAX_RECORD) {
			return null;
		}
		$rSealed = @file_get_contents($rFile);
		$rPayload = is_string($rSealed) ? self::open($rSealed, $rTag, $rIdentity) : null;
		$rDoc = $rPayload === null ? null : json_decode($rPayload, true);
		if (!is_array($rDoc) || ($rNamesNode && ($rDoc['node'] ?? null) !== $rIdentity['node'])) {
			return null;
		}
		return $rDoc;
	}

	/** An ETag as MAIN makes it (ReplicaBuilder::etag): 64 lowercase hex digits. */
	private static function etag(mixed $rEtag): bool {
		return is_string($rEtag) && preg_match('/^[0-9a-f]{64}$/', $rEtag) === 1;
	}

	private static function strings(mixed $rList): bool {
		if (!is_array($rList) || !array_is_list($rList)) {
			return false;
		}
		foreach ($rList as $rEntry) {
			if (!is_string($rEntry)) {
				return false;
			}
		}
		return true;
	}
}
