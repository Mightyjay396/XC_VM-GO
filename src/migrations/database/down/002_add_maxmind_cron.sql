-- Reverse 002_add_maxmind_cron.sql.
ALTER TABLE `settings` DROP COLUMN IF EXISTS `maxmind_account_id`;
ALTER TABLE `settings` DROP COLUMN IF EXISTS `maxmind_license_key`;
ALTER TABLE `settings` DROP COLUMN IF EXISTS `maxmind_editions`;
DELETE FROM `crontab` WHERE `filename` = 'maxmind';
