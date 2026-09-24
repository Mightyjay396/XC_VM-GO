-- Reverse 021_add_secure_stream_tokens.sql.
ALTER TABLE `settings` DROP COLUMN IF EXISTS `secure_stream_tokens`;
