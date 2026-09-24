-- Reverse 012_add_auto_unban.sql.
ALTER TABLE `settings` DROP COLUMN IF EXISTS `auto_unban_ip`;
ALTER TABLE `settings` DROP COLUMN IF EXISTS `ban_duration_value`;
ALTER TABLE `settings` DROP COLUMN IF EXISTS `ban_duration_unit`;
