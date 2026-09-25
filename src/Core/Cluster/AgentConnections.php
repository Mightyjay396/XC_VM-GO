<?php

namespace XcVm\Core\Cluster;

/**
 * This node's connection registry, held by its agent (cluster plan, Phase 6),
 * for a node whose CONNECTIONS flow is on. ConnectionTracker's store seam
 * reads and writes a viewer here instead of in MAIN's Redis or `lines_live`;
 * the agent mirrors every change to MAIN as a P0 event, so MAIN's store stays
 * current for the reaper, the limits and the admin.
 *
 * Every call answers null when the agent did not: the caller then uses MAIN's
 * store for that call, and the viewer is never held up by the agent.
 */
final class AgentConnections {
	/** The stream endpoints' hot path: the agent answers in well under this. */
	public const TIMEOUT = 1.0;

	public static function enabled(): bool {
		return NodeFlows::on(NodeFlows::CONNECTIONS);
	}

	/**
	 * Record (or replace) a connection.
	 *
	 * @param array<string, mixed> $rRecord
	 * @return bool|null true when recorded; null when the agent did not answer or refused.
	 */
	public static function put(string $rUUID, array $rRecord): ?bool {
		$rOut = self::call('PUT', $rUUID, $rRecord);
		return $rOut !== null && $rOut[0] === 200 ? true : null;
	}

	/**
	 * @return array<string, mixed>|false|null the record, false when there is none, null when the agent did not answer
	 */
	public static function get(string $rUUID): array|false|null {
		return self::record(self::call('GET', $rUUID, null));
	}

	/**
	 * The first connection matching every column (a Range request's fallback).
	 *
	 * @param array<string, mixed> $rMatch
	 * @return array<string, mixed>|false|null
	 */
	public static function find(array $rMatch): array|false|null {
		return self::record(AgentClient::request('POST', '/v1/conn/find', ['match' => (object) $rMatch], self::TIMEOUT));
	}

	/** @return array<string, mixed>|false|null a line's oldest open connection */
	public static function oldest(mixed $rLineID): array|false|null {
		return self::record(AgentClient::request('POST', '/v1/conn/oldest', ['user_id' => $rLineID], self::TIMEOUT));
	}

	/** @return array<string, mixed>|false|null the record after refreshing its hls_last_read */
	public static function touch(string $rUUID, int $rLastRead): array|false|null {
		return self::record(self::call('POST', $rUUID . '/touch', ['hls_last_read' => $rLastRead]));
	}

	/** Remove a connection (the agent tells MAIN). */
	public static function delete(string $rUUID): ?bool {
		$rOut = self::call('DELETE', $rUUID, null);
		return $rOut !== null && $rOut[0] === 204 ? true : null;
	}

	/** Follow a close already made in MAIN's store (no event). */
	public static function closed(string $rUUID, bool $rRemove): ?bool {
		$rOut = self::call('POST', $rUUID . '/close', ['remove' => $rRemove]);
		return $rOut !== null && $rOut[0] === 204 ? true : null;
	}

	/**
	 * @param array<string, mixed>|null $rBody
	 * @return array{0: int, 1: array<string, mixed>|null}|null
	 */
	private static function call(string $rMethod, string $rPath, ?array $rBody): ?array {
		if (!preg_match('#^[A-Za-z0-9_-]{1,64}(/(touch|close))?$#', $rPath)) {
			return [400, null];
		}
		return AgentClient::request($rMethod, '/v1/conn/' . $rPath, $rBody, self::TIMEOUT);
	}

	/**
	 * @param array{0: int, 1: array<string, mixed>|null}|null $rOut
	 * @return array<string, mixed>|false|null
	 */
	private static function record(?array $rOut): array|false|null {
		if ($rOut === null) {
			return null;
		}
		if ($rOut[0] === 404) {
			return false;
		}
		return $rOut[0] === 200 && is_array($rOut[1]) ? $rOut[1] : null;
	}
}
