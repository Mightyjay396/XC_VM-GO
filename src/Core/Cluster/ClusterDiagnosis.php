<?php

namespace XcVm\Core\Cluster;

/**
 * What `server:diagnose` says about a node's standing in the cluster API
 * (ADR 0004, Phase 10), from either side:
 *
 * - on the node, from its agent's own report (`GET /v1/status` on the
 *   agent's socket, AgentClient::status()): whether the agent answers, the
 *   state, mode and flows of MAIN's latest reply, the licence fence, the last
 *   heartbeat MAIN answered, MAIN's clock against this machine's, the token
 *   and lease windows, and each event lane's backlog;
 * - on MAIN, from the node's `cluster_nodes` row and its command queue: its
 *   state and health, when it was last heard, its token and the fence window
 *   that follows it, the clock offset its heartbeats carry, and how far its
 *   queued commands lag.
 *
 * Pure: the caller reads the inputs, this judges them. In Core because it
 * runs on both sides (a node's build has no Domain/Cluster).
 *
 * Each check is `{label, value, ok, problem}`; `problem` is the operator's
 * sentence for a check that is not ok, and null otherwise.
 *
 * @package XC_VM_Core_Cluster
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class ClusterDiagnosis {
	/** Flow names, by bit, as the Cluster Nodes page names them. */
	public const FLOWS = [
		NodeFlows::TELEMETRY => 'telemetry',
		NodeFlows::COMMANDS => 'commands',
		NodeFlows::LOGS => 'logs',
		NodeFlows::STREAMS => 'streams',
		NodeFlows::CONTENT => 'content',
		NodeFlows::CONFIG => 'config',
		NodeFlows::CONNECTIONS => 'connections',
		NodeFlows::DATAPLANE => 'dataplane',
	];

	/**
	 * Clock skew (ms) past which a check warns. MAIN refuses a request whose
	 * timestamp is more than 90 s off its own clock (CanonicalRequest), so this
	 * leaves room to act before that happens.
	 */
	public const SKEW_WARN_MS = 30000;

	/** An outbox (an event lane on the node, the command queue on MAIN) whose oldest entry is older than this lags. */
	public const OUTBOX_LAG_SEC = 120;

	/**
	 * The window a node may serve in once it stops reaching MAIN, as MAIN's
	 * settings draw it from the node's token: the lease MAIN signs with a token
	 * runs to `token_exp + lb_partition_tolerance_h` (the extension caps it at
	 * 26 h from issue, which only a tolerance near 24 h reaches), and past the
	 * lease the node drains for `lb_fence_drain_min`. `fence_on` is
	 * `lb_lease_fence`: off, a node serves past both.
	 *
	 * @param array<string, mixed> $rSettings
	 * @return array{lease_until: ?int, drain_until: ?int, tolerance_h: int, drain_min: int, fence_on: bool}
	 */
	public static function fenceWindow(?int $rTokenExp, array $rSettings): array {
		$rTolerance = ClusterSettings::int('lb_partition_tolerance_h', $rSettings['lb_partition_tolerance_h'] ?? null);
		$rDrain = ClusterSettings::int('lb_fence_drain_min', $rSettings['lb_fence_drain_min'] ?? null);
		$rOn = ClusterSettings::int('lb_lease_fence', $rSettings['lb_lease_fence'] ?? null) === 1;
		if ($rTokenExp === null || $rTokenExp <= 0) {
			return ['lease_until' => null, 'drain_until' => null, 'tolerance_h' => $rTolerance, 'drain_min' => $rDrain, 'fence_on' => $rOn];
		}
		$rLease = $rTokenExp + $rTolerance * 3600;
		return ['lease_until' => $rLease, 'drain_until' => $rLease + $rDrain * 60, 'tolerance_h' => $rTolerance, 'drain_min' => $rDrain, 'fence_on' => $rOn];
	}

	/**
	 * The node's view, from its agent's `GET /v1/status`.
	 *
	 * @param array<string, mixed>|null $rStatus The agent's document; null when it did not answer.
	 * @param array<string, mixed> $rSettings The node's settings (fence switch and drain).
	 * @return list<array{label: string, value: string, ok: bool, problem: ?string}>
	 */
	public static function agent(?array $rStatus, int $rNowMs, array $rSettings): array {
		if ($rStatus === null) {
			return [self::check('Agent socket', 'no answer', false, 'xc_agent does not answer on its socket (' . AgentPaths::SOCKET . '): it is stopped, crashed, or older than this panel (no GET /v1/status). Its heartbeats stop with it, so MAIN marks the node offline after cluster_offline_after_sec. Check `bin/xc_agent/run.sh` and the xc_vm service.')];
		}
		$rOut = [self::check('Agent socket', 'answers (xc_agent ' . self::str($rStatus['version'] ?? '') . ')', true)];

		$rState = self::str($rStatus['state'] ?? '');
		$rMode = (int) ($rStatus['mode'] ?? 0);
		$rStateOk = $rState === 'active';
		$rOut[] = self::check('Cluster state', ($rState === '' ? 'no reply from MAIN yet' : $rState) . ' · mode ' . $rMode . ' · flows ' . self::flowNames((int) ($rStatus['flows'] ?? 0)), $rStateOk, match ($rState) {
			'' => 'The agent has had no hello or heartbeat answered since it started: it cannot reach MAIN (see the checks below), or its enrolment never finished.',
			'quarantined' => 'MAIN has quarantined this node: it answers but routes nothing to it. The reason is on MAIN\'s Cluster Nodes page.',
			default => 'MAIN reports this node as `' . $rState . '`.',
		});

		$rFenced = !empty($rStatus['fenced']);
		$rOut[] = self::check('Licence fence', $rFenced ? 'MAIN refuses the session (LICENCE_INVALID)' : 'no', !$rFenced, 'MAIN refuses this node\'s session for want of a licence: its tokens are not refreshed and the node runs on the lease it holds. Re-license MAIN; the node re-keys within a minute.');

		$rBeat = (int) ($rStatus['last_heartbeat_ms'] ?? 0);
		$rEvery = max(1, (int) ($rStatus['heartbeat_sec'] ?? 2));
		$rBeatAge = $rBeat > 0 ? intdiv(max(0, $rNowMs - $rBeat), 1000) : null;
		$rBeatOk = $rBeatAge !== null && $rBeatAge <= max(10, 3 * $rEvery);
		$rOut[] = self::check('Last heartbeat', $rBeatAge === null ? 'none answered since the agent started' : $rBeatAge . 's ago (every ' . $rEvery . 's)', $rBeatOk, 'MAIN has not answered this node\'s heartbeat ' . ($rBeatAge === null ? 'since the agent started' : 'for ' . $rBeatAge . 's') . ': the agent cannot reach MAIN\'s cluster URL, or MAIN refuses it. MAIN marks it suspect after 10 s and offline after cluster_offline_after_sec.');

		$rSeen = (int) ($rStatus['main_time_seen_ms'] ?? 0);
		$rSkew = (int) ($rStatus['main_skew_ms'] ?? 0);
		$rSkewOk = $rSeen === 0 || abs($rSkew) <= self::SKEW_WARN_MS;
		$rOut[] = self::check('Clock vs MAIN', $rSeen === 0 ? 'MAIN\'s time not observed yet' : self::signedMs($rSkew) . ' (MAIN − this machine, observed ' . self::ago($rNowMs - $rSeen) . ')', $rSkewOk, 'This machine\'s clock is ' . self::signedMs(-$rSkew) . ' off MAIN\'s. The agent corrects its requests by it, but the node\'s PHP, its crons and its logs use this clock: sync NTP.');

		$rMainNow = intdiv($rNowMs + ($rSeen === 0 ? 0 : $rSkew), 1000);
		$rToken = is_array($rStatus['token'] ?? null) ? $rStatus['token'] : null;
		$rTokenExp = $rToken !== null ? (int) ($rToken['exp'] ?? 0) : null;
		$rTokenOk = $rTokenExp !== null && $rTokenExp > $rMainNow;
		$rOut[] = self::check('Token', $rToken === null ? 'no valid epoch' : 'epoch ' . (int) ($rToken['epoch'] ?? 0) . ' (gen ' . (int) ($rToken['gen'] ?? 0) . '), expires ' . self::until($rTokenExp, $rMainNow), $rTokenOk, 'The agent holds no token valid on MAIN\'s clock: it re-keys once MAIN answers its challenge. Until then MAIN refuses every op of this node.');

		$rWindow = self::fenceWindow(null, $rSettings);
		$rLease = is_array($rStatus['lease'] ?? null) ? $rStatus['lease'] : null;
		$rLeaseExp = $rLease !== null ? (int) ($rLease['exp'] ?? 0) : null;
		$rRefused = self::str($rStatus['lease_refused'] ?? '');
		if ($rLeaseExp === null) {
			$rOut[] = self::check('Lease', 'none held' . ($rRefused !== '' ? ' (last one refused: ' . $rRefused . ')' : ''), $rRefused === '', 'The agent refused the last lease MAIN sent: ' . $rRefused . '.');
		} else {
			$rDrainUntil = $rLeaseExp + $rWindow['drain_min'] * 60;
			$rValue = 'gen ' . (int) ($rLease['gen'] ?? 0) . ', expires ' . self::until($rLeaseExp, $rMainNow) . ', drain to ' . self::utc($rDrainUntil) . ' · fence ' . ($rWindow['fence_on'] ? 'on' : 'off');
			$rLeaseOk = $rLeaseExp > $rMainNow && $rRefused === '';
			$rProblem = $rLeaseExp <= $rMainNow
				? 'The lease this node holds has run out on MAIN\'s clock' . ($rWindow['fence_on'] ? ': with lb_lease_fence on, no new viewer starts here, and past the drain the running sessions stop.' : '; lb_lease_fence is off, so the node keeps serving.') . ' It gets a new one with its next token, which needs MAIN reachable and licensed.'
				: 'The agent refused the last lease MAIN sent and keeps the older one: ' . $rRefused . '.';
			$rOut[] = self::check('Lease', $rValue, $rLeaseOk, $rProblem);
		}

		foreach (is_array($rStatus['lanes'] ?? null) ? $rStatus['lanes'] : [] as $rLane) {
			if (!is_array($rLane)) {
				continue;
			}
			$rName = self::str($rLane['name'] ?? '?');
			$rFiles = (int) ($rLane['files'] ?? 0);
			$rOldest = (int) ($rLane['oldest_ms'] ?? 0);
			$rAge = $rFiles > 0 && $rOldest > 0 ? intdiv(max(0, $rNowMs - $rOldest), 1000) : 0;
			$rCursor = (int) ($rLane['cursor'] ?? -1);
			$rLaneOk = $rAge <= self::OUTBOX_LAG_SEC;
			$rValue = $rFiles === 0 ? 'empty' : $rFiles . ' file(s), ' . self::bytes((int) ($rLane['bytes'] ?? 0)) . ', oldest ' . $rAge . 's';
			if ((int) ($rLane['inflight'] ?? 0) > 0) {
				$rValue .= ', ' . (int) $rLane['inflight'] . ' event(s) in flight';
			}
			$rValue .= $rCursor < 0 ? ' · MAIN\'s cursor not known yet' : ' · MAIN at #' . $rCursor;
			$rOut[] = self::check('Outbox ' . $rName, $rValue, $rLaneOk, 'Events wait ' . $rAge . 's in the ' . $rName . ' spool: MAIN is not taking them (unreachable, refusing the session, or busy — ' . (int) ($rStatus['busy_refusals'] ?? 0) . ' busy refusals since the agent started). ' . ($rName === 'p0' ? 'Stream state reaches MAIN late until it drains.' : 'Logs are dropped oldest first past the lane\'s cap.'));
		}

		// The relay proxy's port (status `relay`; null while it is off or has not tried).
		$rRelay = is_array($rStatus['relay'] ?? null) ? $rStatus['relay'] : null;
		if ($rRelay !== null) {
			$rBound = ($rRelay['bound'] ?? null) === true;
			$rErr = self::str($rRelay['error'] ?? '');
			$rSince = (int) ($rRelay['since_ms'] ?? 0);
			$rOut[] = self::check('Relay proxy', $rBound ? 'holds 127.0.0.1:31290' : 'cannot bind 127.0.0.1:31290' . ($rSince > 0 ? ' for ' . self::span(intdiv(max(0, $rNowMs - $rSince), 1000)) : '') . ' (' . (int) ($rRelay['failures'] ?? 0) . ' attempts)' . ($rErr !== '' ? ': ' . $rErr : ''), $rBound, 'Another process holds 127.0.0.1:31290, so this node\'s data-plane relays and file reads fail until the agent can bind it (it retries, and MAIN is told). Find it with `ss -ltnp \'sport = :31290\'`.');
		}

		// Whether this node's agent refuses a chunk digest that names no request.
		if (ClusterSettings::int('lb_digest_nonce_required', $rSettings['lb_digest_nonce_required'] ?? null) === 1) {
			$rOut[] = self::check('Chunk digests', 'one that names no request is refused (lb_digest_nonce_required)', true);
		}
		return $rOut;
	}

	/**
	 * MAIN's view of a node, from its `cluster_nodes` row.
	 *
	 * @param array<string, mixed> $rNode The row (ClusterAdmin::nodes() adds `health` and the freshest `last_seen_at`).
	 * @param array{count: int, oldest: ?int} $rOutbox The node's commands not yet acked: how many, and the oldest's created_at.
	 * @param array<string, mixed> $rSettings
	 * @return list<array{label: string, value: string, ok: bool, problem: ?string}>
	 */
	public static function node(array $rNode, array $rOutbox, int $rNowMs, array $rSettings): array {
		$rNow = intdiv($rNowMs, 1000);
		$rState = (string) ($rNode['state'] ?? '');
		$rHealth = (string) ($rNode['health'] ?? $rState);
		$rOut = [];
		$rOk = $rState === 'active' && in_array($rHealth, ['ok', 'active'], true);
		$rOut[] = self::check('Cluster node', $rHealth . ($rHealth !== $rState ? ' (' . $rState . ')' : '') . ' · mode ' . (int) ($rNode['mode'] ?? 0) . ' · flows ' . self::flowNames((int) ($rNode['flows'] ?? 0)), $rOk, match (true) {
			$rState === 'revoked' => 'The node is revoked: it holds no identity MAIN accepts. Re-enrol it (`server:enrol`).',
			$rState === 'quarantined' => 'MAIN has quarantined the node: ' . ((string) ($rNode['quarantine_reason'] ?? '') ?: 'no reason recorded') . '.',
			$rState === 'enrolling' => 'The node\'s enrolment has not finished (no `enrol_complete`): its agent never reached MAIN with its first token.',
			default => 'MAIN has not heard this node\'s agent recently (' . $rHealth . '): the agent is stopped, or it cannot reach MAIN\'s cluster URL. Run `server:diagnose` on the node.',
		});

		$rSeen = isset($rNode['last_seen_at']) ? (int) $rNode['last_seen_at'] : 0;
		$rOffline = ClusterSettings::int('cluster_offline_after_sec', $rSettings['cluster_offline_after_sec'] ?? null);
		$rAge = $rSeen > 0 ? intdiv(max(0, $rNowMs - $rSeen), 1000) : null;
		$rReach = $rAge !== null && $rAge <= $rOffline;
		$rOut[] = self::check('Agent heard', $rAge === null ? 'never' : $rAge . 's ago (offline after ' . $rOffline . 's)', $rReach || $rState !== 'active', 'MAIN has not heard the node\'s agent ' . ($rAge === null ? 'at all' : 'for ' . $rAge . 's') . '.');

		$rTokenExp = isset($rNode['token_exp']) ? (int) $rNode['token_exp'] : null;
		$rTokenOk = $rTokenExp !== null && $rTokenExp > $rNow;
		$rOut[] = self::check('Token', $rTokenExp === null ? 'none issued' : 'epoch ' . (int) ($rNode['epoch'] ?? 0) . ' (gen ' . (int) ($rNode['gen'] ?? 0) . '), expires ' . self::until($rTokenExp, $rNow), $rTokenOk || $rState !== 'active', 'The node\'s newest token has expired: its agent has not refreshed it (unreachable, or MAIN refuses it). It re-keys once it reaches MAIN.');

		$rWindow = self::fenceWindow($rTokenExp, $rSettings);
		$rOut[] = self::check('Fence window', $rWindow['lease_until'] === null ? '—' : 'serves without MAIN to ' . self::utc($rWindow['lease_until']) . ', drains to ' . self::utc((int) $rWindow['drain_until']) . ' · lb_lease_fence ' . ($rWindow['fence_on'] ? 'on' : 'off'), true);

		$rOffset = isset($rNode['clock_offset_ms']) ? (int) $rNode['clock_offset_ms'] : null;
		$rOffsetOk = $rOffset === null || abs($rOffset) <= self::SKEW_WARN_MS;
		$rOut[] = self::check('Clock offset', $rOffset === null ? 'not reported' : self::signedMs($rOffset) . ' (node request time − MAIN)', $rOffsetOk, 'The node\'s requests arrive ' . self::signedMs((int) $rOffset) . ' off MAIN\'s clock; MAIN refuses them past ±90 s. Sync NTP on both.');

		$rCount = (int) ($rOutbox['count'] ?? 0);
		$rOldest = isset($rOutbox['oldest']) ? (int) $rOutbox['oldest'] : 0;
		$rLag = $rCount > 0 && $rOldest > 0 ? max(0, $rNow - $rOldest) : 0;
		$rOut[] = self::check('Command queue', $rCount === 0 ? 'empty' : $rCount . ' not acked, oldest ' . $rLag . 's', $rLag <= self::OUTBOX_LAG_SEC, $rCount . ' command(s) for this node are not acked, the oldest for ' . $rLag . 's: the node is not polling (COMMANDS flow off, agent stopped) or cannot run them.');

		$rOut[] = self::check('Event cursors', 'p0 #' . (int) ($rNode['useq_p0'] ?? 0) . ' · p1 #' . (int) ($rNode['useq_p1'] ?? 0), true);

		// The agent's report of its relay proxy's port (heartbeat `relay`, NodeRelay).
		$rRelayDown = isset($rNode['relay_down_since']) ? (int) $rNode['relay_down_since'] : null;
		if ($rRelayDown !== null || !empty($rNode['relay'])) {
			$rRelayErr = self::str($rNode['relay_error'] ?? '');
			$rOut[] = self::check('Relay proxy', $rRelayDown === null ? 'holds 127.0.0.1:31290' : 'cannot bind 127.0.0.1:31290 since ' . self::utc($rRelayDown) . ($rRelayErr !== '' ? ': ' . $rRelayErr : ''), $rRelayDown === null, 'The node\'s agent cannot bind its relay proxy\'s port (127.0.0.1:31290): another process holds it, so the node\'s data-plane relays and file reads fail until it frees. Find it on the node (`ss -ltnp \'sport = :31290\'`).');
		}

		// Chunk digests that name no request, which the agent takes from an owner
		// from before the nonce (heartbeat `digest_n1`, NodeDigestN1) unless the
		// setting refuses them.
		$rRequired = ClusterSettings::int('lb_digest_nonce_required', $rSettings['lb_digest_nonce_required'] ?? null) === 1;
		$rN1 = isset($rNode['digest_n1']) ? json_decode((string) $rNode['digest_n1'], true) : null;
		if ($rRequired || is_array($rN1)) {
			$rOut[] = self::check('Chunk digests', match (true) {
				$rRequired => 'one that names no request is refused (lb_digest_nonce_required)',
				$rN1 === [] => 'every owner read in 24 h names the request',
				default => 'taken without a nonce from server ' . implode(', ', array_map('intval', $rN1)) . ' in 24 h',
			}, true);
		}
		return $rOut;
	}

	/** The names of the flow bits set, or "none". */
	public static function flowNames(int $rFlows): string {
		$rNames = [];
		foreach (self::FLOWS as $rBit => $rName) {
			if (($rFlows & $rBit) === $rBit) {
				$rNames[] = $rName;
			}
		}
		return $rNames === [] ? 'none' : implode(',', $rNames);
	}

	/** @return array{label: string, value: string, ok: bool, problem: ?string} */
	private static function check(string $rLabel, string $rValue, bool $rOk, ?string $rProblem = null): array {
		return ['label' => $rLabel, 'value' => $rValue, 'ok' => $rOk, 'problem' => $rOk ? null : $rProblem];
	}

	private static function str(mixed $rValue): string {
		return is_scalar($rValue) ? preg_replace('/[\x00-\x1f\x7f]/', '', (string) $rValue) ?? '' : '';
	}

	private static function utc(int $rTs): string {
		return gmdate('Y-m-d H:i', $rTs) . ' UTC';
	}

	/** "at <UTC> (in 5m)" or "at <UTC> (3m ago)" on the clock $rNow is read from. */
	private static function until(int $rTs, int $rNow): string {
		$rLeft = $rTs - $rNow;
		return self::utc($rTs) . ' (' . ($rLeft >= 0 ? 'in ' . self::span($rLeft) : self::span(-$rLeft) . ' ago') . ')';
	}

	private static function ago(int $rMs): string {
		return self::span(intdiv(max(0, $rMs), 1000)) . ' ago';
	}

	private static function span(int $rSec): string {
		return match (true) {
			$rSec >= 86400 => intdiv($rSec, 86400) . 'd ' . intdiv($rSec % 86400, 3600) . 'h',
			$rSec >= 3600 => intdiv($rSec, 3600) . 'h ' . intdiv($rSec % 3600, 60) . 'm',
			$rSec >= 60 => intdiv($rSec, 60) . 'm',
			default => $rSec . 's',
		};
	}

	private static function signedMs(int $rMs): string {
		return ($rMs >= 0 ? '+' : '-') . number_format(abs($rMs) / 1000, 1) . 's';
	}

	private static function bytes(int $rBytes): string {
		return $rBytes >= 1048576 ? number_format($rBytes / 1048576, 1) . ' MiB' : ($rBytes >= 1024 ? number_format($rBytes / 1024, 1) . ' KiB' : $rBytes . ' B');
	}
}
