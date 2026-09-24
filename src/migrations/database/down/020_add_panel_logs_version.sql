-- Reverse 020_add_panel_logs_version.sql.
ALTER TABLE `panel_logs` DROP COLUMN IF EXISTS `version`;
