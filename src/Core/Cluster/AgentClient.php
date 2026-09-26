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
	private static ?string $rSocket = null;

	/** Tests: another socket; null restores the default. */
	public static function useSocket(?string $rPath): void {
		self::$rSocket = $rPath;
	}

	public static function socket(): string {
		return self::$rSocket ?? ((defined('CONFIG_PATH') ? CONFIG_PATH : '/home/xc_vm/config/') . 'cluster/agent.sock');
	}

	/**
	 * Call an op on MAIN through the agent.
	 *
	 * @param array<string, mixed> $rPayload
	 * @return array<string, mixed>|null MAIN's reply; null when the agent or MAIN did not answer, or refused.
	 */
	public static function main(string $rOp, array $rPayload, float $rTimeout = 15.0): ?array {
		if (!preg_match('/^[a-z_]{1,32}$/', $rOp)) {
			return null;
		}
		$rOut = self::request('POST', '/v1/main/' . $rOp, $rPayload, $rTimeout);
		return $rOut !== null && $rOut[0] === 200 && is_array($rOut[1]) ? $rOut[1] : null;
	}

	/**
	 * One HTTP request to the agent's socket.
	 *
	 * @param array<string, mixed>|null $rBody JSON body, or none.
	 * @return array{0: int, 1: array<string, mixed>|null}|null [status, decoded body]; null when the agent did not answer.
	 */
	public static function request(string $rMethod, string $rPath, ?array $rBody, float $rTimeout): ?array {
		$rSock = @stream_socket_client('unix://' . self::socket(), $rErrNo, $rErr, min(2.0, $rTimeout));
		if ($rSock === false) {
			return null;
		}
		stream_set_timeout($rSock, (int) floor($rTimeout), (int) (($rTimeout - floor($rTimeout)) * 1000000));
		$rData = $rBody === null ? '' : (string) json_encode((object) $rBody, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
		fwrite($rSock, $rMethod . ' ' . $rPath . " HTTP/1.0\r\nHost: agent\r\nContent-Type: application/json\r\nContent-Length: " . strlen($rData) . "\r\nConnection: close\r\n\r\n" . $rData);
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
