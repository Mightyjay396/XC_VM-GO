-- Reverse 053_add_cluster_node_install_id.sql.
ALTER TABLE `cluster_nodes` DROP COLUMN IF EXISTS `install_id`;
