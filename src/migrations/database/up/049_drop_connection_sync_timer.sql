-- `settings.connection_sync_timer` was a Redis-tab field with no reader: nothing
-- in the panel ever looked at it (the connection sync it once paced is the
-- fanout reconciler's INTERVAL and the HLS reaper's own timers). The install
-- schema no longer creates it.
ALTER TABLE `settings` DROP COLUMN IF EXISTS `connection_sync_timer`;
