<?php

use PHPUnit\Framework\TestCase;

/**
 * The cluster schema exists twice: as migrations 028–041 for upgrades and in
 * database.sql for fresh installs. Both were loaded into MariaDB 10.11 and
 * compared column by column when written; this test keeps them from drifting
 * where CI has no database.
 */
final class ClusterSchemaTest extends TestCase {
	private const MIGRATIONS = ['028_add_cluster_settings', '029_create_cluster_nodes', '030_create_cluster_commands', '031_create_cluster_enrolment', '032_create_cluster_audit', '033_add_crontab_role', '034_create_cluster_changes', '035_add_cluster_epoch_eph', '036_add_cluster_enrol_request_eph', '037_enable_cluster_cron', '038_add_cluster_endpoint_settings', '039_add_cluster_node_root_ready', '040_add_cluster_db_allowlist', '041_add_cluster_node_features'];

	private function src(string $rPath): string {
		return (string) file_get_contents(dirname(__DIR__, 2) . '/src/' . $rPath);
	}

	/** @return array<string, string> table => column block */
	private function tables(string $rSql): array {
		preg_match_all('/CREATE TABLE IF NOT EXISTS `([a-z_]+)` \((.*?)\n\) ENGINE/s', $rSql, $rM);
		return array_combine($rM[1], array_map('trim', $rM[2]));
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
		foreach (self::MIGRATIONS as $rName) {
			if (preg_match_all('/ALTER TABLE `([a-z_]+)` ADD COLUMN IF NOT EXISTS `([a-z_]+)`/', $this->src('migrations/database/up/' . $rName . '.sql'), $rM, PREG_SET_ORDER)) {
				foreach ($rM as [, $rTable, $rColumn]) {
					$rAdded[$rTable][] = $rColumn;
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
		foreach (['cleanup', 'tmdb', 'tmdb_popular', 'update'] as $rCron) {
			$this->assertMatchesRegularExpression("/\\(\\d+, '" . $rCron . "', '[^']*', 1, 'main'\\)/", $rSql, $rCron);
		}
		$this->assertStringContainsString("(30, 'cluster', '* * * * *', 1, 'main')", $rSql, 'cron:cluster runs on MAIN');
		$this->assertFileExists(dirname(__DIR__, 2) . '/src/Cli/CronJobs/ClusterCronJob.php');
	}
}
