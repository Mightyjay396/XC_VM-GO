-- Reverse 052_add_cluster_node_db_revoked_at.sql.
ALTER TABLE `cluster_nodes` DROP COLUMN IF EXISTS `db_revoked_at`;
