-- A node's event lanes' lag and the MAIN URLs it cannot reach (MAIN <-> LB API
-- plan, section 11, "Servers list badges"): the agent reports them in every
-- heartbeat (`lanes`, `unreachable`), and MAIN keeps the transitions.
-- p0_lag_since / p1_lag_since: when the lane's oldest event began to wait past
-- ClusterDiagnosis::OUTBOX_LAG_SEC (MAIN's clock, unix seconds), NULL while it
-- does not; unreachable_urls: the MAIN URLs that failed and have not answered
-- since, space-separated, NULL when there are none or the agent does not say.
ALTER TABLE `cluster_nodes` ADD COLUMN IF NOT EXISTS `p0_lag_since` int(11) DEFAULT NULL AFTER `digest_n1`;
ALTER TABLE `cluster_nodes` ADD COLUMN IF NOT EXISTS `p1_lag_since` int(11) DEFAULT NULL AFTER `p0_lag_since`;
ALTER TABLE `cluster_nodes` ADD COLUMN IF NOT EXISTS `unreachable_urls` varchar(1024) COLLATE utf8_unicode_ci DEFAULT NULL AFTER `p1_lag_since`;
