-- Reverse 008_add_module_updates_cron.sql.
DELETE FROM `crontab` WHERE `filename` = 'module_updates';
