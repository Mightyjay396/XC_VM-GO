<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\Crypto\ClusterCrypto;
use XcVm\Core\Cluster\Crypto\Seal;
use XcVm\Core\Cluster\ReplicaEtagCache;
use XcVm\Core\Cluster\ReplicaSections;
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
 * Today the replica holds these sections:
 *
 * - `blocklist` (BlocklistDelta): blocked IPs as `blk` deltas between whole
 *   sections. `blk` records only restrict while they add, so the extension
 *   signs them without a licence; removals and whole sections are granting.
 * - `settings`: the raw `settings` row, only the keys the LB build reads
 *   (Core/Cluster/lb_settings_keys.php, from tools/ci/lb-settings-keys.sh),
 *   never a secret.
 * - `servers`, `node`, `crontab`, `cluster` (Core\Cluster\ReplicaSections):
 *   every server's routing and relay fields with the node list, the node's
 *   own configuration, the crontab rows its mode runs, and MAIN's transport
 *   policy and keys.
 *
 * All but the blocklist are sent whole, to an agent that names them in
 * `have`, whenever their ETag differs from the node's. A section and its
 * ETag are reused for 10 s (ReplicaEtagCache); a revoked or re-enrolled node
 * drops the cache and is announced to the others through `config.changed`.
 */
final class ReplicaBuilder {
	use DatabaseAware;

	public const PURPOSE = 'replica';

	public const SECTION_BLOCKLIST = 'blocklist';

	public const SECTION_SETTINGS = ReplicaSections::SETTINGS;

	/** Sections sent whole whenever the node's ETag differs. */
	public const WHOLE = ReplicaSections::WHOLE;

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
	 * @param array<string, mixed> $rSettings MAIN's settings (the `cluster` section's policy)
	 * @param array<string, mixed> $rMain MAIN's `servers` row
	 * @return array{unchanged?: bool, etag?: string, sealed?: string}
	 */
	public static function whole(ClusterCrypto $rCrypto, array $rNode, string $rSection, string $rHave, array $rSettings = [], array $rMain = []): array {
		['etag' => $rEtag, 'data' => $rData] = self::section($rCrypto, $rNode, $rSection, $rSettings, $rMain);
		if (hash_equals($rEtag, $rHave)) {
			return ['unchanged' => true];
		}
		$rDoc = [
			'v' => 1, 'section' => $rSection, 'node' => (string) $rNode['node_uuid'], 'gen' => (int) $rNode['gen'],
			'etag' => $rEtag, 'iat' => ClusterClock::now(), 'data' => $rData,
		];
		return ['etag' => $rEtag, 'sealed' => base64_encode(self::record($rCrypto, $rNode, 'rep', self::json($rDoc)))];
	}

	/**
	 * A whole section for this node and its ETag, reused for 10 s.
	 *
	 * @param array<string, mixed> $rNode cluster_nodes row (server_id, mode)
	 * @param array<string, mixed> $rSettings
	 * @param array<string, mixed> $rMain
	 * @return array{etag: string, data: array<mixed>}
	 */
	public static function section(ClusterCrypto $rCrypto, array $rNode, string $rSection, array $rSettings, array $rMain): array {
		$rKey = match ($rSection) {
			ReplicaSections::NODE => $rSection . '.' . (int) $rNode['server_id'],
			ReplicaSections::CRONTAB => $rSection . '.' . ((int) $rNode['mode'] >= 2 ? 'api' : 'legacy'),
			default => $rSection,
		};
		$rNow = ClusterClock::nowMs();
		$rHit = ReplicaEtagCache::get($rKey, $rNow);
		if ($rHit !== null) {
			return $rHit;
		}
		$rData = match ($rSection) {
			ReplicaSections::SETTINGS => self::settingsData(),
			ReplicaSections::SERVERS => self::serversData(),
			ReplicaSections::NODE => self::nodeData((int) $rNode['server_id']),
			ReplicaSections::CRONTAB => self::crontabData((int) $rNode['mode']),
			ReplicaSections::CLUSTER => self::clusterData($rCrypto, $rSettings, $rMain),
			default => throw new \InvalidArgumentException('Not a whole replica section: ' . $rSection),
		};
		$rData = self::canonical($rData);
		$rEtag = self::etag($rData);
		ReplicaEtagCache::put($rKey, $rNow, $rEtag, $rData);
		return ['etag' => $rEtag, 'data' => $rData];
	}

	/**
	 * The `servers` section: every server's routing and relay fields, and the
	 * node list parents check relay tickets against and children file-digest
	 * keys (`{sid, gen, state, ed_pub}`). Never liveness, telemetry or a
	 * node's own settings (ReplicaSections::SERVER_LOCAL).
	 *
	 * @return array{servers: list<array<string, int|string|null>>, nodes: list<array{sid: int, gen: int, state: string, ed_pub: string}>}
	 */
	public static function serversData(): array {
		self::db()->query('SELECT * FROM `servers` ORDER BY `id` ASC;');
		$rServers = [];
		foreach (self::db()->get_rows() ?: [] as $rRow) {
			$rServers[] = ReplicaSections::typed($rRow, ReplicaSections::SERVER_FIELDS);
		}
		self::db()->query('SELECT `server_id`, `gen`, `state`, `node_sign_pub` FROM `cluster_nodes` ORDER BY `server_id` ASC;');
		$rNodes = [];
		foreach (self::db()->get_rows() ?: [] as $rRow) {
			$rNodes[] = ['sid' => (int) $rRow['server_id'], 'gen' => (int) $rRow['gen'], 'state' => (string) $rRow['state'], 'ed_pub' => base64_encode((string) $rRow['node_sign_pub'])];
		}
		return ['servers' => $rServers, 'nodes' => $rNodes];
	}

	/**
	 * The `node` section: the node's own row (ports, limits, services, disk,
	 * HTTPS and domains, interface, governor, sysctl, clock offset) and the
	 * settings that shape its nginx. Empty when the server row is gone.
	 *
	 * @return array<string, int|string|null>
	 */
	public static function nodeData(int $rServerID): array {
		self::db()->query('SELECT * FROM `servers` WHERE `id` = ?;', $rServerID);
		$rRow = self::db()->get_row();
		if (!is_array($rRow) || $rRow === []) {
			return [];
		}
		self::db()->query('SELECT * FROM `settings` LIMIT 1;');
		$rSettings = self::db()->get_row() ?: [];
		return ReplicaSections::typed($rRow, ReplicaSections::NODE_FIELDS) + ReplicaSections::typed(is_array($rSettings) ? $rSettings : [], ReplicaSections::NODE_SETTINGS);
	}

	/**
	 * The `crontab` section: the enabled rows whose role fits a node in this
	 * mode (ReplicaSections::cronRoles), in the table's order.
	 *
	 * @return array{jobs: list<array{filename: string, time: string}>}
	 */
	public static function crontabData(int $rMode): array {
		$rRoles = ReplicaSections::cronRoles($rMode);
		self::db()->query('SELECT `filename`, `time`, `role` FROM `crontab` WHERE `enabled` = 1 ORDER BY `id` ASC;');
		$rJobs = [];
		foreach (self::db()->get_rows() ?: [] as $rRow) {
			if (in_array((string) ($rRow['role'] ?? 'all'), $rRoles, true)) {
				$rJobs[] = ['filename' => (string) $rRow['filename'], 'time' => (string) $rRow['time']];
			}
		}
		return ['jobs' => $rJobs];
	}

	/**
	 * The `cluster` section: the transport policy a node follows (as `hello`
	 * sends it; one counter versions the URLs and the policy), the panel keys,
	 * the lowest protocol MAIN speaks, and the off-air videos' file names.
	 *
	 * @param array<string, mixed> $rSettings
	 * @param array<string, mixed> $rMain
	 * @return array<string, mixed>
	 */
	public static function clusterData(ClusterCrypto $rCrypto, array $rSettings, array $rMain): array {
		$rPolicy = ClusterPolicy::current($rSettings, $rMain);
		$rInfo = $rCrypto->info();
		$rOffAir = [];
		foreach (ReplicaSections::OFF_AIR as $rName => $rKey) {
			$rPath = is_string($rSettings[$rKey] ?? null) ? trim($rSettings[$rKey]) : '';
			$rOffAir[$rName] = $rPath === '' ? null : basename((string) (parse_url($rPath, PHP_URL_PATH) ?: $rPath));
		}
		return [
			'main_urls' => $rPolicy['main_urls'], 'urls_ver' => $rPolicy['policy_ver'], 'policy_ver' => $rPolicy['policy_ver'],
			'transport' => $rPolicy['transport'], 'panel_sign_pub' => base64_encode((string) ($rInfo['panel_sign_pub'] ?? '')),
			'panel_box_pub' => base64_encode((string) ($rInfo['panel_box_pub'] ?? '')), 'min_proto' => ClusterApi::PROTO_MIN, 'off_air' => $rOffAir,
		];
	}

	/**
	 * The node list changed (a node revoked, re-enrolled or activated): drop
	 * the cached sections and tell every other node taking commands at once
	 * (`config.changed`, restrictive, so it signs without a licence), so a
	 * parent stops trusting a revoked child's key before its next poll. A newer
	 * announcement supersedes one not yet acked. Never fails the change.
	 *
	 * @return int the nodes told
	 */
	public static function nodesChanged(ClusterCrypto $rCrypto, int $rServerID): int {
		ReplicaEtagCache::bump();
		$rSent = 0;
		try {
			self::db()->query("SELECT * FROM `cluster_nodes` WHERE `server_id` <> ? AND `state` = 'active';", $rServerID);
			foreach (self::db()->get_rows() ?: [] as $rNode) {
				if (CommandBus::accepts($rNode)) {
					CommandBus::enqueue($rCrypto, (int) $rNode['server_id'], 'config.changed', ['sections' => [ReplicaSections::SERVERS]], 'config.changed');
					$rSent++;
				}
			}
		} catch (\Throwable) {
			// The nodes see the change at their next poll (60 s).
		}
		return $rSent;
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

	/** Keys sorted at every level; lists keep their order. */
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
