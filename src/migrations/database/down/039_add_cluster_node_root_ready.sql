-- Reverse 039_add_cluster_node_root_ready.sql.
ALTER TABLE `cluster_nodes` DROP COLUMN IF EXISTS `root_ready`;
