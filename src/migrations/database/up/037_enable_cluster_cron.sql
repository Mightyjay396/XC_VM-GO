-- cron:cluster exists now (expired epochs, the replay cache, enrolment
-- codes). It does nothing on load balancers or while the API is disabled.
UPDATE `crontab` SET `enabled` = 1 WHERE `filename` = 'cluster';
