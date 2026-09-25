-- Change detection for the node replica, without triggers. cluster_changes is
-- the blocklist delta log (nodes fetch by id; a gap forces a full reload).
-- cluster_stream_ver is bumped only by MAIN's desired-config writers for a
-- (node, stream), never by the runtime columns nodes keep changing.
CREATE TABLE IF NOT EXISTS `cluster_changes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `section` varchar(32) COLLATE utf8_unicode_ci NOT NULL,
  `op` varchar(8) COLLATE utf8_unicode_ci NOT NULL,
  `kind` varchar(16) COLLATE utf8_unicode_ci NOT NULL,
  `value` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `time` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `section_id` (`section`, `id`),
  KEY `time` (`time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE IF NOT EXISTS `cluster_stream_ver` (
  `server_id` int(11) NOT NULL,
  `stream_id` int(11) NOT NULL,
  `ver` bigint(20) unsigned NOT NULL DEFAULT '1',
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`server_id`, `stream_id`),
  KEY `server_ver` (`server_id`, `ver`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
