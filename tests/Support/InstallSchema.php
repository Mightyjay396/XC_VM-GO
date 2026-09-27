<?php

namespace XcVm\Tests\Support;

/**
 * Tables of the install schema (src/bin/install/database.sql) and the
 * migrations, reduced to what SQLite accepts, so a test works against every
 * column a real install has.
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
	 * Any table's CREATE statement from the install schema, reduced to what
	 * SQLite accepts: its AUTO_INCREMENT key is the INTEGER PRIMARY KEY, and
	 * the other keys, collations and `ON UPDATE` clauses go.
	 */
	public static function table(string $rTable): string {
		$rLines = [];
		foreach (explode("\n", self::body($rTable)) as $rLine) {
			$rLine = trim($rLine, " \t,");
			if ($rLine === '' || str_starts_with($rLine, 'PRIMARY KEY') || str_starts_with($rLine, 'KEY ') || str_starts_with($rLine, 'UNIQUE KEY')) {
				continue;
			}
			$rLine = (string) preg_replace('/ (CHARACTER SET|COLLATE) \w+| unsigned| ON UPDATE CURRENT_TIMESTAMP/', '', $rLine);
			if (preg_match('/^(`\w+`) \w+(\(\d+\))? NOT NULL AUTO_INCREMENT$/', $rLine, $rM)) {
				$rLine = $rM[1] . ' INTEGER PRIMARY KEY';
			}
			$rLines[] = $rLine;
		}
		return 'CREATE TABLE `' . $rTable . '` (' . implode(', ', $rLines) . ')';
	}

	/**
	 * Every column of a table: the install schema's and the migrations'.
	 *
	 * @return list<string>
	 */
	public static function columns(string $rTable): array {
		preg_match_all('/^\s*`(\w+)` /m', self::body($rTable), $rCols);
		$rOut = $rCols[1];
		foreach (glob(dirname(__DIR__, 2) . '/src/migrations/database/up/*.sql') ?: [] as $rFile) {
			$rSql = (string) file_get_contents($rFile);
			if (str_contains($rSql, 'ALTER TABLE `' . $rTable . '`')) {
				preg_match_all('/ADD COLUMN (?:IF NOT EXISTS )?`(\w+)`/', $rSql, $rAdd);
				$rOut = array_merge($rOut, $rAdd[1]);
			}
		}
		return array_values(array_unique($rOut));
	}

	/**
	 * Every column of `servers`: the install schema's and the migrations'.
	 *
	 * @return list<string>
	 */
	public static function serverColumns(): array {
		return self::columns('servers');
	}

	/** A migration's MariaDB DDL (`up/<name>.sql`), reduced to what SQLite accepts. */
	public static function migration(string $rName): string {
		$rSql = (string) file_get_contents(dirname(__DIR__, 2) . '/src/migrations/database/up/' . $rName . '.sql');
		$rSql = (string) preg_replace('/^--.*$/m', '', $rSql);
		$rSql = (string) preg_replace('/`id` bigint\(20\) unsigned NOT NULL AUTO_INCREMENT/', '`id` INTEGER PRIMARY KEY AUTOINCREMENT', $rSql);
		$rSql = (string) preg_replace('/,\s*PRIMARY KEY \(`id`\)/', '', $rSql);
		$rSql = (string) preg_replace('/,\s*(UNIQUE )?KEY `\w+` \([^)]*\)/', '', $rSql);
		$rSql = (string) preg_replace('/ unsigned| COLLATE \w+/', '', $rSql);
		return (string) preg_replace('/\) ENGINE=[^;]*;/', ');', $rSql);
	}

	private static function body(string $rTable): string {
		preg_match('/CREATE TABLE IF NOT EXISTS `' . $rTable . '` \((.*?)\) ENGINE=/s', (string) file_get_contents(dirname(__DIR__, 2) . '/src/bin/install/database.sql'), $rMatch);
		return $rMatch[1] ?? '';
	}
}
