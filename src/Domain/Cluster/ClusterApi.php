<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\ClusterSettings;
use XcVm\Core\Cluster\Crypto\Box;
use XcVm\Core\Cluster\Crypto\Canonical;
use XcVm\Core\Cluster\Crypto\ClusterCrypto;
use XcVm\Core\Cluster\Crypto\ClusterRefusedException;
use XcVm\Core\Cluster\Crypto\NodeSig;
use XcVm\Core\Cluster\Crypto\SessionKeys;
use XcVm\Domain\Stream\RecordingFinalizer;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * MAIN's `/cluster/v1/<op>` API (Phase 2: health, challenge, enrol_complete,
 * enrol_code, enrol_code_status, token_refresh, token_rekey, hello, heartbeat;
 * Phase 4: commands, ack; Phase 5: events, recording_complete; Phase 6: conn_snapshot, conn_admit). Transport-free: handle() takes the request
 * as an array and returns status, headers and body, so it is tested without
 * a web server; Public/cluster/index.php is the HTTP shell around it.
 *
 * Order of checks (plan, "Signing, replay, revocation"): nothing about a node
 * is changed, and no nonce is recorded, before the request's MAC (and, for
 * token ops, the node signature) has verified. Refusals are panel-signed and
 * bound to the request nonce and node (DenialFactory).
 */
final class ClusterApi {
	public const PROTO_MIN = 1;
	public const PROTO_MAX = 1;

	/** A node may re-key once per this many seconds. */
	public const REKEY_INTERVAL = 60;

	/** Largest request body accepted (nginx also caps at 8 MB). */
	public const MAX_BODY = 8388608;

	/** op => [method, needs a node signature, allowed node states] */
	private const OPS = [
		'health' => ['GET', false, null],
		'challenge' => ['GET', false, null],
		'enrol_complete' => ['POST', true, ['enrolling']],
		'token_refresh' => ['POST', true, ['active', 'quarantined']],
		'token_rekey' => ['POST', true, ['active']],
		'enrol_code' => ['POST', true, null],
		'enrol_code_status' => ['POST', false, null],
		'hello' => ['POST', false, ['active', 'quarantined']],
		'commands' => ['POST', false, ['active']],
		'ack' => ['POST', false, ['active', 'quarantined']],
		'events' => ['POST', false, ['active']],
		'recording_complete' => ['POST', false, ['active']],
		'conn_snapshot' => ['POST', false, ['active']],
		'conn_admit' => ['POST', false, ['active']],
		'config' => ['POST', false, ['active']],
		'heartbeat' => ['POST', false, ['active', 'quarantined']],
	];

	/**
	 * @param array{method: string, path: string, query?: string, headers: array<string, string>, body?: string, ip?: string} $rReq
	 * @param array<string, mixed> $rSettings
	 * @param array<string, mixed> $rMain The main server's `servers` row.
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	public static function handle(ClusterCrypto $rCrypto, array $rReq, array $rSettings, array $rMain): array {
		$rPath = (string) $rReq['path'];
		$rOp = str_starts_with($rPath, Canonical::PATH_PREFIX) ? substr($rPath, strlen(Canonical::PATH_PREFIX)) : '';
		if (!isset(self::OPS[$rOp])) {
			return DenialFactory::deny($rCrypto, 404, 'UNKNOWN_OP');
		}
		[$rMethod, $rNeedsNodeSig, $rStates] = self::OPS[$rOp];
		if (strtoupper((string) $rReq['method']) !== $rMethod) {
			return DenialFactory::deny($rCrypto, 405, 'BAD_REQUEST');
		}
		if ($rOp === 'health') {
			return self::health($rCrypto);
		}
		if (empty($rSettings['cluster_api_enabled'])) {
			return DenialFactory::deny($rCrypto, 503, 'DISABLED');
		}
		if ($rOp === 'challenge') {
			return self::challenge($rCrypto, (string) ($rReq['query'] ?? ''), $rSettings, $rMain);
		}
		if ($rOp === 'token_rekey') {
			return self::tokenRekey($rCrypto, $rReq, $rStates);
		}
		if ($rOp === 'enrol_code' || $rOp === 'enrol_code_status') {
			return self::enrolByCode($rCrypto, $rOp, $rReq);
		}

		// ── Authenticated ops ────────────────────────────────────────────
		$rH = Canonical::parseHeaders($rReq['headers']);
		$rBody = (string) ($rReq['body'] ?? '');
		if ($rH === null || $rH['sig'] === null || strlen($rBody) > self::MAX_BODY) {
			return DenialFactory::deny($rCrypto, 400, 'BAD_REQUEST');
		}
		if ($rH['proto'] < self::PROTO_MIN || $rH['proto'] > self::PROTO_MAX) {
			return DenialFactory::deny($rCrypto, 426, 'PROTO', $rH['node'], $rH['nonce'], ['min' => self::PROTO_MIN, 'max' => self::PROTO_MAX]);
		}
		if (!Canonical::withinWindow($rH['ts_ms'], ClusterClock::nowMs())) {
			return DenialFactory::deny($rCrypto, 401, 'CLOCK_SKEW', $rH['node'], $rH['nonce']);
		}
		$rNode = str_starts_with($rH['node'], 'sid:') ? null : NodeRegistry::byUuid($rH['node']);
		if ($rNode === null) {
			return DenialFactory::deny($rCrypto, 401, 'UNKNOWN_NODE', $rH['node'], $rH['nonce']);
		}
		if ($rNode['state'] === 'revoked') {
			return DenialFactory::deny($rCrypto, 403, 'NODE_REVOKED', $rH['node'], $rH['nonce'], ['revoked_gen' => (int) $rNode['gen']]);
		}
		try {
			$rKeys = TokenService::session($rCrypto, $rNode, $rH['epoch']);
		} catch (ClusterRefusedException $rE) {
			return self::refusal($rCrypto, $rE->reason(), $rNode, $rH);
		}
		if (!$rKeys instanceof \XcVm\Core\Cluster\Crypto\SessionKeys) {
			return DenialFactory::deny($rCrypto, 401, 'TOKEN_EXPIRED', $rH['node'], $rH['nonce']);
		}

		$rCtx = Canonical::request([
			'proto' => $rH['proto'], 'agent' => $rH['agent'], 'method' => $rMethod, 'path' => $rPath,
			'query' => (string) ($rReq['query'] ?? ''), 'content_type' => self::header($rReq['headers'], 'Content-Type'),
			'content_encoding' => self::header($rReq['headers'], 'Content-Encoding'), 'node' => $rH['node'],
			'epoch' => $rH['epoch'], 'ts_ms' => $rH['ts_ms'], 'nonce' => $rH['nonce'],
		]);
		if (!Canonical::verifyMac($rKeys->rMacUp, $rCtx, $rBody, $rH['sig'])) {
			return DenialFactory::deny($rCrypto, 401, 'BAD_MAC', $rH['node'], $rH['nonce']);
		}
		if ($rNeedsNodeSig) {
			$rNodeSig = self::header($rReq['headers'], Canonical::H_NODE_SIG);
			$rSig = preg_match('/^[0-9a-f]{128}$/', $rNodeSig) ? (string) hex2bin($rNodeSig) : '';
			// The key comes from the extension-sealed epoch record, not the DB row.
			if (!NodeSig::verify($rKeys->rNodeSignPub, 'request', $rCtx . hash('sha256', $rBody, true), $rSig)) {
				return DenialFactory::deny($rCrypto, 401, 'BAD_NODE_SIG', $rH['node'], $rH['nonce']);
			}
		}
		// Authenticated from here on.
		if (($rReplay = self::claimNonce($rCrypto, $rH)) !== null) {
			return $rReplay;
		}
		if (!in_array($rNode['state'], $rStates, true)) {
			return DenialFactory::deny($rCrypto, 409, 'NOT_ACTIVE', $rH['node'], $rH['nonce'], ['state' => $rNode['state']]);
		}
		// hello, config and conn_snapshot hold one of the op's bus permits.
		return ClusterSemaphore::run($rCrypto, $rOp, $rH, static fn(): array => self::dispatch($rCrypto, $rOp, $rReq, $rSettings, $rMain, $rNode, $rKeys, $rCtx, $rH, $rBody));
	}

	/**
	 * Claim an authenticated request's nonce: null when claimed, else its 401
	 * REPLAY. When MAIN only cannot vouch for the nonce yet (a bus that just
	 * started or was lost, NonceStore), the refusal carries retry_after_ms: a
	 * request stamped anew that long after the denial's main_time_ms passes.
	 *
	 * @param array{node: string, nonce: string, ts_ms: int} $rH
	 * @return array{status: int, headers: array<string, string>, body: string}|null
	 */
	private static function claimNonce(ClusterCrypto $rCrypto, array $rH): ?array {
		if (NonceStore::claim($rH['node'], $rH['nonce'], $rH['ts_ms'], $rRetryMs)) {
			return null;
		}
		return DenialFactory::deny($rCrypto, 401, 'REPLAY', $rH['node'], $rH['nonce'], $rRetryMs === null ? [] : ['retry_after_ms' => $rRetryMs]);
	}

	/**
	 * An authenticated session op, past its nonce and node state: open the
	 * BOX and run the handler.
	 *
	 * @param 'enrol_complete'|'token_refresh'|'hello'|'heartbeat'|'commands'|'ack'|'events'|'recording_complete'|'conn_snapshot'|'conn_admit'|'config' $rOp
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	private static function dispatch(ClusterCrypto $rCrypto, string $rOp, array $rReq, array $rSettings, array $rMain, array $rNode, SessionKeys $rKeys, string $rCtx, array $rH, string $rBody): array {
		$rPlain = Box::open($rKeys->rEncUp, $rCtx, $rBody);
		$rPayload = $rPlain === null ? null : json_decode($rPlain, true);
		if (!is_array($rPayload)) {
			return DenialFactory::deny($rCrypto, 400, 'BAD_REQUEST', $rH['node'], $rH['nonce']);
		}
		TokenService::markUsed($rNode, $rH['epoch'], $rKeys->rExp);

		return match ($rOp) {
			'enrol_complete' => self::enrolComplete($rCrypto, $rNode, $rKeys, $rCtx, $rH, $rPayload, $rSettings, $rMain, (string) ($rReq['ip'] ?? '')),
			'token_refresh' => self::tokenRefresh($rCrypto, $rNode, $rKeys, $rCtx, $rH, $rPayload),
			'hello' => self::hello($rNode, $rKeys, $rCtx, $rH, $rPayload, $rSettings, $rMain),
			'heartbeat' => self::heartbeat($rNode, $rKeys, $rCtx, $rH, $rPayload, $rSettings),
			'commands' => self::commands($rNode, $rKeys, $rCtx, $rPayload),
			'ack' => self::ack($rCrypto, $rNode, $rKeys, $rCtx, $rH, $rPayload),
			'events' => self::events($rCrypto, $rNode, $rKeys, $rCtx, $rH, $rPayload),
			'recording_complete' => self::recordingComplete($rCrypto, $rNode, $rKeys, $rCtx, $rH, $rPayload),
			'conn_snapshot' => self::connSnapshot($rCrypto, $rNode, $rKeys, $rCtx, $rH, $rPayload),
			'conn_admit' => self::connAdmit($rCrypto, $rNode, $rKeys, $rCtx, $rH, $rPayload, $rSettings),
			'config' => self::config($rCrypto, $rNode, $rKeys, $rCtx, $rH, $rPayload),
		};
	}

	/** @return array{status: int, headers: array<string, string>, body: string} */
	private static function health(ClusterCrypto $rCrypto): array {
		$rInfo = $rCrypto->info();
		return DenialFactory::signed($rCrypto, 200, 'hlt', [
			'v' => 1,
			'typ' => 'xcvm-health',
			'main_time_ms' => ClusterClock::nowMs(),
			'proto' => ['min' => self::PROTO_MIN, 'max' => self::PROTO_MAX],
			'api' => (int) ($rInfo['api'] ?? 0),
			'panel_sign_pub' => base64_encode((string) ($rInfo['panel_sign_pub'] ?? '')),
			'panel_box_pub' => base64_encode((string) ($rInfo['panel_box_pub'] ?? '')),
			'panel_fp' => bin2hex((string) ($rInfo['panel_fp'] ?? '')),
		]);
	}

	/** @return array{status: int, headers: array<string, string>, body: string} */
	private static function challenge(ClusterCrypto $rCrypto, string $rQuery, array $rSettings, array $rMain): array {
		parse_str($rQuery, $rQ);
		$rCn = is_string($rQ['cn'] ?? null) ? (string) $rQ['cn'] : '';
		if (!Canonical::validNode($rCn)) {
			return DenialFactory::deny($rCrypto, 400, 'BAD_REQUEST');
		}
		$rChallenge = random_bytes(32);
		// Single use, 180 s: token_rekey (later) consumes it by its hash.
		NonceStore::issue('chal:' . $rCn, substr(hash('sha256', $rChallenge, true), 0, 16));
		return DenialFactory::signed($rCrypto, 200, 'hlt', [
			'v' => 1,
			'typ' => 'xcvm-challenge',
			'cn' => $rCn,
			'challenge' => base64_encode($rChallenge),
			'main_time_ms' => ClusterClock::nowMs(),
			'licence_ok' => (bool) ($rCrypto->info()['licensed'] ?? false),
			'policy' => ClusterPolicy::current($rSettings, $rMain),
		]);
	}

	private static function enrolComplete(ClusterCrypto $rCrypto, array $rNode, SessionKeys $rKeys, string $rCtx, array $rH, array $rP, array $rSettings, array $rMain, string $rIP): array {
		if ($rH['epoch'] !== 1 || ClusterClock::now() > (int) $rNode['enrol_deadline']) {
			return DenialFactory::deny($rCrypto, 409, 'ENROL_EXPIRED', $rH['node'], $rH['nonce']);
		}
		NodeRegistry::update((int) $rNode['server_id'], [
			'state' => 'active',
			'instance_id' => self::short($rP['instance_id'] ?? null),
			'boot_id' => self::short($rP['boot_id'] ?? null),
			'agent_version' => self::short($rP['agent_version'] ?? null, 32),
			'proto' => $rH['proto'],
			'enrol_deadline' => null,
			'last_seen_at' => ClusterClock::nowMs(),
		]);
		ClusterAudit::log('node.enrol_complete', (int) $rNode['server_id'], ['node' => $rNode['node_uuid'], 'agent' => $rP['agent_version'] ?? null], 'node', $rIP ?: null);
		return ClusterReply::boxed($rKeys, $rCtx, [
			'state' => 'active', 'mode' => (int) $rNode['mode'], 'flows' => (int) $rNode['flows'], 'gen' => (int) $rNode['gen'],
			'main_time_ms' => ClusterClock::nowMs(), 'policy' => ClusterPolicy::current($rSettings, $rMain),
		]);
	}

	private static function tokenRefresh(ClusterCrypto $rCrypto, array $rNode, SessionKeys $rKeys, string $rCtx, array $rH, array $rP): array {
		$rEph = is_string($rP['eph_pub'] ?? null) ? base64_decode((string) $rP['eph_pub'], true) : false;
		if ($rEph === false || strlen($rEph) !== 32) {
			return DenialFactory::deny($rCrypto, 400, 'BAD_REQUEST', $rH['node'], $rH['nonce']);
		}
		try {
			$rIssued = TokenService::refresh($rCrypto, NodeRegistry::byUuid((string) $rNode['node_uuid']) ?? $rNode, $rH['epoch'], $rEph);
		} catch (ClusterRefusedException $rE) {
			return self::refusal($rCrypto, $rE->reason(), $rNode, $rH);
		}
		return ClusterReply::boxed($rKeys, $rCtx, [
			'token_sealed' => base64_encode((string) $rIssued['token_sealed']),
			'epoch' => (int) $rIssued['epoch'], 'nbf' => (int) $rIssued['nbf'], 'exp' => (int) $rIssued['exp'],
			'refresh_at' => (int) $rIssued['refresh_at'], 'main_time_ms' => ClusterClock::nowMs(),
		]);
	}

	/**
	 * `token_rekey`: recovery for a node whose tokens have all expired while
	 * its keys are intact. There is no session, so the request carries no MAC:
	 * it is node-signed (the enrolled key), its body is SEALed to the panel box
	 * key under the request context, and it must return a challenge from
	 * `GET challenge?cn=` once. The reply is panel-signed (`pre`, a granting
	 * record: the extension signs it only under a valid licence) and names
	 * this node and request; the token inside is sealed to the agent's new
	 * per-epoch key.
	 *
	 * Order, as for session ops: nothing is recorded before the node signature
	 * verifies. Then the nonce, the node state, the once-a-minute limit, the
	 * body, the challenge, the attestation, and finally the licence (the mint).
	 *
	 * @param list<string> $rStates
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	private static function tokenRekey(ClusterCrypto $rCrypto, array $rReq, array $rStates): array {
		$rH = Canonical::parseHeaders($rReq['headers']);
		$rBody = (string) ($rReq['body'] ?? '');
		if ($rH === null || $rH['sig'] !== null || $rH['epoch'] !== 0 || $rBody === '' || strlen($rBody) > 65536) {
			return DenialFactory::deny($rCrypto, 400, 'BAD_REQUEST');
		}
		if ($rH['proto'] < self::PROTO_MIN || $rH['proto'] > self::PROTO_MAX) {
			return DenialFactory::deny($rCrypto, 426, 'PROTO', $rH['node'], $rH['nonce'], ['min' => self::PROTO_MIN, 'max' => self::PROTO_MAX]);
		}
		if (!Canonical::withinWindow($rH['ts_ms'], ClusterClock::nowMs())) {
			return DenialFactory::deny($rCrypto, 401, 'CLOCK_SKEW', $rH['node'], $rH['nonce']);
		}
		$rNode = str_starts_with($rH['node'], 'sid:') ? null : NodeRegistry::byUuid($rH['node']);
		if ($rNode === null) {
			return DenialFactory::deny($rCrypto, 401, 'UNKNOWN_NODE', $rH['node'], $rH['nonce']);
		}
		if ($rNode['state'] === 'revoked') {
			return DenialFactory::deny($rCrypto, 403, 'NODE_REVOKED', $rH['node'], $rH['nonce'], ['revoked_gen' => (int) $rNode['gen']]);
		}
		$rCtx = Canonical::request([
			'proto' => $rH['proto'], 'agent' => $rH['agent'], 'method' => 'POST', 'path' => (string) $rReq['path'],
			'query' => (string) ($rReq['query'] ?? ''), 'content_type' => self::header($rReq['headers'], 'Content-Type'),
			'content_encoding' => self::header($rReq['headers'], 'Content-Encoding'), 'node' => $rH['node'],
			'epoch' => 0, 'ts_ms' => $rH['ts_ms'], 'nonce' => $rH['nonce'],
		]);
		$rNodeSig = self::header($rReq['headers'], Canonical::H_NODE_SIG);
		$rSig = preg_match('/^[0-9a-f]{128}$/', $rNodeSig) ? (string) hex2bin($rNodeSig) : '';
		if (!NodeSig::verify((string) $rNode['node_sign_pub'], 'request', $rCtx . hash('sha256', $rBody, true), $rSig)) {
			return DenialFactory::deny($rCrypto, 401, 'BAD_NODE_SIG', $rH['node'], $rH['nonce']);
		}
		// Authenticated from here on.
		if (($rReplay = self::claimNonce($rCrypto, $rH)) !== null) {
			return $rReplay;
		}
		if (!in_array($rNode['state'], $rStates, true)) {
			return DenialFactory::deny($rCrypto, 409, 'NOT_ACTIVE', $rH['node'], $rH['nonce'], ['state' => $rNode['state']]);
		}
		// A bus permit first: a busy MAIN spends neither the minute nor the challenge.
		return ClusterSemaphore::run($rCrypto, 'token_rekey', $rH, static fn(): array => self::rekeyAuthenticated($rCrypto, $rNode, $rH, $rCtx, $rBody));
	}

	/**
	 * `token_rekey` past its node signature, nonce and node state.
	 *
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	private static function rekeyAuthenticated(ClusterCrypto $rCrypto, array $rNode, array $rH, string $rCtx, string $rBody): array {
		// Once a minute, counted per attempt: the slot is a claim on this minute.
		$rSlot = intdiv(ClusterClock::now(), self::REKEY_INTERVAL);
		if (!NonceStore::claim('rekey:' . $rH['node'], substr(hash('sha256', (string) $rSlot, true), 0, 16))) {
			return DenialFactory::deny($rCrypto, 429, 'RATE_LIMITED', $rH['node'], $rH['nonce'], ['retry_after_ms' => (($rSlot + 1) * self::REKEY_INTERVAL - ClusterClock::now()) * 1000]);
		}
		try {
			$rPlain = $rCrypto->openSealed('rekey', $rBody, $rCtx);
		} catch (ClusterRefusedException) {
			$rPlain = null;
		}
		$rP = $rPlain === null ? null : json_decode($rPlain, true);
		$rChallenge = is_array($rP) && is_string($rP['challenge'] ?? null) ? base64_decode((string) $rP['challenge'], true) : false;
		$rEph = is_array($rP) && is_string($rP['eph_pub'] ?? null) ? base64_decode((string) $rP['eph_pub'], true) : false;
		if ($rChallenge === false || strlen($rChallenge) !== 32 || $rEph === false || strlen($rEph) !== 32) {
			return DenialFactory::deny($rCrypto, 400, 'BAD_REQUEST', $rH['node'], $rH['nonce']);
		}
		if (!NonceStore::consume('chal:' . $rH['node'], substr(hash('sha256', $rChallenge, true), 0, 16))) {
			return DenialFactory::deny($rCrypto, 401, 'CHALLENGE', $rH['node'], $rH['nonce']);
		}
		$rInstance = self::short($rP['instance_id'] ?? null);
		if (!empty($rNode['instance_id']) && ($rInstance === null || !hash_equals((string) $rNode['instance_id'], $rInstance))) {
			// Authenticated evidence of a clone, as in hello: the admin decides.
			NodeRegistry::update((int) $rNode['server_id'], ['state' => 'quarantined', 'quarantine_reason' => 'instance_id changed (re-key)']);
			ClusterAudit::log('node.quarantine', (int) $rNode['server_id'], ['reason' => 'rekey attest', 'was' => $rNode['instance_id'], 'now' => $rInstance], 'node');
			return DenialFactory::deny($rCrypto, 409, 'NOT_ACTIVE', $rH['node'], $rH['nonce'], ['state' => 'quarantined']);
		}
		try {
			$rIssued = TokenService::rekey($rCrypto, $rNode, $rEph);
			NodeRegistry::update((int) $rNode['server_id'], [
				'boot_id' => self::short($rP['boot_id'] ?? null), 'agent_version' => self::short($rP['agent_version'] ?? null, 32),
				'proto' => $rH['proto'], 'last_seen_at' => ClusterClock::nowMs(),
			]);
			return DenialFactory::signed($rCrypto, 200, 'pre', [
				'v' => 1, 'typ' => 'xcvm-rekey', 'node' => $rH['node'], 'req_nonce' => bin2hex($rH['nonce']),
				'token_sealed' => base64_encode((string) $rIssued['token_sealed']),
				'epoch' => (int) $rIssued['epoch'], 'nbf' => (int) $rIssued['nbf'], 'exp' => (int) $rIssued['exp'],
				'refresh_at' => (int) $rIssued['refresh_at'], 'main_time_ms' => ClusterClock::nowMs(),
			]);
		} catch (ClusterRefusedException $rE) {
			return self::refusal($rCrypto, $rE->reason(), $rNode, $rH);
		}
	}

	/**
	 * `enrol_code` and `enrol_code_status`: a node with no identity on MAIN yet
	 * (`X-XCVM-Node: sid:<n>`, epoch 0), authenticated by the code's K_req.
	 * `enrol_code` is also node-signed with the key it asks MAIN to enrol, and
	 * its body is SEALed to the panel box key. Replies are MAC'd under K_res;
	 * an approved one also carries the panel's `pre` signature.
	 *
	 * A wrong MAC changes nothing: no nonce, no attempt counted (the attempts
	 * are the admin's wrong SAS entries), so a code cannot be burned without
	 * its secret.
	 *
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	private static function enrolByCode(ClusterCrypto $rCrypto, string $rOp, array $rReq): array {
		$rH = Canonical::parseHeaders($rReq['headers']);
		$rBody = (string) ($rReq['body'] ?? '');
		if ($rH === null || $rH['sig'] === null || $rH['epoch'] !== 0 || !str_starts_with($rH['node'], 'sid:') || strlen($rBody) > 65536) {
			return DenialFactory::deny($rCrypto, 400, 'BAD_REQUEST');
		}
		if ($rH['proto'] < self::PROTO_MIN || $rH['proto'] > self::PROTO_MAX) {
			return DenialFactory::deny($rCrypto, 426, 'PROTO', $rH['node'], $rH['nonce'], ['min' => self::PROTO_MIN, 'max' => self::PROTO_MAX]);
		}
		if (!Canonical::withinWindow($rH['ts_ms'], ClusterClock::nowMs())) {
			return DenialFactory::deny($rCrypto, 401, 'CLOCK_SKEW', $rH['node'], $rH['nonce']);
		}
		$rSid = (int) substr($rH['node'], 4);
		$rPending = $rOp === 'enrol_code_status' ? EnrolCodeService::request($rSid) : null;
		$rCode = $rOp === 'enrol_code' ? EnrolCodeService::code($rCrypto, $rSid)
			: ($rPending === null ? null : EnrolCodeService::code($rCrypto, $rSid, (int) $rPending['code_id'], false));
		if ($rCode === null) {
			return DenialFactory::deny($rCrypto, 401, 'CODE_INVALID', $rH['node'], $rH['nonce']);
		}
		$rCtx = Canonical::request([
			'proto' => $rH['proto'], 'agent' => $rH['agent'], 'method' => 'POST', 'path' => (string) $rReq['path'],
			'query' => (string) ($rReq['query'] ?? ''), 'content_type' => self::header($rReq['headers'], 'Content-Type'),
			'content_encoding' => self::header($rReq['headers'], 'Content-Encoding'), 'node' => $rH['node'],
			'epoch' => 0, 'ts_ms' => $rH['ts_ms'], 'nonce' => $rH['nonce'],
		]);
		if (!Canonical::verifyMac($rCode['req'], $rCtx, $rBody, $rH['sig'])) {
			return DenialFactory::deny($rCrypto, 401, 'BAD_MAC', $rH['node'], $rH['nonce']);
		}

		if ($rOp === 'enrol_code_status') {
			if (($rReplay = self::claimNonce($rCrypto, $rH)) !== null) {
				return $rReplay;
			}
			$rP = json_decode($rBody, true);
			if (!is_array($rP) || ($rP['node_uuid'] ?? null) !== $rPending['node_uuid']) {
				return DenialFactory::deny($rCrypto, 400, 'BAD_REQUEST', $rH['node'], $rH['nonce']);
			}
			if ($rPending['state'] === 'approved') {
				$rReply = json_decode((string) $rPending['reply'], true);
				return ClusterReply::maced($rCode['res'], $rCtx, (string) $rReply['doc'], [Canonical::H_PANEL_SIG => (string) $rReply['sig']]);
			}
			return ClusterReply::maced($rCode['res'], $rCtx, (string) json_encode([
				'v' => 1, 'typ' => 'xcvm-enrol-status', 'state' => (string) $rPending['state'], 'main_time_ms' => ClusterClock::nowMs(),
			]));
		}

		try {
			$rPlain = $rCrypto->openSealed('enrol_code', $rBody, $rCtx);
		} catch (ClusterRefusedException) {
			$rPlain = null;
		}
		$rP = $rPlain === null ? null : json_decode($rPlain, true);
		$rKey = static fn(string $rName) => is_array($rP) && is_string($rP[$rName] ?? null) ? base64_decode((string) $rP[$rName], true) : false;
		$rNode = [
			'node_uuid' => is_array($rP) && is_string($rP['node_uuid'] ?? null) ? (string) $rP['node_uuid'] : '',
			'sign_pub' => $rKey('sign_pub'), 'box_pub' => $rKey('box_pub'), 'eph_pub' => $rKey('eph_pub'),
			'instance_id' => self::short($rP['instance_id'] ?? null),
		];
		foreach (['sign_pub', 'box_pub', 'eph_pub'] as $rName) {
			if (!is_string($rNode[$rName]) || strlen($rNode[$rName]) !== 32) {
				return DenialFactory::deny($rCrypto, 400, 'BAD_REQUEST', $rH['node'], $rH['nonce']);
			}
		}
		if (!EnrolmentService::validUuid($rNode['node_uuid'])) {
			return DenialFactory::deny($rCrypto, 400, 'BAD_REQUEST', $rH['node'], $rH['nonce']);
		}
		$rNodeSig = self::header($rReq['headers'], Canonical::H_NODE_SIG);
		$rSig = preg_match('/^[0-9a-f]{128}$/', $rNodeSig) ? (string) hex2bin($rNodeSig) : '';
		if (!NodeSig::verify($rNode['sign_pub'], 'request', $rCtx . hash('sha256', $rBody, true), $rSig)) {
			return DenialFactory::deny($rCrypto, 401, 'BAD_NODE_SIG', $rH['node'], $rH['nonce']);
		}
		if (($rReplay = self::claimNonce($rCrypto, $rH)) !== null) {
			return $rReplay;
		}
		$rRefused = EnrolCodeService::submit($rCode, $rSid, $rNode, (string) ($rReq['ip'] ?? ''));
		if ($rRefused !== null) {
			return DenialFactory::deny($rCrypto, $rRefused === 'BAD_REQUEST' ? 400 : 409, $rRefused, $rH['node'], $rH['nonce']);
		}
		return ClusterReply::maced($rCode['res'], $rCtx, (string) json_encode([
			'v' => 1, 'typ' => 'xcvm-enrol-status', 'state' => 'pending_approval', 'main_time_ms' => ClusterClock::nowMs(),
		]));
	}

	private static function hello(array $rNode, SessionKeys $rKeys, string $rCtx, array $rH, array $rP, array $rSettings, array $rMain): array {
		$rInstance = self::short($rP['instance_id'] ?? null);
		$rFields = ['boot_id' => self::short($rP['boot_id'] ?? null), 'agent_version' => self::short($rP['agent_version'] ?? null, 32), 'proto' => $rH['proto'], 'last_seen_at' => ClusterClock::nowMs(), 'features' => self::features($rP['features'] ?? null)];
		$rState = (string) $rNode['state'];
		if ($rInstance !== null && !empty($rNode['instance_id']) && !hash_equals((string) $rNode['instance_id'], $rInstance) && $rState === 'active') {
			// Authenticated evidence of a clone: the same token from another install.
			$rState = 'quarantined';
			$rFields['state'] = $rState;
			$rFields['quarantine_reason'] = 'instance_id changed';
			ClusterAudit::log('node.quarantine', (int) $rNode['server_id'], ['reason' => 'instance_id', 'was' => $rNode['instance_id'], 'now' => $rInstance], 'node');
		} elseif (empty($rNode['instance_id'])) {
			$rFields['instance_id'] = $rInstance;
		}
		NodeRegistry::update((int) $rNode['server_id'], $rFields);
		return ClusterReply::boxed($rKeys, $rCtx, [
			'state' => $rState, 'mode' => (int) $rNode['mode'], 'flows' => (int) $rNode['flows'], 'gen' => (int) $rNode['gen'],
			'epoch' => $rH['epoch'], 'main_time_ms' => ClusterClock::nowMs(),
			'proto' => ['min' => self::PROTO_MIN, 'max' => self::PROTO_MAX], 'policy' => ClusterPolicy::current($rSettings, $rMain),
			'cursors' => ['p0' => (int) $rNode['useq_p0'], 'p1' => (int) $rNode['useq_p1']],
			'offline_admission' => self::offlineAdmission($rSettings), 'p2_types' => EventIngest::p2Types(),
		]);
	}

	/**
	 * `lb_offline_admission`, as the agent applies it to a viewer without an
	 * `adm` claim while conn_admit gets no answer: `local`, `allow` or `deny`.
	 * In hello and every heartbeat, so a change reaches a node within one
	 * heartbeat and needs no CONFIG flow.
	 */
	private static function offlineAdmission(array $rSettings): string {
		[$rDefault, $rAllowed] = ClusterSettings::ENUMS['lb_offline_admission'];
		$rValue = (string) ($rSettings['lb_offline_admission'] ?? $rDefault);
		return in_array($rValue, $rAllowed, true) ? $rValue : $rDefault;
	}

	/**
	 * What the agent says it does (e.g. `hls_reaper`), as a comma-separated
	 * list for `cluster_nodes.features`; null when it says nothing (an older
	 * agent), so MAIN keeps doing everything itself.
	 */
	public static function features(mixed $rList): ?string {
		if (!is_array($rList)) {
			return null;
		}
		$rOut = [];
		foreach (array_slice($rList, 0, 16) as $rFeature) {
			if (is_string($rFeature) && preg_match('/^[a-z0-9_]{1,32}$/', $rFeature)) {
				$rOut[] = $rFeature;
			}
		}
		$rOut = array_values(array_unique($rOut));
		sort($rOut);
		return $rOut === [] ? null : substr(implode(',', $rOut), 0, 255);
	}

	private static function heartbeat(array $rNode, SessionKeys $rKeys, string $rCtx, array $rH, array $rP, array $rSettings): array {
		HeartbeatService::record($rNode, $rP, $rH['ts_ms']);
		// A node that holds its viewers sends its registry's digest; a drift
		// that outlives the events in flight gets its snapshot asked for.
		$rWant = false;
		if (((int) $rNode['flows'] & NodeRegistry::FLOW_CONNECTIONS) !== 0 && isset($rP['conn_digest'])) {
			try {
				$rWant = ConnectionDigest::check((int) $rNode['server_id'], $rP['conn_digest']);
			} catch (\Throwable) {
				$rWant = false; // the store is down: the next heartbeat checks again
			}
		}
		// policy_ver lets the agent notice a new transport policy (MAIN URLs)
		// within one heartbeat; it then says hello again to fetch it.
		return ClusterReply::boxed($rKeys, $rCtx, [
			'state' => (string) $rNode['state'], 'mode' => (int) $rNode['mode'], 'flows' => (int) $rNode['flows'],
			'main_time_ms' => ClusterClock::nowMs(), 'pending' => 0, 'policy_ver' => intval($rSettings['cluster_policy_ver'] ?? 1),
			'offline_admission' => self::offlineAdmission($rSettings), 'p2_types' => EventIngest::p2Types(),
		] + ($rWant ? ['want_conn_snapshot' => true] : []));
	}

	/** Longest a `commands` long-poll is held (ms). */
	public const COMMANDS_WAIT_MAX_MS = 20000;

	/**
	 * `commands`: the node's queued commands after its high-water, panel-signed
	 * (`cmd`) each. Held up to `wait_ms` while there are none, so a command
	 * reaches the node within a poll step of being queued.
	 */
	private static function commands(array $rNode, SessionKeys $rKeys, string $rCtx, array $rP): array {
		$rAfter = max(0, (int) ($rP['after_seq'] ?? 0));
		$rWait = max(0, min(self::COMMANDS_WAIT_MAX_MS, (int) ($rP['wait_ms'] ?? 0)));
		$rDeadline = microtime(true) + $rWait / 1000;
		while (true) {
			$rCommands = CommandBus::pending((int) $rNode['server_id'], $rAfter);
			$rLeft = $rDeadline - microtime(true);
			if ($rCommands !== [] || $rLeft <= 0) {
				break;
			}
			// Woken by the cluster bus when a command is queued, with the DB
			// connection released meanwhile; polled without the bus.
			if (ClusterBus::waitNodeReleasing((int) $rNode['server_id'], min($rLeft, 5.0), DatabaseFactory::get()) === null) {
				usleep(250000);
			}
		}
		return ClusterReply::boxed($rKeys, $rCtx, ['commands' => $rCommands, 'main_time_ms' => ClusterClock::nowMs()]);
	}

	/** `ack`: a command's outcome, accepted only for this node's own commands. */
	private static function ack(ClusterCrypto $rCrypto, array $rNode, SessionKeys $rKeys, string $rCtx, array $rH, array $rP): array {
		$rCmdID = is_string($rP['cmd_id'] ?? null) && preg_match('/^[0-9a-f]{32}$/', (string) $rP['cmd_id']) ? (string) $rP['cmd_id'] : null;
		if ($rCmdID === null || !CommandBus::ack((int) $rNode['server_id'], $rCmdID, !empty($rP['ok']), is_string($rP['result'] ?? null) ? (string) $rP['result'] : '')) {
			return DenialFactory::deny($rCrypto, 400, 'BAD_REQUEST', $rH['node'], $rH['nonce']);
		}
		return ClusterReply::boxed($rKeys, $rCtx, ['ok' => true, 'main_time_ms' => ClusterClock::nowMs()]);
	}

	/**
	 * `events`: a batch from one lane, applied in order (EventIngest). A P0 gap
	 * is refused with the number MAIN expects, and the node resends from there.
	 * P2 has no number: `first_useq` is not read, and the reply's `useq` is 0.
	 */
	private static function events(ClusterCrypto $rCrypto, array $rNode, SessionKeys $rKeys, string $rCtx, array $rH, array $rP): array {
		$rLane = $rP['lane'] ?? null;
		$rFirst = $rLane === 'p2' ? 0 : ($rP['first_useq'] ?? null);
		$rEvents = $rP['events'] ?? null;
		if (!in_array($rLane, ['p0', 'p1', 'p2'], true) || !is_int($rFirst) || ($rLane !== 'p2' && $rFirst < 1) || !is_array($rEvents) || !array_is_list($rEvents) || count($rEvents) > EventIngest::MAX_EVENTS) {
			return DenialFactory::deny($rCrypto, 400, 'BAD_REQUEST', $rH['node'], $rH['nonce']);
		}
		try {
			$rOut = EventIngest::ingest($rNode, $rLane, $rFirst, $rEvents);
		} catch (\Throwable) {
			return DenialFactory::deny($rCrypto, 503, 'DB', $rH['node'], $rH['nonce']);
		}
		if (!$rOut['ok']) {
			return DenialFactory::deny($rCrypto, 409, 'USEQ_GAP', $rH['node'], $rH['nonce'], ['expected_useq' => $rOut['expected_useq']]);
		}
		return ClusterReply::boxed($rKeys, $rCtx, $rOut + ['main_time_ms' => ClusterClock::nowMs()]);
	}

	/**
	 * `recording_complete`: the VOD for a recording the node finished, created
	 * once (RecordingFinalizer). The node names its file after the id, then
	 * reports `recording.state` 2 once it has converted it.
	 */
	private static function recordingComplete(ClusterCrypto $rCrypto, array $rNode, SessionKeys $rKeys, string $rCtx, array $rH, array $rP): array {
		if (((int) $rNode['flows'] & NodeRegistry::FLOW_CONTENT) === 0) {
			return DenialFactory::deny($rCrypto, 409, 'FLOW_OFF', $rH['node'], $rH['nonce'], ['flow' => 'content']);
		}
		$rRecording = $rP['recording_id'] ?? null;
		$rIcon = $rP['stream_icon'] ?? null;
		if (!is_int($rRecording) || $rRecording <= 0 || ($rIcon !== null && !is_string($rIcon))) {
			return DenialFactory::deny($rCrypto, 400, 'BAD_REQUEST', $rH['node'], $rH['nonce']);
		}
		try {
			$rID = RecordingFinalizer::create($rRecording, (int) $rNode['server_id'], $rIcon);
		} catch (\Throwable) {
			return DenialFactory::deny($rCrypto, 503, 'DB', $rH['node'], $rH['nonce']);
		}
		if ($rID === null) {
			return DenialFactory::deny($rCrypto, 400, 'BAD_REQUEST', $rH['node'], $rH['nonce']);
		}
		return ClusterReply::boxed($rKeys, $rCtx, ['stream_id' => $rID, 'main_time_ms' => ClusterClock::nowMs()]);
	}

	/**
	 * `conn_snapshot`: one chunk of a CONNECTIONS node's registry, applied
	 * with the last (ConnectionSnapshot). A chunk out of order is refused with
	 * the number MAIN expects; the node then starts over.
	 */
	private static function connSnapshot(ClusterCrypto $rCrypto, array $rNode, SessionKeys $rKeys, string $rCtx, array $rH, array $rP): array {
		if (((int) $rNode['flows'] & NodeRegistry::FLOW_CONNECTIONS) === 0) {
			return DenialFactory::deny($rCrypto, 409, 'FLOW_OFF', $rH['node'], $rH['nonce'], ['flow' => 'connections']);
		}
		try {
			$rOut = ConnectionSnapshot::receive((int) $rNode['server_id'], $rP);
		} catch (\Throwable) {
			return DenialFactory::deny($rCrypto, 503, 'DB', $rH['node'], $rH['nonce']);
		}
		if (!empty($rOut['bad'])) {
			return DenialFactory::deny($rCrypto, 400, 'BAD_REQUEST', $rH['node'], $rH['nonce']);
		}
		if (!$rOut['ok']) {
			return DenialFactory::deny($rCrypto, 409, 'SNAP_GAP', $rH['node'], $rH['nonce'], ['expected_seq' => (int) $rOut['expected_seq']]);
		}
		if (!empty($rOut['done'])) {
			ClusterAudit::log('conn.snapshot', (int) $rNode['server_id'], ['applied' => $rOut['applied'], 'removed' => $rOut['removed'], 'dropped' => $rOut['dropped']], 'node');
		}
		unset($rOut['ok']);
		return ClusterReply::boxed($rKeys, $rCtx, $rOut + ['main_time_ms' => ClusterClock::nowMs()]);
	}

	/**
	 * `conn_admit`: admission for a viewer the node is about to record whose
	 * token has no `adm` claim (ConnectionAdmission::forNode), for a node that
	 * holds its viewers. The line is MAIN's, never the node's; the answer is
	 * {admit, exp, reason?}.
	 */
	private static function connAdmit(ClusterCrypto $rCrypto, array $rNode, SessionKeys $rKeys, string $rCtx, array $rH, array $rP, array $rSettings): array {
		if (((int) $rNode['flows'] & NodeRegistry::FLOW_CONNECTIONS) === 0) {
			return DenialFactory::deny($rCrypto, 409, 'FLOW_OFF', $rH['node'], $rH['nonce'], ['flow' => 'connections']);
		}
		try {
			$rOut = ConnectionAdmission::forNode($rSettings, (int) $rNode['server_id'], $rP);
		} catch (\Throwable) {
			return DenialFactory::deny($rCrypto, 503, 'DB', $rH['node'], $rH['nonce']);
		}
		if ($rOut === null) {
			return DenialFactory::deny($rCrypto, 400, 'BAD_REQUEST', $rH['node'], $rH['nonce']);
		}
		return ClusterReply::boxed($rKeys, $rCtx, $rOut + ['main_time_ms' => ClusterClock::nowMs()]);
	}

	/**
	 * `config`: the node's replica (ReplicaBuilder), in shadow until its CONFIG
	 * flow is on. The blocklist: a `blk` delta from `blocklist_since`, or the
	 * whole section when there is no delta to give. `have` maps each section to
	 * the ETag the node holds, so a section it already has is not sent again;
	 * a section sent whole (settings) goes only to an agent that names it.
	 */
	private static function config(ClusterCrypto $rCrypto, array $rNode, SessionKeys $rKeys, string $rCtx, array $rH, array $rP): array {
		$rSince = $rP['blocklist_since'] ?? 0;
		$rHave = is_array($rP['have'] ?? null) ? $rP['have'] : [];
		if (!is_int($rSince) || $rSince < 0) {
			return DenialFactory::deny($rCrypto, 400, 'BAD_REQUEST', $rH['node'], $rH['nonce']);
		}
		foreach ($rHave as $rEtag) {
			if (!is_string($rEtag) || ($rEtag !== '' && !preg_match('/^[0-9a-f]{64}$/', $rEtag))) {
				return DenialFactory::deny($rCrypto, 400, 'BAD_REQUEST', $rH['node'], $rH['nonce']);
			}
		}
		try {
			$rOut = [ReplicaBuilder::SECTION_BLOCKLIST => ReplicaBuilder::blocklist($rCrypto, $rNode, $rSince, $rHave[ReplicaBuilder::SECTION_BLOCKLIST] ?? '')];
			// Sent whole: only to an agent that asks for them (have names the section).
			foreach (array_keys(ReplicaBuilder::WHOLE) as $rSection) {
				if (array_key_exists($rSection, $rHave)) {
					$rOut[$rSection] = ReplicaBuilder::whole($rCrypto, $rNode, $rSection, (string) $rHave[$rSection]);
				}
			}
		} catch (ClusterRefusedException $rE) {
			return self::refusal($rCrypto, $rE->reason(), $rNode, $rH);
		} catch (\Throwable) {
			return DenialFactory::deny($rCrypto, 503, 'DB', $rH['node'], $rH['nonce']);
		}
		return ClusterReply::boxed($rKeys, $rCtx, $rOut + ['main_time_ms' => ClusterClock::nowMs()]);
	}

	/** Map an extension refusal to a signed denial. */
	private static function refusal(ClusterCrypto $rCrypto, string $rReason, array $rNode, array $rH): array {
		return match (true) {
			$rReason === 'REVOKED' => DenialFactory::deny($rCrypto, 403, 'NODE_REVOKED', $rH['node'], $rH['nonce'], ['revoked_gen' => (int) $rNode['gen']]),
			$rReason === 'LICENCE' => DenialFactory::deny($rCrypto, 403, 'LICENCE_INVALID', $rH['node'], $rH['nonce']),
			$rReason === 'CLOCK' => DenialFactory::deny($rCrypto, 503, 'CLOCK', $rH['node'], $rH['nonce']),
			default => DenialFactory::deny($rCrypto, 401, 'TOKEN_EXPIRED', $rH['node'], $rH['nonce'], ['detail' => substr($rReason, 0, 32)]),
		};
	}

	private static function header(array $rHeaders, string $rName): string {
		foreach ($rHeaders as $rKey => $rValue) {
			if (strcasecmp((string) $rKey, $rName) === 0) {
				return trim((string) $rValue);
			}
		}
		return '';
	}

	private static function short(mixed $rValue, int $rMax = 64): ?string {
		return is_string($rValue) && $rValue !== '' ? substr(preg_replace('/[^\x21-\x7e]/', '', $rValue) ?? '', 0, $rMax) : null;
	}
}
