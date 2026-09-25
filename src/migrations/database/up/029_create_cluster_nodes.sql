-- Enrolled cluster nodes, their token epochs, and cluster-wide state.
-- cluster_nodes.row_mac binds a row to the extension's sealed nodes.state, so a
-- restored or edited row does not re-activate a revoked node.
CREATE TABLE IF NOT EXISTS `cluster_nodes` (
  `server_id` int(11) NOT NULL,
  `node_uuid` char(36) COLLATE utf8_unicode_ci NOT NULL,
  `state` varchar(16) COLLATE utf8_unicode_ci NOT NULL DEFAULT 'enrolling',
  `mode` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `flows` int(10) unsigned NOT NULL DEFAULT '0',
  `gen` int(10) unsigned NOT NULL DEFAULT '1',
  `node_sign_pub` binary(32) DEFAULT NULL,
  `node_box_pub` binary(32) DEFAULT NULL,
  `attest` varbinary(64) DEFAULT NULL,
  `instance_id` varchar(64) COLLATE utf8_unicode_ci DEFAULT NULL,
  `boot_id` varchar(64) COLLATE utf8_unicode_ci DEFAULT NULL,
  `agent_version` varchar(32) COLLATE utf8_unicode_ci DEFAULT NULL,
  `proto` smallint(5) unsigned NOT NULL DEFAULT '0',
  `epoch` int(10) unsigned NOT NULL DEFAULT '0',
  `token_exp` int(11) DEFAULT NULL,
  `enrol_deadline` int(11) DEFAULT NULL,
  `last_seen_at` bigint(20) DEFAULT NULL,
  `clock_offset_ms` int(11) DEFAULT NULL,
  `useq_p0` bigint(20) unsigned NOT NULL DEFAULT '0',
  `useq_p1` bigint(20) unsigned NOT NULL DEFAULT '0',
  `cmd_seq` bigint(20) unsigned NOT NULL DEFAULT '0',
  `policy_ver` int(10) unsigned NOT NULL DEFAULT '0',
  `quarantine_reason` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `row_mac` binary(32) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`server_id`),
  UNIQUE KEY `node_uuid` (`node_uuid`),
  KEY `state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- One row per issued epoch: the extension's sealed epoch record (z and the MAC
-- inputs, sealed to MAIN's machine with the node uuid as context) and the
-- sealed token, kept so a lost refresh reply can be re-sent. Deleting a row at
-- expiry erases z.
CREATE TABLE IF NOT EXISTS `cluster_node_epochs` (
  `server_id` int(11) NOT NULL,
  `epoch` int(10) unsigned NOT NULL,
  `record` varbinary(2048) NOT NULL,
  `token_sealed` varbinary(4096) DEFAULT NULL,
  `nbf` int(11) NOT NULL,
  `exp` int(11) NOT NULL,
  `refresh_at` int(11) NOT NULL,
  `used` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` int(11) NOT NULL,
  PRIMARY KEY (`server_id`, `epoch`),
  KEY `exp` (`exp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- Cluster-wide key/value state: gate state, resumable jobs (stream-secret
-- rotation), policy version, endpoint-change progress.
CREATE TABLE IF NOT EXISTS `cluster_meta` (
  `name` varchar(64) COLLATE utf8_unicode_ci NOT NULL,
  `value` mediumtext COLLATE utf8_unicode_ci,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
