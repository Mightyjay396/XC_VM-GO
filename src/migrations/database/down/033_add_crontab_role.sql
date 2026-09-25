-- Reverse 033_add_crontab_role.sql.
DELETE FROM `crontab` WHERE `filename` = 'cluster';
ALTER TABLE `crontab` DROP COLUMN IF EXISTS `role`;
