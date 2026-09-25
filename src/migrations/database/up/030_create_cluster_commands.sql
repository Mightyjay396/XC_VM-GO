-- MAIN -> node command queue: FIFO per node by seq, at least once, deduplicated
-- by cmd_id. dedupe_key keeps one desired-state command per key (stream.*);
-- MySQL allows many NULLs under the unique key. `class` is informational: the
-- extension recomputes it from the payload when it signs.
CREATE TABLE IF NOT EXISTS `cluster_commands` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `server_id` int(11) NOT NULL,
  `seq` bigint(20) unsigned NOT NULL,
  `cmd_id` char(32) COLLATE utf8_unicode_ci NOT NULL,
  `type` varchar(32) COLLATE utf8_unicode_ci NOT NULL,
  `action` varchar(32) COLLATE utf8_unicode_ci DEFAULT NULL,
  `class` char(1) COLLATE utf8_unicode_ci NOT NULL DEFAULT 'G',
  `dedupe_key` varchar(128) COLLATE utf8_unicode_ci DEFAULT NULL,
  `payload` mediumblob NOT NULL,
  `sig` binary(64) NOT NULL,
  `state` varchar(16) COLLATE utf8_unicode_ci NOT NULL DEFAULT 'queued',
  `created_at` int(11) NOT NULL,
  `exp` int(11) NOT NULL,
  `delivered_at` int(11) DEFAULT NULL,
  `acked_at` int(11) DEFAULT NULL,
  `result` mediumblob,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cmd_id` (`cmd_id`),
  UNIQUE KEY `server_seq` (`server_id`, `seq`),
  UNIQUE KEY `server_dedupe` (`server_id`, `dedupe_key`),
  KEY `server_state_seq` (`server_id`, `state`, `seq`),
  KEY `exp` (`exp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
