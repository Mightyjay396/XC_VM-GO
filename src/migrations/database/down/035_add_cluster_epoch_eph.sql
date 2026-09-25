-- Reverse 035_add_cluster_epoch_eph.sql.
ALTER TABLE `cluster_node_epochs` DROP COLUMN IF EXISTS `agent_eph_pub`;
