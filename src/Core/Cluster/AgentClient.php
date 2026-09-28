<?php

namespace XcVm\Core\Cluster;

/**
 * The LB's PHP asking MAIN something through the node's agent (plan, section
 * 7, "Local socket"): `POST /v1/main/{op}` on the agent's unix socket
 * (`config/cluster/agent.sock`, xc_vm only). The agent makes the
 * authenticated call to MAIN and returns its opened reply. Only ops the agent
 * allows pass; everything else it refuses.
 *
 * For the few calls that need MAIN's answer before the node can go on (a
 * recording's VOD id). One-way reports go through EventSpool instead.
 */
final class AgentClient {
	/**
	 * The waits (s) between the tries of mainRetrying(), two minutes in all
	 * (with each try's own timeout, up to about four): longer than MAIN stays
	 * busy while its ingest permits are held.
	 */
	public const RETRY_WAITS_SEC = [1, 2, 4, 8, 15, 30, 30, 30];

	private static ?string $rSocket = null;

	private static ?\Closure $rSleep = null;

	/** Tests: another socket; null restores the default. */
	public static function useSocket(?string $rPath): void {
		self::$rSocket = $rPath;
	}

	/** Tests: fn(int $rSec) instead of sleep(); null restores it. */
	public static function useSleep(?\Closure $rSleep): void {
		self::$rSleep = $rSleep;
	}

	public static function socket(): string {
		return self::$rSocket ?? AgentPaths::file(AgentPaths::SOCKET);
	}

	/**
	 * Call an op on MAIN through the agent.
	 *
	 * @param array<string, mixed> $rPayload
	 * @return array<string, mixed>|null MAIN's reply; null when the agent or MAIN did not answer, or refused.
	 */
	public static function main(string $rOp, array $rPayload, float $rTimeout = 15.0): ?array {
		if (!preg_match('/^[a-z_]{1,32}\z/', $rOp)) {
			return null;
		}
		$rOut = self::request('POST', '/v1/main/' . $rOp, $rPayload, $rTimeout);
		return $rOut !== null && $rOut[0] === 200 && is_array($rOut[1]) ? $rOut[1] : null;
	}

	/**
	 * main() for an op MAIN applies once (recording_complete: a retry gets the
	 * same VOD, even one that overlaps a try MAIN is still running after the
	 * agent gave up on it), asked again while it gets no answer, after each
	 * of RETRY_WAITS_SEC. MAIN refuses such an op while its ingest permits are
	 * held (503 RATE_LIMITED), and today's agent hands any refusal back as a
	 * bare 409, so a busy MAIN cannot be told from any other failure here.
	 *
	 * @param array<string, mixed> $rPayload
	 * @return array<string, mixed>|null MAIN's reply; null when no try got one.
	 */
	public static function mainRetrying(string $rOp, array $rPayload): ?array {
		foreach ([0, ...self::RETRY_WAITS_SEC] as $rWait) {
			if ($rWait > 0 && self::$rSleep !== null) {
				(self::$rSleep)($rWait);
			} elseif ($rWait > 0) {
				sleep($rWait);
			}
			$rReply = self::main($rOp, $rPayload);
			if ($rReply !== null) {
				return $rReply;
			}
		}
		return null;
	}

	/**
	 * One HTTP request to the agent's socket.
	 *
	 * @param array<string, mixed>|null $rBody JSON body, or none.
	 * @param array<string, string> $rHeaders Extra request headers; CR and LF are dropped from the values.
	 * @return array{0: int, 1: array<string, mixed>|null}|null [status, decoded body]; null when the agent did not answer.
	 */
	public static function request(string $rMethod, string $rPath, ?array $rBody, float $rTimeout, array $rHeaders = []): ?array {
		$rSock = @stream_socket_client('unix://' . self::socket(), $rErrNo, $rErr, min(2.0, $rTimeout));
		if ($rSock === false) {
			return null;
		}
		stream_set_timeout($rSock, (int) floor($rTimeout), (int) (($rTimeout - floor($rTimeout)) * 1000000));
		$rData = $rBody === null ? '' : (string) json_encode((object) $rBody, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
		$rExtra = '';
		foreach ($rHeaders as $rName => $rValue) {
			$rExtra .= $rName . ': ' . str_replace(["\r", "\n"], '', $rValue) . "\r\n";
		}
		fwrite($rSock, $rMethod . ' ' . $rPath . " HTTP/1.0\r\nHost: agent\r\nContent-Type: application/json\r\n" . $rExtra . 'Content-Length: ' . strlen($rData) . "\r\nConnection: close\r\n\r\n" . $rData);
		$rRaw = (string) stream_get_contents($rSock, 1048576);
		$rTimedOut = stream_get_meta_data($rSock)['timed_out'];
		fclose($rSock);
		if ($rTimedOut || !preg_match('#^HTTP/1\.[01] (\d{3}) #', $rRaw, $rM)) {
			return null;
		}
		[, $rReply] = array_pad(explode("\r\n\r\n", $rRaw, 2), 2, '');
		$rOut = json_decode($rReply, true);
		return [(int) $rM[1], is_array($rOut) ? $rOut : null];
	}
}
