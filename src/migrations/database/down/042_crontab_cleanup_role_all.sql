-- Reverse 042_crontab_cleanup_role_all.sql.
UPDATE `crontab` SET `role` = 'main' WHERE `filename` = 'cleanup';
