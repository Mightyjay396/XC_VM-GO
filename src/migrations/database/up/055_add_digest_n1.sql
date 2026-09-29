-- Chunk digests that name no request (ADR 0004, "Binding a chunk's digest to
-- its request"): only an owner from before the nonce sends one, and a fetcher
-- takes it inside the ±90 s window. digest_n1: the owners (server ids, a JSON
-- list) whose such digest the node's agent took in the last 24 h, as its
-- heartbeat reports them; NULL while the agent does not say.
-- lb_digest_nonce_required: 1 has every agent refuse such a digest.
ALTER TABLE `cluster_nodes` ADD COLUMN IF NOT EXISTS `digest_n1` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL AFTER `relay_error`;
ALTER TABLE `settings`
      ADD COLUMN IF NOT EXISTS `lb_digest_nonce_required` tinyint(1) DEFAULT '0';
