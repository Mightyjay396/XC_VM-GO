<?php

use PHPUnit\Framework\TestCase;

/**
 * The cluster schema exists twice: as the core migrations from 028 on for
 * upgrades (028â045, then later ones that ALTER a cluster table) and in
 * database.sql for fresh installs. Both were loaded into MariaDB 10.11 and
 * compared column by column when written; this test keeps them from drifting
 * where CI has no database.
 */
final class ClusterSchemaTest extends TestCase {
	private const MIGRATIONS = ['028_add_cluster_settings', '029_create_cluster_nodes', '030_create_cluster_commands', '031_create_cluster_enrolment', '032_create_cluster_audit', '033_add_crontab_role', '034_create_cluster_changes', '035_add_cluster_epoch_eph', '036_add_cluster_enrol_request_eph', '037_enable_cluster_cron', '038_add_cluster_endpoint_settings', '039_add_cluster_node_root_ready', '040_add_cluster_db_allowlist', '041_add_cluster_node_features', '042_crontab_cleanup_role_all', '043_crontab_main_roles', '045_add_cluster_node_audit', '047_add_cluster_stream_ver_holders'];

	private function src(string $rPath): string {
		return (string) file_get_contents(dirname(__DIR__, 2) . '/src/' . $rPath);
	}

	/** @return array<string, string> table => column block */
	private function tables(string $rSql): array {
		preg_match_all('/CREATE TABLE IF NOT EXISTS `([a-z_]+)` \((.*?)\n\) ENGINE/s', $rSql, $rM);
		return array_combine($rM[1], array_map('trim', $rM[2]));
	}

	/**
	 * The cluster migrations above, then every later core migration from 028
	 * on: one may ALTER a column into a cluster table (046 adds
	 * `cluster_nodes.main_port`) without being listed.
	 *
	 * @return list<string>
	 */
	private function migrations(): array {
		$rNames = self::MIGRATIONS;
		foreach (glob(dirname(__DIR__, 2) . '/src/migrations/database/up/*.sql') ?: [] as $rFile) {
			if ((int) basename($rFile) >= 28) {
				$rNames[] = basename($rFile, '.sql');
			}
		}
		return array_values(array_unique($rNames));
	}

	public function testEveryMigrationHasADownFile(): void {
		foreach (self::MIGRATIONS as $rName) {
			$this->assertFileExists(dirname(__DIR__, 2) . '/src/migrations/database/up/' . $rName . '.sql');
			$this->assertFileExists(dirname(__DIR__, 2) . '/src/migrations/database/down/' . $rName . '.sql');
		}
	}

	public function testClusterTablesMatchDatabaseSql(): void {
		$rInstall = $this->tables($this->src('bin/install/database.sql'));
		// Columns a later migration ALTERs into a table: present in database.sql,
		// absent from the migration that created the table.
		$rAdded = [];
		// And keys (047 adds `cluster_stream_ver.stream_id`).
		$rKeys = [];
		foreach ($this->migrations() as $rName) {
			$rUp = $this->src('migrations/database/up/' . $rName . '.sql');
			if (preg_match_all('/ALTER TABLE `([a-z_]+)` ADD COLUMN IF NOT EXISTS `([a-z_]+)`/', $rUp, $rM, PREG_SET_ORDER)) {
				foreach ($rM as [, $rTable, $rColumn]) {
					$rAdded[$rTable][] = $rColumn;
				}
			}
			if (preg_match_all('/ALTER TABLE `([a-z_]+)` ADD KEY IF NOT EXISTS `([a-z_]+)` (\([^)]*\))/', $rUp, $rM, PREG_SET_ORDER)) {
				foreach ($rM as [, $rTable, $rKey, $rColumns]) {
					$rKeys[$rTable][$rKey] = $rColumns;
				}
			}
		}
		foreach ($rAdded as $rTable => $rColumns) {
			if (!isset($rInstall[$rTable])) {
				continue;
			}
			foreach ($rColumns as $rColumn) {
				$this->assertStringContainsString('`' . $rColumn . '`', $rInstall[$rTable], $rTable . '.' . $rColumn);
				$rInstall[$rTable] = (string) preg_replace('/\n\s*`' . $rColumn . '` [^\n]*/', '', $rInstall[$rTable]);
			}
		}
		foreach ($rKeys as $rTable => $rAddedKeys) {
			foreach ($rAddedKeys as $rKey => $rColumns) {
				$this->assertStringContainsString('KEY `' . $rKey . '` ' . $rColumns, $rInstall[$rTable] ?? '', $rTable . ' key ' . $rKey);
				$rInstall[$rTable] = rtrim((string) preg_replace('/\n\s*KEY `' . $rKey . '` [^\n]*/', '', $rInstall[$rTable]), ',');
			}
		}
		$rCount = 0;
		foreach (self::MIGRATIONS as $rName) {
			foreach ($this->tables($this->src('migrations/database/up/' . $rName . '.sql')) as $rTable => $rBody) {
				$this->assertArrayHasKey($rTable, $rInstall, $rTable . ' missing from database.sql');
				$this->assertSame($rBody, $rInstall[$rTable], $rTable);
				$rCount++;
			}
		}
		$this->assertSame(11, $rCount);
	}

	public function testStreamVersionsAreFoundByStreamAndSeededForEveryHolder(): void {
		$rUp = $this->src('migrations/database/up/047_add_cluster_stream_ver_holders.sql');
		$this->assertStringContainsString('ALTER TABLE `cluster_stream_ver` ADD KEY IF NOT EXISTS `stream_id` (`stream_id`);', $rUp);
		// Every holder of a stream today (Core\Cluster\StreamVersions::holders), at version 0.
		foreach (['`server_id`, `stream_id`, 0, UNIX_TIMESTAMP() FROM `streams_servers`', '`tv_archive_server_id`, `id`, 0, UNIX_TIMESTAMP() FROM `streams`', '`vframes_server_id`, `id`, 0, UNIX_TIMESTAMP() FROM `streams`', '`source_id`, `stream_id`, 0, UNIX_TIMESTAMP() FROM `recordings`'] as $rSeed) {
			$this->assertStringContainsString('INSERT IGNORE INTO `cluster_stream_ver` (`server_id`, `stream_id`, `ver`, `updated_at`) SELECT ' . $rSeed, $rUp);
		}
		$this->assertStringContainsString("VALUES ('stream_ver', '1', UNIX_TIMESTAMP());", $rUp, 'the counter starts at StreamVersions::START');
		$this->assertSame(1, \XcVm\Core\Cluster\StreamVersions::START);
		$rDown = $this->src('migrations/database/down/047_add_cluster_stream_ver_holders.sql');
		$this->assertStringContainsString('DROP KEY IF EXISTS `stream_id`', $rDown);
		$this->assertStringContainsString("('stream_ver', 'stream_ver_floor')", $rDown);
	}

	public function testSettingsColumnsMatchDatabaseSql(): void {
		preg_match_all('/ADD COLUMN IF NOT EXISTS (`[a-z_]+` [^\n]*?),?\n/', $this->src('migrations/database/up/028_add_cluster_settings.sql') . "\n", $rM);
		$this->assertCount(19, $rM[1]);
		$rSettings = $this->tables($this->src('bin/install/database.sql'))['settings'];
		foreach ($rM[1] as $rColumn) {
			$this->assertStringContainsString('  ' . rtrim($rColumn, ';') . ',', $rSettings);
		}
	}

	public function testCrontabRoles(): void {
		$rSql = $this->src('bin/install/database.sql');
		$this->assertStringContainsString("`role` enum('all','main','legacy')", $rSql);
		// 033, then 043: the crons the plan's cron table gives MAIN alone.
		$rMain = ['tmdb', 'tmdb_popular', 'update', 'epg', 'series', 'backups', 'cache_engine', 'providers', 'stats', 'proxy', 'watch', 'plex'];
		foreach ($rMain as $rCron) {
			$this->assertMatchesRegularExpression("/\\(\\d+, '" . $rCron . "', '[^']*', 1, 'main'\\)/", $rSql, $rCron);
		}
		$rUp = $this->src('migrations/database/up/043_crontab_main_roles.sql');
		foreach (array_slice($rMain, 3) as $rCron) {
			$this->assertStringContainsString("'" . $rCron . "'", $rUp, $rCron);
		}
		// maxmind keeps each node's own GeoIP databases current.
		$this->assertMatchesRegularExpression("/\\(\\d+, 'maxmind', '[^']*', 1, 'all'\\)/", $rSql);
		// cleanup prunes each node's own files; only its table rotation is MAIN's (042).
		$this->assertMatchesRegularExpression("/\\(\\d+, 'cleanup', '[^']*', 1, 'all'\\)/", $rSql);
		$this->assertStringContainsString("SET `role` = 'all' WHERE `filename` = 'cleanup'", $this->src('migrations/database/up/042_crontab_cleanup_role_all.sql'));
		$this->assertStringContainsString("(30, 'cluster', '* * * * *', 1, 'main')", $rSql, 'cron:cluster runs on MAIN');
		$this->assertFileExists(dirname(__DIR__, 2) . '/src/Cli/CronJobs/ClusterCronJob.php');
	}
}
