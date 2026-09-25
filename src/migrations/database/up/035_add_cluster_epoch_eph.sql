-- The agent's per-epoch X25519 key an epoch's token was sealed to. A refresh
-- whose reply was lost is retried with the same key, and MAIN re-sends the
-- unused next epoch instead of minting another, so a node never holds more
-- than two valid epochs.
ALTER TABLE `cluster_node_epochs` ADD COLUMN IF NOT EXISTS `agent_eph_pub` binary(32) DEFAULT NULL AFTER `token_sealed`;
