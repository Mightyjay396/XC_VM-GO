-- Reverse 009_add_proxy_cron.sql.
DELETE FROM `crontab` WHERE `filename` = 'proxy';
