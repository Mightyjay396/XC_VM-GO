-- Reverse 060_add_servers_relay_seal.sql.
ALTER TABLE `servers` DROP COLUMN IF EXISTS `relay_seal`;
