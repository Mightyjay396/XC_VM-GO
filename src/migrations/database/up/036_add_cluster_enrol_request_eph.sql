-- The per-epoch X25519 key a code-enrolled node's first token is sealed to.
-- It arrives with the node's request, before the admin approves; the token is
-- minted at approval, so the key waits here until then.
ALTER TABLE `cluster_enrol_requests` ADD COLUMN IF NOT EXISTS `agent_eph_pub` binary(32) DEFAULT NULL AFTER `node_box_pub`;
