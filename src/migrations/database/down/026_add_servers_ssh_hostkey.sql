-- Reverse 026_add_servers_ssh_hostkey.sql.
ALTER TABLE `servers` DROP COLUMN IF EXISTS `ssh_hostkey_sha1`;
