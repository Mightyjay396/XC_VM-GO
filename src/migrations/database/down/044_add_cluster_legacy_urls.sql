-- Reverse 044_add_cluster_legacy_urls.sql.
ALTER TABLE `settings` DROP COLUMN IF EXISTS `cluster_legacy_urls`;
