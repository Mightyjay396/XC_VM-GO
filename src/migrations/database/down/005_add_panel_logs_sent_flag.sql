-- Reverse 005_add_panel_logs_sent_flag.sql.
ALTER TABLE `panel_logs` DROP COLUMN IF EXISTS `sent`;
