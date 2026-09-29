-- Whether a node's agent holds its loopback relay proxy's port (MAIN <-> LB
-- API plan, Phase 9): the agent reports it in every heartbeat (`relay`), and
-- MAIN keeps the transitions. relay_down_since: when the agent first failed
-- to bind 127.0.0.1:31290 in a row (MAIN's clock, unix seconds), NULL while it
-- holds it or has not said; relay_error: the agent's last error, printable.
ALTER TABLE `cluster_nodes` ADD COLUMN IF NOT EXISTS `relay_down_since` int(11) DEFAULT NULL AFTER `db_revoked_at`;
ALTER TABLE `cluster_nodes` ADD COLUMN IF NOT EXISTS `relay_error` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL AFTER `relay_down_since`;
