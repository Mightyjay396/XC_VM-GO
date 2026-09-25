-- Reverse 036_add_cluster_enrol_request_eph.sql.
ALTER TABLE `cluster_enrol_requests` DROP COLUMN IF EXISTS `agent_eph_pub`;
