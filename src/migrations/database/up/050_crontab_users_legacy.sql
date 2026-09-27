-- `cron:users` reaps MAIN's own `lines_live` and Redis, so a node in cluster
-- mode 2 (no database of MAIN's) must not run it: its viewers are the agent's
-- registry, which reaps them itself (the hls_reaper feature), and MAIN's copy is
-- reaped by MAIN's own row. `legacy` is exactly that role
-- (ReplicaSections::cronRoles) and no row used it before.
UPDATE `crontab` SET `role` = 'legacy' WHERE `filename` = 'users' AND `role` = 'all';
