-- Master switch for the xc_fanout live-delivery daemon. 1 (the default) keeps
-- today's behaviour. 0 stops the daemon on every node, and live streams go back
-- to the pre-fanout delivery paths: on-disk HLS served by PHP, the TS
-- chase-read, and ProxyCommand for proxy streams. See FanoutMode.
ALTER TABLE `settings`
      ADD COLUMN IF NOT EXISTS `fanout_enabled` tinyint(1) DEFAULT '1' AFTER `fanout_debug`;
