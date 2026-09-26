-- Reverse 041_add_cluster_node_features.sql.
ALTER TABLE `cluster_nodes` DROP COLUMN IF EXISTS `features`;
