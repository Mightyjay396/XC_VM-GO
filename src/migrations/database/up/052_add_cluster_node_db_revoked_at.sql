-- When MAIN revoked a node's database grant after the node gave up MAIN's
-- credentials (MAIN <-> LB API plan, section 10, step 3; Phase 9): the node
-- ran `node.root strip_db_credentials` (or a credential-free `install_config`)
-- and MAIN called XC_VM::db_revoke for its address. NULL: never revoked.
ALTER TABLE `cluster_nodes` ADD COLUMN IF NOT EXISTS `db_revoked_at` int(11) DEFAULT NULL AFTER `quarantine_reason`;
