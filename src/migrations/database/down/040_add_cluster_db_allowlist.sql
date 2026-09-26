-- Reverse 040_add_cluster_db_allowlist.sql.
ALTER TABLE `settings` DROP COLUMN IF EXISTS `cluster_db_allowlist_extra`;
ALTER TABLE `settings` DROP COLUMN IF EXISTS `cluster_db_allowlist`;
