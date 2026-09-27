-- MAIN endpoint changes: the MAIN port (nginx's $server_port) a node's last
-- hello or heartbeat reached, written only when it changes. cron:cluster
-- releases an old port before its 7 days once every node has adopted the
-- current policy and none arrives on it (Domain\Cluster\ClusterEndpoint).
ALTER TABLE `cluster_nodes` ADD COLUMN IF NOT EXISTS `main_port` smallint(5) unsigned DEFAULT NULL AFTER `policy_ver`;
