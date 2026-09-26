<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\Crypto\ClusterCrypto;
use XcVm\Core\Cluster\Crypto\Seal;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * The node replica (plan, section 9, Phase 7): what MAIN sends a node so it
 * can serve without MAIN's database. Each record is panel-signed and sealed
 * to the node's X25519 key, so the node can keep it on disk as it came:
 *
 * ```text
 * record = SEAL(node_box_pub, purpose "replica", context node_uuid,
 *               u32(len) ‖ payload ‖ Ed25519 sig(tag, payload))
 * rep  {v, section, node, gen, etag, seq, iat, data}   a whole section (granting)
 * blk  {v, seq, iat, add, remove}                      a blocklist delta
 * ```
 *
 * A `rep` record names the node and its generation, so it opens and verifies
 * only there, and only until the node is re-enrolled. The ETag is the SHA-256
 * of the section's canonical data: a node that already holds it gets
 * `unchanged` instead of the section again.
 *
 * Today the replica holds two sections:
 *
 * - `blocklist` (BlocklistDelta): blocked IPs as `blk` deltas between whole
 *   sections. `blk` records only restrict while they add, so the extension
 *   signs them without a licence; removals and whole sections are granting.
 * - `settings`: the raw `settings` row, only the keys the LB build reads
 *   (Core/Cluster/lb_settings_keys.php, from tools/ci/lb-settings-keys.sh),
 *   never a secret. Sent whole whenever its ETag differs from the node's.
 */
final class ReplicaBuilder {
	use DatabaseAware;

	public const PURPOSE = 'replica';

	public const SECTION_BLOCKLIST = 'blocklist';

	public const SECTION_SETTINGS = 'settings';

	/** Sections sent whole whenever the node's ETag differs: name => builder. */
	public const WHOLE = [self::SECTION_SETTINGS => 'settingsData'];

	/**
	 * The node's blocklist from change $rSince (0: it has none).
	 *
	 * @param array<string, mixed> $rNode cluster_nodes row
	 * @return array{seq: int, more: bool, unchanged?: bool, delta?: string, section?: array{etag: string, sealed: string}}
	 */
	public static function blocklist(ClusterCrypto $rCrypto, array $rNode, int $rSince, string $rHave): array {
		$rDelta = BlocklistDelta::since($rSince);
		if ($rDelta['full'] || !empty($rDelta['reload'])) {
			// The head before the snapshot: a change made in between comes again next time.
			$rSeq = BlocklistDelta::head();
			$rData = BlocklistDelta::snapshot();
			$rEtag = self::etag($rData);
			if (hash_equals($rEtag, $rHave)) {
				return ['seq' => $rSeq, 'more' => false, 'unchanged' => true];
			}
			$rDoc = [
				'v' => 1, 'section' => self::SECTION_BLOCKLIST, 'node' => (string) $rNode['node_uuid'], 'gen' => (int) $rNode['gen'],
				'etag' => $rEtag, 'seq' => $rSeq, 'iat' => ClusterClock::now(), 'data' => self::canonical($rData),
			];
			return ['seq' => $rSeq, 'more' => false, 'section' => ['etag' => $rEtag, 'sealed' => base64_encode(self::record($rCrypto, $rNode, 'rep', self::json($rDoc)))]];
		}
		$rOut = ['seq' => $rDelta['last'], 'more' => $rDelta['more']];
		if (($rDelta['add'] ?? []) === [] && ($rDelta['remove'] ?? []) === []) {
			return $rOut;
		}
		$rDoc = ['v' => 1, 'seq' => $rDelta['last'], 'iat' => ClusterClock::now(), 'add' => $rDelta['add'] ?? [], 'remove' => $rDelta['remove'] ?? []];
		$rOut['delta'] = base64_encode(self::record($rCrypto, $rNode, 'blk', self::json($rDoc)));
		return $rOut;
	}

	/**
	 * A section sent whole: `unchanged` when the node holds its ETag, else the
	 * sealed `rep` record.
	 *
	 * @param array<string, mixed> $rNode cluster_nodes row
	 * @return array{unchanged?: bool, etag?: string, sealed?: string}
	 */
	public static function whole(ClusterCrypto $rCrypto, array $rNode, string $rSection, string $rHave): array {
		$rData = [self::class, self::WHOLE[$rSection]]();
		$rEtag = self::etag($rData);
		if (hash_equals($rEtag, $rHave)) {
			return ['unchanged' => true];
		}
		$rDoc = [
			'v' => 1, 'section' => $rSection, 'node' => (string) $rNode['node_uuid'], 'gen' => (int) $rNode['gen'],
			'etag' => $rEtag, 'iat' => ClusterClock::now(), 'data' => self::canonical($rData),
		];
		return ['etag' => $rEtag, 'sealed' => base64_encode(self::record($rCrypto, $rNode, 'rep', self::json($rDoc)))];
	}

	/**
	 * The `settings` section: the raw row, allowlisted keys only.
	 *
	 * @return array<string, mixed>
	 */
	public static function settingsData(): array {
		$rAllow = self::settingsKeys();
		self::db()->query('SELECT * FROM `settings` LIMIT 1;');
		$rRow = self::db()->get_row() ?: [];
		$rOut = [];
		foreach ($rAllow as $rKey) {
			if (array_key_exists($rKey, $rRow)) {
				$rOut[$rKey] = $rRow[$rKey] === null ? null : (string) $rRow[$rKey];
			}
		}
		return $rOut;
	}

	/** @return list<string> the settings keys a node's replica may carry */
	public static function settingsKeys(): array {
		$rList = require dirname(__DIR__, 2) . '/Core/Cluster/lb_settings_keys.php';
		return array_values(array_diff($rList['keys'], $rList['withheld']));
	}

	/** Sign a record and seal it to the node's box key. */
	public static function record(ClusterCrypto $rCrypto, array $rNode, string $rTag, string $rPayload): string {
		$rSig = $rCrypto->sign($rTag, $rPayload);
		return Seal::seal((string) $rNode['node_box_pub'], self::PURPOSE, (string) $rNode['node_uuid'], pack('N', strlen($rPayload)) . $rPayload . $rSig);
	}

	/** SHA-256 (hex) of a section's canonical data. */
	public static function etag(array $rData): string {
		return hash('sha256', self::json(self::canonical($rData)));
	}

	private static function canonical(mixed $rValue): mixed {
		if (!is_array($rValue)) {
			return $rValue;
		}
		if (!array_is_list($rValue)) {
			ksort($rValue, SORT_STRING);
		}
		return array_map([self::class, 'canonical'], $rValue);
	}

	private static function json(array $rDoc): string {
		return (string) json_encode($rDoc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
	}
}
