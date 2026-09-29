-- Reverse 054_add_cluster_node_relay_down.sql.
ALTER TABLE `cluster_nodes` DROP COLUMN IF EXISTS `relay_error`;
ALTER TABLE `cluster_nodes` DROP COLUMN IF EXISTS `relay_down_since`;
