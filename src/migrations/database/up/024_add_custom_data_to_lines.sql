-- Migration 024: Add custom_data column to lines table
-- Supports category template customizations and active code subscriber pairing
-- No down migration: once populated this column holds real per-line
-- customization/subscriber-pairing data — dropping it on rollback would
-- destroy that. Un-reversed, older code simply never references it.
ALTER TABLE `lines`
    ADD COLUMN IF NOT EXISTS `custom_data` mediumtext COLLATE utf8_unicode_ci DEFAULT NULL;
