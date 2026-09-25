<?php

namespace XcVm\Domain\Cluster;

/**
 * `conn_snapshot`: a CONNECTIONS node's whole registry, sent when MAIN asks
 * (ConnectionDigest), so MAIN's store for that node matches it again.
 *
 * ```text
 * {snap_id, seq, last, records: [record, …]}
 * ```
 *
 * The registry goes in chunks of up to MAX_RECORDS, numbered from 0. MAIN
 * keeps them aside and applies nothing until the last one arrives. Then every
 * record is upserted as a `conn.upsert` would be (ConnectionIngest), and every
 * open connection MAIN holds for the node that the snapshot lacks is removed.
 * A chunk 0 starts over; any other chunk out of order is refused with the
 * number MAIN expects, and the node starts the snapshot again.
 */
final class ConnectionSnapshot {
	/** Records per chunk. */
	public const MAX_RECORDS = 1000;

	/** Chunks per snapshot (50 000 connections). */
	public const MAX_CHUNKS = 50;

	private static ?string $rDir = null;

	/** Tests: another staging directory; null restores the default. */
	public static function useDir(?string $rDir): void {
		self::$rDir = $rDir;
	}

	/**
	 * @param array<string, mixed> $rP
	 * @return array{ok: bool, bad?: bool, expected_seq?: int, done?: bool, applied?: int, removed?: int, dropped?: int}
	 */
	public static function receive(int $rServerID, array $rP): array {
		$rSnap = $rP['snap_id'] ?? null;
		$rSeq = $rP['seq'] ?? null;
		$rRecords = $rP['records'] ?? null;
		if (!is_string($rSnap) || !preg_match('/^[0-9a-f]{16,32}$/', $rSnap) || !is_int($rSeq) || $rSeq < 0 || $rSeq >= self::MAX_CHUNKS || !is_bool($rP['last'] ?? null) || !is_array($rRecords) || !array_is_list($rRecords) || count($rRecords) > self::MAX_RECORDS) {
			return ['ok' => false, 'bad' => true];
		}
		$rDir = self::dir() . $rServerID . '/';
		if (!is_dir($rDir) && !@mkdir($rDir, 0750, true) && !is_dir($rDir)) {
			throw new \RuntimeException('cannot stage the snapshot');
		}
		$rMeta = json_decode((string) @file_get_contents($rDir . 'meta.json'), true);
		if ($rSeq === 0) {
			self::clear($rDir);
			$rMeta = ['snap_id' => $rSnap, 'next' => 0];
		}
		if (!is_array($rMeta) || ($rMeta['snap_id'] ?? null) !== $rSnap || (int) ($rMeta['next'] ?? -1) !== $rSeq) {
			return ['ok' => false, 'expected_seq' => is_array($rMeta) && ($rMeta['snap_id'] ?? null) === $rSnap ? (int) $rMeta['next'] : 0];
		}
		if (file_put_contents($rDir . $rSeq . '.json', json_encode($rRecords)) === false) {
			throw new \RuntimeException('cannot stage the snapshot');
		}
		if (!$rP['last']) {
			file_put_contents($rDir . 'meta.json', json_encode(['snap_id' => $rSnap, 'next' => $rSeq + 1]));
			return ['ok' => true, 'done' => false];
		}
		$rAll = [];
		for ($i = 0; $i <= $rSeq; $i++) {
			$rChunk = json_decode((string) @file_get_contents($rDir . $i . '.json'), true);
			if (!is_array($rChunk)) {
				self::clear($rDir);
				return ['ok' => false, 'expected_seq' => 0];
			}
			array_push($rAll, ...$rChunk);
		}
		self::clear($rDir);
		return ['ok' => true, 'done' => true] + self::apply($rServerID, $rAll);
	}

	/**
	 * Make MAIN's store for the node match its registry.
	 *
	 * @param list<mixed> $rRecords
	 * @return array{applied: int, removed: int, dropped: int}
	 */
	public static function apply(int $rServerID, array $rRecords): array {
		$rApplied = $rDropped = $rRemoved = 0;
		$rSeen = [];
		foreach ($rRecords as $rRecord) {
			if (is_array($rRecord) && ConnectionIngest::upsert($rServerID, $rRecord)) {
				$rApplied++;
				$rSeen[(string) $rRecord['uuid']] = true;
			} else {
				$rDropped++;
			}
		}
		foreach (array_keys(ConnectionDigest::stored($rServerID)) as $rUUID) {
			if (!isset($rSeen[(string) $rUUID]) && ConnectionIngest::remove($rServerID, (string) $rUUID)) {
				$rRemoved++;
			}
		}
		return ['applied' => $rApplied, 'removed' => $rRemoved, 'dropped' => $rDropped];
	}

	private static function clear(string $rDir): void {
		foreach (glob($rDir . '*.json') ?: [] as $rFile) {
			@unlink($rFile);
		}
	}

	private static function dir(): string {
		return self::$rDir ?? ((defined('TMP_PATH') ? TMP_PATH : sys_get_temp_dir() . '/') . 'cluster_snapshots/');
	}
}
