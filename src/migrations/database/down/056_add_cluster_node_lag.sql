-- Reverse 056_add_cluster_node_lag.sql.
ALTER TABLE `cluster_nodes` DROP COLUMN IF EXISTS `unreachable_urls`;
ALTER TABLE `cluster_nodes` DROP COLUMN IF EXISTS `p1_lag_since`;
ALTER TABLE `cluster_nodes` DROP COLUMN IF EXISTS `p0_lag_since`;
