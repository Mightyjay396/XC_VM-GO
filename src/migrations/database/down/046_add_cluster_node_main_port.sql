-- Reverse 046_add_cluster_node_main_port.sql.
ALTER TABLE `cluster_nodes` DROP COLUMN IF EXISTS `main_port`;
