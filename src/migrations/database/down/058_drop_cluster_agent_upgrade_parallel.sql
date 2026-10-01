-- Reverse 058_drop_cluster_agent_upgrade_parallel.sql.
ALTER TABLE `settings` ADD COLUMN IF NOT EXISTS `cluster_agent_upgrade_parallel` int(11) DEFAULT '1';
