-- Reverse 007_add_platform_api_key.sql.
ALTER TABLE `settings` DROP COLUMN IF EXISTS `platform_api_key`;
