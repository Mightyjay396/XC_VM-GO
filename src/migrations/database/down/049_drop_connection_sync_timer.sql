-- Reverse 049_drop_connection_sync_timer.sql.
ALTER TABLE `settings` ADD COLUMN IF NOT EXISTS `connection_sync_timer` int(11) DEFAULT '1' AFTER `mag_load_all_channels`;
