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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
