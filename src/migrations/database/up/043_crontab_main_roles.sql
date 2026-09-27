-- The crons only MAIN runs, as the cluster plan's cron table has them: the
-- load balancer build strips the classes of epg, series, backups,
-- cache_engine and providers; stats exits on a load balancer; proxy fetches
-- the proxy archive MAIN's installer ships; watch and plex come from modules,
-- which are MAIN-only. The node replica's crontab section filters by role, so
-- a node no longer gets these jobs. maxmind stays 'all': each node keeps its
-- own GeoIP databases.
UPDATE `crontab` SET `role` = 'main' WHERE `filename` IN ('epg', 'series', 'backups', 'cache_engine', 'providers', 'stats', 'proxy', 'watch', 'plex');
