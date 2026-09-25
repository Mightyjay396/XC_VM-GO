-- SSH host key (SHA-1, 40 lowercase hex) of an LB/proxy node, recorded by
-- server:install on the first successful install and required to match on every
-- reinstall, unless the admin supplies the node's new fingerprint. NULL = never
-- installed since this column existed (trusted on first use).
ALTER TABLE `servers`
      ADD COLUMN IF NOT EXISTS `ssh_hostkey_sha1` char(40) COLLATE utf8_unicode_ci DEFAULT NULL AFTER `limit_burst`;
