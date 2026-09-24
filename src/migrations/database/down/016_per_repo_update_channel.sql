-- Reverse 016_per_repo_update_channel.sql: restore the single `update_channel`
-- column an older panel (pre-016) reads, backfilled from `update_channel_main`
-- (the three per-repo columns collapse back to one — this is the exact
-- reverse of the up migration's own split).
ALTER TABLE `settings` ADD COLUMN IF NOT EXISTS `update_channel` varchar(12) COLLATE utf8_unicode_ci DEFAULT 'stable' AFTER `live_streaming_pass`;
UPDATE `settings` SET `update_channel` = `update_channel_main`;
ALTER TABLE `settings` DROP COLUMN IF EXISTS `update_channel_main`;
ALTER TABLE `settings` DROP COLUMN IF EXISTS `update_channel_bin`;
ALTER TABLE `settings` DROP COLUMN IF EXISTS `update_channel_fanout`;
