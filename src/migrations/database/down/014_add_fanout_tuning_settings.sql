-- Reverse 014_add_fanout_tuning_settings.sql.
ALTER TABLE `settings` DROP COLUMN IF EXISTS `fanout_hls_window`;
ALTER TABLE `settings` DROP COLUMN IF EXISTS `fanout_grace_sec`;
ALTER TABLE `settings` DROP COLUMN IF EXISTS `fanout_write_timeout_sec`;
ALTER TABLE `settings` DROP COLUMN IF EXISTS `fanout_chunk_bytes`;
ALTER TABLE `settings` DROP COLUMN IF EXISTS `fanout_max_gop_bytes`;
ALTER TABLE `settings` DROP COLUMN IF EXISTS `fanout_source_insecure`;
ALTER TABLE `settings` DROP COLUMN IF EXISTS `fanout_default_prebuffer_sec`;
ALTER TABLE `settings` DROP COLUMN IF EXISTS `fanout_idle_buffer_grace_sec`;
ALTER TABLE `settings` DROP COLUMN IF EXISTS `fanout_idle_buffer_ratio`;
