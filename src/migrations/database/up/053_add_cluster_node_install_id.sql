-- The node's xcvm_core install_id (MAIN <-> LB API plan, Phase 9): what MAIN
-- packs a node's core.pin (cluster_pack) and a node config (config_pack) for.
-- Recorded when the SSH install reads it, or when the node's root reports it
-- (node.root pin_core). NULL: not known yet. Not a secret (it appears in SSH
-- logs); a wrong one only makes the node refuse what MAIN packs for it.
ALTER TABLE `cluster_nodes` ADD COLUMN IF NOT EXISTS `install_id` varchar(64) COLLATE utf8_unicode_ci DEFAULT NULL AFTER `arch`;
