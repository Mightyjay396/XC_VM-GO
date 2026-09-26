-- What a node's agent says at hello that it does, comma-separated (e.g.
-- hls_reaper: it ends idle HLS viewers itself, so MAIN's reaper leaves them
-- to it while the node is heard from).
ALTER TABLE `cluster_nodes` ADD COLUMN IF NOT EXISTS `features` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL AFTER `root_ready`;
