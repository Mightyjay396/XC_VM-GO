-- Reverse 038_add_cluster_endpoint_settings.sql.
ALTER TABLE `settings` DROP COLUMN IF EXISTS `cluster_legacy_ports`;
ALTER TABLE `settings` DROP COLUMN IF EXISTS `cluster_policy_ver`;
