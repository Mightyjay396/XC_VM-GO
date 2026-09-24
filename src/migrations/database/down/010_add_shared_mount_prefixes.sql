-- Reverse 010_add_shared_mount_prefixes.sql.
ALTER TABLE `settings` DROP COLUMN IF EXISTS `shared_mount_prefixes`;
