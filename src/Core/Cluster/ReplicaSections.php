<?php

namespace XcVm\Core\Cluster;

/**
 * What the node replica's whole sections hold (plan, section 9, "What an LB
 * receives"), shared by MAIN, which builds them (Domain\Cluster\ReplicaBuilder),
 * and the node, which applies them (ReplicaApply). Lives in Core: it ships to
 * LBs, where Domain\Cluster does not.
 *
 * ```text
 * servers  {servers: [SERVER_FIELDS of every server], nodes: [{sid, gen, state, ed_pub}]}
 * node     NODE_FIELDS of the node's own row, and NODE_SETTINGS
 * crontab  {jobs: [{filename, time}]}   enabled rows whose role fits the node's mode
 * cluster  {main_urls, urls_ver, policy_ver, transport, panel_sign_pub,
 *           panel_box_pub, min_proto, off_air}
 * secrets  {live_streaming_pass: SECRET, openssl_extra: SECRET}
 *          SECRET = {kid, current, previous, previous_valid_until}
 * ```
 *
 * Every column of `servers` is in exactly one of SERVER_FIELDS, NODE_FIELDS
 * and SERVER_LOCAL (ReplicaSectionsTest fails on a new column that is not):
 * a replica never carries liveness, telemetry, `watchdog_data`, `php_pids`,
 * or the `api_url*` a node builds itself from its own settings.
 *
 * `secrets` is the one section that carries secrets (SECRET_KEYS, nothing
 * else): the viewer-token secret and OPENSSL_EXTRA, which the `settings`
 * section withholds and a node needs to open the tokens MAIN mints. It is
 * not in WHOLE: MAIN serves it only to an active node in mode 1 or 2, and
 * never keeps it unsealed (ReplicaEtagCache).
 */
final class ReplicaSections {
	public const SETTINGS = 'settings';
	public const SERVERS = 'servers';
	public const NODE = 'node';
	public const CRONTAB = 'crontab';
	public const CLUSTER = 'cluster';
	public const SECRETS = 'secrets';

	/** Sections sent whole, by ETag, to an agent that names them in `have` (and `secrets`, on its own terms). */
	public const WHOLE = [self::SETTINGS, self::SERVERS, self::NODE, self::CRONTAB, self::CLUSTER];

	/**
	 * `secrets`: what it carries, and nothing else. `live_streaming_pass`
	 * keys the viewer tokens, OPENSSL_EXTRA (config/openssl_extra) their
	 * context; both are withheld from the `settings` section.
	 */
	public const SECRET_KEYS = ['live_streaming_pass', 'openssl_extra'];

	/** `servers`: the routing and relay fields of every server, with their types. */
	public const SERVER_FIELDS = [
		'id' => 'int', 'server_type' => 'int', 'server_name' => 'str', 'is_main' => 'int', 'enabled' => 'int',
		'parent_id' => 'str', 'server_ip' => 'str', 'private_ip' => 'str', 'domain_name' => 'str', 'enable_https' => 'int',
		'http_broadcast_port' => 'int', 'https_broadcast_port' => 'int', 'http_ports_add' => 'str', 'https_ports_add' => 'str',
		'rtmp_port' => 'int', 'total_clients' => 'int', 'network_guaranteed_speed' => 'int', 'enable_geoip' => 'int',
		'geoip_countries' => 'str', 'geoip_type' => 'str', 'enable_isp' => 'int', 'isp_names' => 'str', 'isp_type' => 'str',
		'timeshift_only' => 'int', 'random_ip' => 'int', 'enable_proxy' => 'int', 'persistent_connections' => 'int',
		'enable_gzip' => 'int', 'order' => 'int', 'whitelist_ips' => 'str', 'xc_vm_version' => 'str',
	];

	/** `node`: the node's own configuration, from its row; only it gets these. */
	public const NODE_FIELDS = [
		'id' => 'int', 'http_broadcast_port' => 'int', 'https_broadcast_port' => 'int', 'http_ports_add' => 'str',
		'https_ports_add' => 'str', 'rtmp_port' => 'int', 'limit_requests' => 'int', 'limit_burst' => 'int',
		'total_services' => 'int', 'use_disk' => 'int', 'enable_https' => 'int', 'domain_name' => 'str',
		'network_interface' => 'str', 'governor' => 'str', 'sysctl' => 'str', 'time_offset' => 'int',
	];

	/** `node`: the settings that configure a node's nginx (realip, the Ministra redirect). */
	public const NODE_SETTINGS = ['cloudflare' => 'int', 'mag_legacy_redirect' => 'int'];

	/**
	 * `servers` columns no replica carries: liveness and telemetry (MAIN's
	 * view of each node), the node's own reports, and install bookkeeping.
	 * A node materialises them as null.
	 */
	public const SERVER_LOCAL = [
		'status', 'last_check_ago', 'last_status', 'remote_status', 'ping', 'watchdog_data', 'php_pids', 'connections',
		'users', 'requests_per_second', 'server_hardware', 'video_devices', 'audio_devices', 'gpu_info', 'interfaces',
		'governors', 'certbot_ssl', 'certbot_renew', 'uuid', 'ssh_hostkey_sha1',
	];

	/** `servers.nodes[].state`: cluster_nodes states. */
	public const NODE_STATES = ['enrolling', 'active', 'quarantined', 'revoked'];

	/** `cluster.off_air`: the off-air videos, by the settings key of their path. */
	public const OFF_AIR = [
		'connected' => 'connected_video_path', 'not_on_air' => 'not_on_air_video_path', 'banned' => 'banned_video_path',
		'expired' => 'expired_video_path', 'expiring' => 'expiring_video_path',
	];

	/**
	 * The crontab roles that fit a node in this mode: every node runs `all`,
	 * a node that still has MAIN's database (mode 0 or 1) also runs `legacy`,
	 * and `main` never leaves MAIN.
	 *
	 * @return list<string>
	 */
	public static function cronRoles(int $rMode): array {
		return $rMode >= 2 ? ['all'] : ['all', 'legacy'];
	}

	/**
	 * A crontab job as a node writes it into its crontab: a cron: command name
	 * and a five-field schedule, nothing a shell or cron would read more into
	 * (no trailing newline either: `\z`, not `$`).
	 *
	 * @return array{filename: string, time: string}|null
	 */
	public static function cronJob(mixed $rJob): ?array {
		if (!is_array($rJob) || !is_string($rJob['filename'] ?? null) || !is_string($rJob['time'] ?? null)) {
			return null;
		}
		if (!preg_match('/^[a-z0-9_]{1,64}\z/', $rJob['filename']) || !preg_match('/^[0-9*\/,-]+( [0-9*\/,-]+){4}\z/', $rJob['time'])) {
			return null;
		}
		return ['filename' => $rJob['filename'], 'time' => $rJob['time']];
	}

	/**
	 * A secret's key id: an HMAC keyed by the value, cut to 64 bits, which
	 * names the value without revealing it. For OPENSSL_EXTRA it is the
	 * fingerprint every node publishes (OpensslExtra::fingerprint).
	 */
	public static function kid(string $rName, string $rValue): string {
		return substr(hash_hmac('sha256', 'xc_vm ' . $rName . ' fingerprint v1', $rValue), 0, 16);
	}

	/**
	 * One entry of the `secrets` section as a node takes it: `current` and
	 * `kid` non-empty strings, and `previous` (a non-empty string) with
	 * `previous_valid_until` (unix seconds), or both null.
	 *
	 * @return array{current: string, kid: string, previous: ?string, previous_valid_until: ?int}|null
	 */
	public static function secret(mixed $rEntry): ?array {
		if (!is_array($rEntry) || !is_string($rEntry['current'] ?? null) || $rEntry['current'] === '' || !is_string($rEntry['kid'] ?? null) || $rEntry['kid'] === '') {
			return null;
		}
		$rPrevious = $rEntry['previous'] ?? null;
		$rUntil = $rEntry['previous_valid_until'] ?? null;
		if (!($rPrevious === null && $rUntil === null) && !(is_string($rPrevious) && $rPrevious !== '' && is_int($rUntil))) {
			return null;
		}
		return ['current' => $rEntry['current'], 'kid' => $rEntry['kid'], 'previous' => $rPrevious, 'previous_valid_until' => $rUntil];
	}

	/**
	 * A row's fields, typed the same whichever driver read them: integers
	 * as int, text as string, a missing column or NULL as null.
	 *
	 * @param array<string, mixed> $rRow
	 * @param array<string, string> $rFields name => 'int'|'str'
	 * @return array<string, int|string|null>
	 */
	public static function typed(array $rRow, array $rFields): array {
		$rOut = [];
		foreach ($rFields as $rKey => $rType) {
			$rValue = $rRow[$rKey] ?? null;
			if ($rValue === null || is_array($rValue)) {
				$rOut[$rKey] = null;
			} elseif ($rType === 'int') {
				$rOut[$rKey] = (int) $rValue;
			} else {
				$rOut[$rKey] = (string) $rValue;
			}
		}
		return $rOut;
	}
}
