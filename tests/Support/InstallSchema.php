<?php

namespace XcVm\Tests\Support;

/**
 * Tables of the install schema (src/bin/install/database.sql), reduced to what
 * SQLite accepts, so a test works against every column a real install has.
 */
final class InstallSchema {
	/** The `servers` table's CREATE statement. */
	public static function serversTable(): string {
		$rLines = [];
		foreach (explode("\n", self::body('servers')) as $rLine) {
			$rLine = trim($rLine, " \t,");
			if ($rLine === '' || str_starts_with($rLine, 'PRIMARY KEY') || str_starts_with($rLine, 'KEY ')) {
				continue;
			}
			$rLine = (string) preg_replace('/ COLLATE \w+/', '', $rLine);
			$rLines[] = str_starts_with($rLine, '`id` ') ? '`id` INTEGER PRIMARY KEY' : $rLine;
		}
		return 'CREATE TABLE `servers` (' . implode(', ', $rLines) . ')';
	}

	/**
	 * Every column of `servers`: the install schema's and the migrations'.
	 *
	 * @return list<string>
	 */
	public static function serverColumns(): array {
		preg_match_all('/^\s*`(\w+)` /m', self::body('servers'), $rCols);
		$rOut = $rCols[1];
		foreach (glob(dirname(__DIR__, 2) . '/src/migrations/database/up/*.sql') ?: [] as $rFile) {
			$rSql = (string) file_get_contents($rFile);
			if (str_contains($rSql, 'ALTER TABLE `servers`')) {
				preg_match_all('/ADD COLUMN (?:IF NOT EXISTS )?`(\w+)`/', $rSql, $rAdd);
				$rOut = array_merge($rOut, $rAdd[1]);
			}
		}
		return array_values(array_unique($rOut));
	}

	private static function body(string $rTable): string {
		preg_match('/CREATE TABLE IF NOT EXISTS `' . $rTable . '` \((.*?)\) ENGINE=/s', (string) file_get_contents(dirname(__DIR__, 2) . '/src/bin/install/database.sql'), $rMatch);
		return $rMatch[1] ?? '';
	}
}
