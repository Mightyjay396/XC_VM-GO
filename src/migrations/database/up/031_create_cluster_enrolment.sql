-- Break-glass enrolment by code. A code row holds K_req/K_res sealed with
-- cluster_seal_local under a row MAC; SHA-256(secret) is only the lookup key.
-- Codes are single use, live 30 minutes and allow 5 wrong-SAS attempts.
CREATE TABLE IF NOT EXISTS `cluster_enrol_codes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `server_id` int(11) NOT NULL,
  `lookup` binary(32) NOT NULL,
  `keys_sealed` varbinary(1024) NOT NULL,
  `row_mac` binary(32) NOT NULL,
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `created_by` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `exp` int(11) NOT NULL,
  `used_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `lookup` (`lookup`),
  KEY `server_id` (`server_id`),
  KEY `exp` (`exp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- A node's pending request, approved by the admin typing its SAS. One per
-- server: a second request or a key change alerts and blocks (no bulk approve).
CREATE TABLE IF NOT EXISTS `cluster_enrol_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `server_id` int(11) NOT NULL,
  `code_id` int(11) DEFAULT NULL,
  `node_uuid` char(36) COLLATE utf8_unicode_ci NOT NULL,
  `node_sign_pub` binary(32) NOT NULL,
  `node_box_pub` binary(32) NOT NULL,
  `attest` varbinary(64) DEFAULT NULL,
  `state` varchar(20) COLLATE utf8_unicode_ci NOT NULL DEFAULT 'pending_approval',
  `created_at` int(11) NOT NULL,
  `decided_at` int(11) DEFAULT NULL,
  `decided_by` int(11) DEFAULT NULL,
  `reply` mediumblob,
  PRIMARY KEY (`id`),
  UNIQUE KEY `server_id` (`server_id`),
  KEY `state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
