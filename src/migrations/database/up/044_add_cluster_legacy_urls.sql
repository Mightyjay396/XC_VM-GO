-- MAIN endpoint changes: MAIN's old cluster API URLs (an old server_ip or
-- private_ip, an old HTTPS port) that the nodes' transport policy keeps
-- listing, after the current ones, for 7 days after a change (JSON url =>
-- unix expiry).
ALTER TABLE `settings` ADD COLUMN IF NOT EXISTS `cluster_legacy_urls` mediumtext COLLATE utf8_unicode_ci;
