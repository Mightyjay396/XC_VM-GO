-- Reverse 037_enable_cluster_cron.sql.
UPDATE `crontab` SET `enabled` = 0 WHERE `filename` = 'cluster';
