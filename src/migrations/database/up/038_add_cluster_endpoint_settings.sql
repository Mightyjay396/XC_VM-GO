-- MAIN endpoint changes: the transport policy version the agents compare in
-- every heartbeat reply, and MAIN's old HTTP ports kept for the cluster API
-- alone for 7 days after a change (JSON port => unix expiry).
ALTER TABLE `settings` ADD COLUMN IF NOT EXISTS `cluster_policy_ver` int(11) DEFAULT '1';
ALTER TABLE `settings` ADD COLUMN IF NOT EXISTS `cluster_legacy_ports` varchar(255) DEFAULT '';
