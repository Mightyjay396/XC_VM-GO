-- What a node's heartbeat last reported of its own audit, as MAIN keeps it
-- (JSON, Domain\Cluster\NodeAudit): today {"settings_misses": {key: count}},
-- the settings reads outside the replica's allowlist over the node's last
-- seven days. Shown on the Cluster Nodes page.
ALTER TABLE `cluster_nodes` ADD COLUMN IF NOT EXISTS `audit` text COLLATE utf8_unicode_ci DEFAULT NULL AFTER `features`;
