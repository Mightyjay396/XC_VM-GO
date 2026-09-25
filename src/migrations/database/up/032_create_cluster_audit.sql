-- Cluster audit log (kept cluster_audit_retention_days), the replay cache for
-- request nonces (180 s), and connection reservations for MySQL-mode admission.
CREATE TABLE IF NOT EXISTS `cluster_audit` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `time` int(11) NOT NULL,
  `server_id` int(11) DEFAULT NULL,
  `actor` varchar(64) COLLATE utf8_unicode_ci DEFAULT NULL,
  `event` varchar(64) COLLATE utf8_unicode_ci NOT NULL,
  `detail` text COLLATE utf8_unicode_ci,
  `ip` varchar(45) COLLATE utf8_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `time` (`time`),
  KEY `server_time` (`server_id`, `time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE IF NOT EXISTS `cluster_nonces` (
  `node` varchar(48) COLLATE utf8_unicode_ci NOT NULL,
  `nonce` binary(16) NOT NULL,
  `exp` int(11) NOT NULL,
  PRIMARY KEY (`node`, `nonce`),
  KEY `exp` (`exp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE IF NOT EXISTS `cluster_reservations` (
  `id` char(32) COLLATE utf8_unicode_ci NOT NULL,
  `identity` varchar(96) COLLATE utf8_unicode_ci NOT NULL,
  `server_id` int(11) NOT NULL,
  `stream_id` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `exp` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `identity` (`identity`),
  KEY `exp` (`exp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
