-- Which nodes run each cron: every node ('all'), MAIN only ('main'), or only
-- nodes that still have MAIN's database ('legacy'). The four crons Phase 0
-- gated to MAIN (NodeRole::isMain) are marked 'main'. The `cluster` row is
-- MAIN's cluster maintenance job, disabled until Phase 2 ships `cron:cluster`.
ALTER TABLE `crontab` ADD COLUMN IF NOT EXISTS `role` enum('all','main','legacy') COLLATE utf8_unicode_ci NOT NULL DEFAULT 'all';
UPDATE `crontab` SET `role` = 'main' WHERE `filename` IN ('cleanup', 'tmdb', 'tmdb_popular', 'update');
INSERT INTO `crontab` (`filename`, `time`, `enabled`, `role`) SELECT 'cluster', '* * * * *', 0, 'main' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `crontab` WHERE `filename` = 'cluster');
