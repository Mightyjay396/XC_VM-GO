-- The machine architecture a node's agent reports at hello (amd64, arm64,
-- armv7, 386), as the xc_agent asset names it. MAIN pins one agent binary per
-- arch (`console.php agent_binary`), so it needs the node's to offer it the
-- one it should run (Domain\Cluster\AgentUpgrades, cron:cluster).
ALTER TABLE `cluster_nodes` ADD COLUMN IF NOT EXISTS `arch` varchar(8) COLLATE utf8_unicode_ci DEFAULT NULL AFTER `agent_version`;
