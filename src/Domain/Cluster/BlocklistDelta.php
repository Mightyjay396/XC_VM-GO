<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\BlocklistChanges;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * What a node applies to bring its blocklist from change `since` to now (plan,
 * section 9): read from the log BlocklistChanges writes, with each changed
 * key's current row, or its removal.
 *
 * ```text
 * {last, more, full: false, reload: [kind…], set: {kind: [row…]}, del: {kind: [key…]}}
 * {last, more: false, full: true}   since is 0, older than the log, or past it
 * ```
 *
 * `full` asks the node for a whole reload (snapshot()); `reload` names kinds a
 * bulk change touched, which the node reloads whole. The log keeps seven days
 * and always its newest row, so a quiet week does not force every node into
 * a full reload.
 */
final class BlocklistDelta {
	use DatabaseAware;

	/** Days the change log keeps. */
	public const KEEP_DAYS = 7;

	/** Changes read per call; the node asks again while `more` is set. */
	public const MAX_CHANGES = 5000;

	/** Kind => [table, key column, columns a node gets]. */
	private const KINDS = [
		'ip' => ['blocked_ips', 'ip', ['ip']],
		'ua' => ['blocked_uas', 'id', ['id', 'user_agent', 'exact_match']],
		'isp' => ['blocked_isps', 'id', ['id', 'isp', 'blocked']],
		'asn' => ['blocked_asns', 'id', ['id', 'asn']],
		// The RTMP password goes too: the node checks publishers against it.
		'rtmp' => ['rtmp_ips', 'id', ['id', 'ip', 'password', 'push', 'pull']],
	];

	/**
	 * @return array{last: int, more: bool, full: bool, reload?: list<string>, set?: array<string, list<array<string, mixed>>>, del?: array<string, list<string>>}
	 */
	public static function since(int $rSince, int $rLimit = self::MAX_CHANGES): array {
		$db = self::db();
		$db->query('SELECT MIN(`id`) AS `lo`, MAX(`id`) AS `hi` FROM `cluster_changes` WHERE `section` = ?;', BlocklistChanges::SECTION);
		$rRange = $db->get_row() ?: [];
		$rLo = (int) ($rRange['lo'] ?? 0);
		$rHi = (int) ($rRange['hi'] ?? 0);
		// Nothing to start from, a log pruned past it, or a log that went back.
		if ($rSince <= 0 || $rHi === 0 || $rSince + 1 < $rLo || $rSince > $rHi) {
			return ['last' => $rHi, 'more' => false, 'full' => true];
		}
		$db->query('SELECT `id`, `op`, `kind`, `value` FROM `cluster_changes` WHERE `section` = ? AND `id` > ? ORDER BY `id` LIMIT ' . max(1, $rLimit) . ';', BlocklistChanges::SECTION, $rSince);
		$rRows = $db->get_rows() ?: [];
		$rLast = $rSince;
		$rReload = [];
		$rKeys = [];
		foreach ($rRows as $rRow) {
			$rLast = (int) $rRow['id'];
			$rKind = (string) $rRow['kind'];
			if (!isset(self::KINDS[$rKind])) {
				continue;
			}
			if ($rRow['op'] === 'reset') {
				$rReload[$rKind] = true;
			} else {
				$rKeys[$rKind][(string) $rRow['value']] = true;
			}
		}
		$rOut = ['last' => $rLast, 'more' => count($rRows) >= $rLimit, 'full' => false, 'reload' => array_keys($rReload), 'set' => [], 'del' => []];
		foreach ($rKeys as $rKind => $rSet) {
			if (isset($rReload[$rKind])) {
				continue; // reloaded whole
			}
			$rKeyList = array_map('strval', array_keys($rSet));
			$rCurrent = self::rows($rKind, $rKeyList);
			$rKeyColumn = self::KINDS[$rKind][1];
			$rFound = array_map(static fn(array $rRow): string => (string) $rRow[$rKeyColumn], $rCurrent);
			if ($rCurrent !== []) {
				$rOut['set'][$rKind] = $rCurrent;
			}
			$rGone = array_values(array_diff($rKeyList, $rFound));
			if ($rGone !== []) {
				$rOut['del'][$rKind] = $rGone;
			}
		}
		return $rOut;
	}

	/**
	 * The whole blocklist, or the named kinds, for a full reload.
	 *
	 * @param list<string>|null $rKinds
	 * @return array<string, list<array<string, mixed>>>
	 */
	public static function snapshot(?array $rKinds = null): array {
		$rOut = [];
		foreach ($rKinds ?? array_keys(self::KINDS) as $rKind) {
			if (isset(self::KINDS[$rKind])) {
				$rOut[$rKind] = self::rows($rKind, null);
			}
		}
		return $rOut;
	}

	/** Drop changes past KEEP_DAYS, always keeping the newest. */
	public static function prune(?int $rNow = null): void {
		$db = self::db();
		$db->query('SELECT MAX(`id`) AS `hi` FROM `cluster_changes` WHERE `section` = ?;', BlocklistChanges::SECTION);
		$rHi = (int) ($db->get_row()['hi'] ?? 0);
		if ($rHi > 0) {
			$db->query('DELETE FROM `cluster_changes` WHERE `section` = ? AND `id` < ? AND `time` < ?;', BlocklistChanges::SECTION, $rHi, ($rNow ?? time()) - self::KEEP_DAYS * 86400);
		}
	}

	/**
	 * @param list<string>|null $rKeys null: every row
	 * @return list<array<string, mixed>>
	 */
	private static function rows(string $rKind, ?array $rKeys): array {
		[$rTable, $rKey, $rColumns] = self::KINDS[$rKind];
		$rSql = 'SELECT `' . implode('`, `', $rColumns) . '` FROM `' . $rTable . '`';
		$rArgs = [];
		if ($rKind === 'asn') {
			$rSql .= ' WHERE `blocked` = 1'; // the catalog lists every ASN; only blocked ones matter
		}
		if ($rKeys !== null) {
			if ($rKeys === []) {
				return [];
			}
			$rSql .= ($rKind === 'asn' ? ' AND ' : ' WHERE ') . '`' . $rKey . '` IN (' . implode(', ', array_fill(0, count($rKeys), '?')) . ')';
			$rArgs = $rKeys;
		}
		self::db()->query($rSql . ' ORDER BY `' . $rKey . '`;', ...$rArgs);
		return self::db()->get_rows() ?: [];
	}
}
