<?php

namespace XcVm\Core\Cluster;

/**
 * A viewer's divergence: how far below its stream's bitrate the node serves
 * it, in percent (plan, section 7: `conn.divergence`, P1, `lines_divergence`).
 *
 * The node measures each viewer's rate, in KiB/s: the legacy chase-read and
 * the VOD and timeshift loops write it to DIVERGENCE_TMP_PATH, which the
 * users cron reads, and the fanout reports its daemon viewers' rates, which
 * fanout_sync reads. A legacy node compares them with the stream's bitrate
 * and writes `lines_divergence` in MAIN's database itself. A node whose
 * CONNECTIONS flow is on sends the rates instead ({@see EventSpool}):
 *
 * ```text
 * conn.divergence  P1  {rows: [{uuid, rate}, …]}   CHUNK rows per event
 * ```
 *
 * and MAIN, which holds the node's connections and its streams' bitrates,
 * works out the divergence with the same formula (expected(), of()) for the
 * node's own connections only (ConnectionIngest::divergence).
 *
 * In Core: the users cron and fanout_sync ship to LBs.
 */
final class DivergenceSink {
	/** Viewers per event, as LogSink::CHUNK rows. */
	public const CHUNK = 1000;

	/** What a viewer's uuid may be: the store's key, and `lines_divergence.uuid` is 32 wide. */
	public const UUID = '/^[A-Za-z0-9_-]{1,32}$/';

	/**
	 * Send this node's viewers' rates to MAIN when its CONNECTIONS flow is on.
	 * Rates that are not an int ≥ 0, and uuids that do not match UUID, are
	 * left out. False when nothing was sent: the caller writes MAIN's tables
	 * the legacy way.
	 *
	 * @param array<string, int> $rRates uuid => KiB/s
	 */
	public static function spool(array $rRates): bool {
		if (!NodeFlows::on(NodeFlows::CONNECTIONS)) {
			return false;
		}
		$rRows = [];
		foreach ($rRates as $rUUID => $rRate) {
			if (is_int($rRate) && $rRate >= 0 && preg_match(self::UUID, (string) $rUUID)) {
				$rRows[] = ['uuid' => (string) $rUUID, 'rate' => $rRate];
			}
		}
		if ($rRows === []) {
			return true; // nothing a store could hold
		}
		$rEvents = [];
		foreach (array_chunk($rRows, self::CHUNK) as $rChunk) {
			$rEvents[] = ['type' => 'conn.divergence', 'd' => ['rows' => $rChunk]];
		}
		return EventSpool::append('p1', $rEvents);
	}

	/** The rate (KiB/s) a stream of this bitrate (kbps) should reach, with 8 % headroom. */
	public static function expected(int $rBitrate): int {
		return intval($rBitrate / 8 * 0.92);
	}

	/**
	 * The divergence: how many percent below $rExpected the viewer's rate is
	 * (0 when it is faster, or when no bitrate is known).
	 */
	public static function of(int $rRate, int $rExpected): int {
		if ($rExpected <= 0) {
			return 0;
		}
		return abs(min(0, intval(($rRate - $rExpected) / $rExpected * 100)));
	}
}
