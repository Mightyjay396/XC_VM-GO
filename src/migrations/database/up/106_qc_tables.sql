CREATE TABLE IF NOT EXISTS `source_proxies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `type` varchar(10) NOT NULL DEFAULT 'http',
  `host` varchar(255) NOT NULL,
  `port` int(11) NOT NULL,
  `username` varchar(255) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `notes` varchar(255) DEFAULT NULL,
  `last_check` int(11) DEFAULT NULL,
  `last_ok` tinyint(1) DEFAULT NULL,
  `last_ip` varchar(64) DEFAULT NULL,
  `last_country` varchar(64) DEFAULT NULL,
  `last_ms` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `refund_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `reseller_id` int(11) NOT NULL,
  `actor_id` int(11) NOT NULL DEFAULT 0,
  `line_id` int(11) NOT NULL,
  `line_username` varchar(255) DEFAULT NULL,
  `line_type` varchar(16) NOT NULL DEFAULT 'line',
  `credits_paid` double NOT NULL DEFAULT 0,
  `months_total` int(11) NOT NULL DEFAULT 0,
  `months_used` int(11) NOT NULL DEFAULT 0,
  `credits_refunded` double NOT NULL DEFAULT 0,
  `exp_date_at_delete` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `deleted_line_info` longtext DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `reseller_id` (`reseller_id`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `streams_arguments` (`argument_cat`, `argument_name`, `argument_description`, `argument_wprotocol`, `argument_key`, `argument_cmd`, `argument_type`, `argument_default_value`)
SELECT 'fetch', 'Proxy (liste)', 'Proxy choisi dans la liste Serveurs > Proxys sources (référence)', 'http', 'proxy_id', '', 'text', NULL
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `streams_arguments` WHERE `argument_key` = 'proxy_id');
