-- Reverse 050_crontab_users_legacy.sql.
UPDATE `crontab` SET `role` = 'all' WHERE `filename` = 'users' AND `role` = 'legacy';
