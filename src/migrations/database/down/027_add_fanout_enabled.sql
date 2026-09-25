-- Reverse 027_add_fanout_enabled.sql.
ALTER TABLE `settings` DROP COLUMN IF EXISTS `fanout_enabled`;
