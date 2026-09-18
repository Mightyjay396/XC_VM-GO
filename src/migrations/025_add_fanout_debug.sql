-- xc_fanout: live debug narration, toggled from the panel and applied on the
-- daemon's next config poll (no restart, no viewer drop). Empty = off (the
-- default; the full narration on a busy node is a lot of log), "all" = every
-- category, or a comma-separated list of the daemon's categories: boot, config,
-- stream, puller, hls, viewer, ingest, ctl, signal, monitor, stats, buffer,
-- reaper, mem. Written to the daemon's config.json as `debug_cats`
-- (XC_VM_Fanout internal/dlog); the panel normalises the value in FanoutConfig.
ALTER TABLE `settings`
      ADD COLUMN IF NOT EXISTS `fanout_debug` varchar(128) DEFAULT '' AFTER `fanout_supervise`;
