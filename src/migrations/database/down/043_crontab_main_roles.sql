-- Reverse 043_crontab_main_roles.sql.
UPDATE `crontab` SET `role` = 'all' WHERE `filename` IN ('epg', 'series', 'backups', 'cache_engine', 'providers', 'stats', 'proxy', 'watch', 'plex');
