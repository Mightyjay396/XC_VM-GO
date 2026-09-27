-- Reverse 045_add_cluster_node_audit.sql.
ALTER TABLE `cluster_nodes` DROP COLUMN IF EXISTS `audit`;
