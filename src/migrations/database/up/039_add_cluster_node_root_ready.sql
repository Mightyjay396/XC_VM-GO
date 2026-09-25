-- Whether a node can take root commands: its root-owned panel-key pin
-- (/etc/xc_vm/cluster) matches the key its agent holds. Reported in every
-- heartbeat; until it is, root actions keep the signals table.
ALTER TABLE `cluster_nodes` ADD COLUMN IF NOT EXISTS `root_ready` tinyint(1) NOT NULL DEFAULT '0' AFTER `flows`;
