-- MAIN no longer rolls binaries out to its nodes: every node takes xc_agent,
-- xc_fanout and xcvm_core from their GitHub releases itself (ADR 0004,
-- "Binaries from GitHub on every node"). The rollout's width setting has no
-- reader left.
ALTER TABLE `settings` DROP COLUMN IF EXISTS `cluster_agent_upgrade_parallel`;
