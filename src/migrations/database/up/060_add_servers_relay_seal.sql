-- Whether this server opens a relay's session key (RelaySeal, D11): a child
-- pulls a stream from such a parent only sealed. Reported with the server's
-- inventory each minute (cron:servers) and carried in the signed servers
-- section; 0 until the server reports it.
ALTER TABLE `servers`
      ADD COLUMN IF NOT EXISTS `relay_seal` tinyint(1) NOT NULL DEFAULT '0' AFTER `viewer_key_fp`;
