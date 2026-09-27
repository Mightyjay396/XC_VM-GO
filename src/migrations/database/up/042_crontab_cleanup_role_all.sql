-- cron:cleanup runs on every node: it prunes the node's own stream files,
-- TV archive and created channels, and only its table rotation is MAIN's
-- (NodeRole::isMain). Migration 033 marked it 'main' while the role was not
-- read on load balancers; the node replica's crontab section now filters by
-- role, so it is 'all', as the cluster plan's cron table has it.
UPDATE `crontab` SET `role` = 'all' WHERE `filename` = 'cleanup';
