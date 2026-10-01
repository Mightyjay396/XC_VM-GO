-- Reverse 061_add_servers_proxy_key.sql.
ALTER TABLE `servers` DROP COLUMN IF EXISTS `proxy_signed`, DROP COLUMN IF EXISTS `proxy_key_gen`;
