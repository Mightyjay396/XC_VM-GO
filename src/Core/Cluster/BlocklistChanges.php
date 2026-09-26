<?php

namespace XcVm\Core\Cluster;

use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * The blocklist's change log (plan, section 9, "Change detection"): every path
 * that blocks or unblocks something appends to `cluster_changes` (section
 * `blocklist`, migration 034), so a node can fetch what changed since the
 * last id it applied instead of the whole list. No triggers.
 *
 * ```text
 * kind  table          key
 * ip    blocked_ips    the address
 * ua    blocked_uas    id
 * isp   blocked_isps   id
 * asn   blocked_asns   id
 * rtmp  rtmp_ips       id
 * ```
 *
 * A row says which key changed, not what it became: MAIN serves the key's
 * current row, or its removal (Domain\Cluster\BlocklistDelta), so the order of
 * two changes to one key never matters. A bulk change (a flush, a whole ASN
 * type, the catalog sync, a migration) records `reset` for its kind, and
 * nodes reload that kind whole. Recording never fails the block itself.
 *
 * In Core: LBs still write MAIN's blocklist directly (the flood guard, the
 * Ministra portal, cron:root_signals' auto-unban) until their CONFIG flow is on.
 */
final class BlocklistChanges {
	public const SECTION = 'blocklist';

	public const KINDS = ['ip', 'ua', 'isp', 'asn', 'rtmp'];

	/** Keys recorded one by one; past this a change is recorded as a reset. */
	public const MAX_KEYS = 500;

	/** @param list<string|int> $rKeys rows added or changed */
	public static function set(string $rKind, array $rKeys, ?object $rDb = null): void {
		self::record($rKind, 'set', $rKeys, $rDb);
	}

	/** @param list<string|int> $rKeys rows removed */
	public static function del(string $rKind, array $rKeys, ?object $rDb = null): void {
		self::record($rKind, 'del', $rKeys, $rDb);
	}

	/** Many rows of the kind changed at once: nodes reload it whole. */
	public static function reset(string $rKind, ?object $rDb = null): void {
		self::record($rKind, 'reset', [''], $rDb);
	}

	/** @param list<string|int> $rKeys */
	private static function record(string $rKind, string $rOp, array $rKeys, ?object $rDb): void {
		$rKeys = array_values(array_unique(array_filter(array_map('strval', $rKeys), static fn(string $rKey): bool => $rKey !== '' || $rOp === 'reset')));
		if (!in_array($rKind, self::KINDS, true) || $rKeys === []) {
			return;
		}
		if (count($rKeys) > self::MAX_KEYS) {
			[$rOp, $rKeys] = ['reset', ['']];
		}
		try {
			$rDb ??= DatabaseFactory::get();
			if (!is_object($rDb)) {
				return;
			}
			$rNow = time();
			$rArgs = [];
			foreach ($rKeys as $rKey) {
				$rArgs[] = self::SECTION;
				$rArgs[] = $rOp;
				$rArgs[] = $rKind;
				$rArgs[] = substr($rKey, 0, 255);
				$rArgs[] = $rNow;
			}
			$rDb->query('INSERT INTO `cluster_changes` (`section`, `op`, `kind`, `value`, `time`) VALUES ' . implode(', ', array_fill(0, count($rKeys), '(?, ?, ?, ?, ?)')) . ';', ...$rArgs);
		} catch (\Throwable) {
			// The daily full reload covers a change that could not be logged.
		}
	}
}
