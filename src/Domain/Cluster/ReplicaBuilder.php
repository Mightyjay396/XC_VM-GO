<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\Crypto\ClusterCrypto;
use XcVm\Core\Cluster\Crypto\Seal;
use XcVm\Core\Cluster\ReplicaEtagCache;
use XcVm\Core\Cluster\ReplicaSections;
use XcVm\Core\Config\OpensslExtra;
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
 * - `secrets`: `live_streaming_pass` and OPENSSL_EXTRA, each as {kid,
 *   current, previous, previous_valid_until}, the only secrets a node gets.
 *
 * All but the blocklist are sent whole, to an agent that names them in
 * `have`, whenever their ETag differs from the node's. A section and its
 * ETag are reused for 10 s (ReplicaEtagCache); a change of the node list
 * drops the cache and is announced to the others through `config.changed`
 * (nodesChanged). `secrets` goes only to an active node in mode 1 or 2
 * (serves()) and is read afresh each time, never cached unsealed; like every
 * whole section it grants, so without a licence it is not signed.
 *
 * A section is never built from a failed read, a missing settings row or an
 * unset secret: it would be signed as MAIN's word (an empty settings row the
 * node takes as its whole settings, a crontab with no job, an empty `current`).
 * The builder throws instead, the `config` op answers `503 DB`, and the node
 * keeps what it holds and asks again.
 */
final class ReplicaBuilder {
	use DatabaseAware;

	public const PURPOSE = 'replica';

	public const SECTION_BLOCKLIST = 'blocklist';

	public const SECTION_SETTINGS = ReplicaSections::SETTINGS;

	/** Sections sent whole whenever the node's ETag differs (and `secrets`, see serves()). */
	public const WHOLE = ReplicaSections::WHOLE;

	public const SECTION_SECRETS = ReplicaSections::SECRETS;

	/**
	 * What an agent says at hello (`features`) when it runs `config.changed`
	 * itself: only those agents are sent it. Others would hand it to
	 * cluster:exec, which an LB's older PHP fails as an unknown type.
	 */
	public const FEATURE_CONFIG_CHANGED = 'config_changed';

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
	 * Is this whole section served to this node? `secrets` only to an active
	 * node in mode 1 or 2: a legacy node (mode 0) reads MAIN's database.
	 *
	 * @param array<string, mixed> $rNode cluster_nodes row (state, mode)
	 */
	public static function serves(array $rNode, string $rSection): bool {
		if ($rSection !== ReplicaSections::SECRETS) {
			return true;
		}
		return ($rNode['state'] ?? null) === 'active' && (int) ($rNode['mode'] ?? 0) >= 1;
	}

	/**
	 * A whole section for this node and its ETag, reused for 10 s; `secrets`
	 * is read each time, never cached.
	 *
	 * @param array<string, mixed> $rNode cluster_nodes row (server_id, mode)
	 * @param array<string, mixed> $rSettings
	 * @param array<string, mixed> $rMain
	 * @return array{etag: string, data: array<mixed>}
	 */
	public static function section(ClusterCrypto $rCrypto, array $rNode, string $rSection, array $rSettings, array $rMain): array {
		if ($rSection === ReplicaSections::SECRETS) {
			$rData = self::canonical(self::secretsData());
			return ['etag' => self::etag($rData), 'data' => $rData];
		}
		$rKey = match ($rSection) {
			ReplicaSections::NODE => $rSection . '.' . (int) $rNode['server_id'],
			ReplicaSections::CRONTAB => $rSection . '.' . ((int) $rNode['mode'] >= 2 ? 'api' : 'legacy'),
			default => $rSection,
		};
		$rNow = ClusterClock::nowMs();
		$rGen = ReplicaEtagCache::generation();
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
		ReplicaEtagCache::put($rKey, $rNow, $rGen, $rEtag, $rData);
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
		self::read('SELECT * FROM `servers` ORDER BY `id` ASC;');
		$rServers = [];
		foreach (self::db()->get_rows() ?: [] as $rRow) {
			$rServers[] = ReplicaSections::typed($rRow, ReplicaSections::SERVER_FIELDS);
		}
		self::read('SELECT `server_id`, `gen`, `state`, `node_sign_pub` FROM `cluster_nodes` ORDER BY `server_id` ASC;');
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
		self::read('SELECT * FROM `servers` WHERE `id` = ?;', $rServerID);
		$rRow = self::db()->get_row();
		if (!is_array($rRow) || $rRow === []) {
			return [];
		}
		return ReplicaSections::typed($rRow, ReplicaSections::NODE_FIELDS) + ReplicaSections::typed(self::settingsRow('*'), ReplicaSections::NODE_SETTINGS);
	}

	/**
	 * The `crontab` section: the enabled rows whose role fits a node in this
	 * mode (ReplicaSections::cronRoles), in the table's order. A row that is
	 * not a job as ReplicaSections::cronJob takes it (a hand-edited `@daily`,
	 * a six-field schedule) is left out and audited once, since a node
	 * refuses the whole section over it.
	 *
	 * @return array{jobs: list<array{filename: string, time: string}>}
	 */
	public static function crontabData(int $rMode): array {
		$rRoles = ReplicaSections::cronRoles($rMode);
		self::read('SELECT `filename`, `time`, `role` FROM `crontab` WHERE `enabled` = 1 ORDER BY `id` ASC;');
		$rJobs = [];
		$rSkipped = [];
		foreach (self::db()->get_rows() ?: [] as $rRow) {
			if (!in_array((string) ($rRow['role'] ?? 'all'), $rRoles, true)) {
				continue;
			}
			$rRow = ['filename' => (string) $rRow['filename'], 'time' => (string) $rRow['time']];
			$rJob = ReplicaSections::cronJob($rRow);
			if ($rJob === null) {
				$rSkipped[] = $rRow;
			} else {
				$rJobs[] = $rJob;
			}
		}
		if ($rSkipped !== []) {
			self::skippedJobs($rSkipped);
		}
		return ['jobs' => $rJobs];
	}

	/**
	 * Audit the crontab rows the section leaves out, once per change of them.
	 *
	 * @param list<array{filename: string, time: string}> $rSkipped
	 */
	private static function skippedJobs(array $rSkipped): void {
		try {
			$rHash = hash('sha256', (string) json_encode($rSkipped));
			if (ClusterMeta::get('replica_crontab_skipped') !== $rHash) {
				ClusterMeta::set('replica_crontab_skipped', $rHash);
				ClusterAudit::log('replica.crontab_skipped', null, ['jobs' => $rSkipped], 'system');
			}
		} catch (\Throwable) {
			// The section goes out regardless.
		}
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
	 * The node list changed (a node revoked, re-enrolled, activated or
	 * quarantined): drop the cached sections and tell every other node at
	 * once whose agent takes `config.changed` (COMMANDS on, and the
	 * FEATURE_CONFIG_CHANGED feature said at hello), so a parent stops
	 * trusting a revoked child's key before its next poll. The command is
	 * restrictive, so it signs without a licence, and a newer announcement
	 * supersedes one not yet acked. Every other node sees the change at its
	 * next poll. Never fails the change: a node that cannot be told is
	 * skipped.
	 *
	 * @return int the nodes told
	 */
	public static function nodesChanged(ClusterCrypto $rCrypto, int $rServerID): int {
		ReplicaEtagCache::bump();
		try {
			self::db()->query("SELECT * FROM `cluster_nodes` WHERE `server_id` <> ? AND `state` = 'active';", $rServerID);
			$rNodes = self::db()->get_rows() ?: [];
		} catch (\Throwable) {
			return 0;
		}
		$rSent = 0;
		foreach ($rNodes as $rNode) {
			if (!CommandBus::accepts($rNode) || !in_array(self::FEATURE_CONFIG_CHANGED, explode(',', (string) ($rNode['features'] ?? '')), true)) {
				continue;
			}
			try {
				CommandBus::enqueue($rCrypto, (int) $rNode['server_id'], 'config.changed', ['sections' => [ReplicaSections::SERVERS]], 'config.changed');
				$rSent++;
			} catch (\Throwable) {
				// This node sees the change at its next poll (60 s).
			}
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
		$rRow = self::settingsRow('*');
		$rOut = [];
		foreach ($rAllow as $rKey) {
			if (array_key_exists($rKey, $rRow)) {
				$rOut[$rKey] = $rRow[$rKey] === null ? null : (string) $rRow[$rKey];
			}
		}
		return $rOut;
	}

	/**
	 * The `secrets` section: the viewer-token secret (`live_streaming_pass`,
	 * from the settings row) and OPENSSL_EXTRA (the value this php-fpm mints
	 * with), each with its kid, and the value MAIN replaced while it is still
	 * accepted on MAIN's clock. Only OPENSSL_EXTRA has one today
	 * (config/openssl_extra.prev); the stream secret's rotation (plan, section
	 * 10, step 4) will fill its own. An unset value throws: a node refuses an
	 * empty `current` (ReplicaSections::secret), and cron:root_signals sets a
	 * missing stream secret on MAIN within the minute.
	 *
	 * @return array<string, array{current: string, kid: string, previous: ?string, previous_valid_until: ?int}>
	 */
	public static function secretsData(): array {
		$rLive = (string) (self::settingsRow('`live_streaming_pass`')['live_streaming_pass'] ?? '');
		$rExtra = defined('OPENSSL_EXTRA') ? (string) OPENSSL_EXTRA : '';
		if ($rLive === '' || $rExtra === '') {
			throw new \RuntimeException('replica: a secret is not set');
		}
		return [
			'live_streaming_pass' => self::secret('live_streaming_pass', $rLive, null),
			'openssl_extra' => self::secret('openssl_extra', $rExtra, OpensslExtra::previousEntry(ClusterClock::now())),
		];
	}

	/**
	 * @param array{value: string, valid_until: int}|null $rPrevious
	 * @return array{current: string, kid: string, previous: ?string, previous_valid_until: ?int}
	 */
	private static function secret(string $rName, string $rValue, ?array $rPrevious): array {
		return ['current' => $rValue, 'kid' => ReplicaSections::kid($rName, $rValue), 'previous' => $rPrevious['value'] ?? null, 'previous_valid_until' => $rPrevious['valid_until'] ?? null];
	}

	/**
	 * MAIN's settings row, these columns of it; a failed read or no row throws.
	 *
	 * @return array<string, mixed>
	 */
	private static function settingsRow(string $rColumns): array {
		self::read('SELECT ' . $rColumns . ' FROM `settings` LIMIT 1;');
		$rRow = self::db()->get_row();
		if (!is_array($rRow) || $rRow === []) {
			throw new \RuntimeException('replica: no settings row');
		}
		return $rRow;
	}

	/** Run one of a section's reads: a failed one throws, never an empty result. */
	private static function read(string $rQuery, mixed ...$rArgs): void {
		if (self::db()->query($rQuery, ...$rArgs) === false) {
			throw new \RuntimeException('replica: a read failed');
		}
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
	public static function canonical(mixed $rValue): mixed {
		if (!is_array($rValue)) {
			return $rValue;
		}
		if (!array_is_list($rValue)) {
			ksort($rValue, SORT_STRING);
		}
		return array_map([self::class, 'canonical'], $rValue);
	}

	/** A record's payload, as signed: JSON with slashes and Unicode as they are. */
	public static function json(array $rDoc): string {
		return (string) json_encode($rDoc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
	}
}
