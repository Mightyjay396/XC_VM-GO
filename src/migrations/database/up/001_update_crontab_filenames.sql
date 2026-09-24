-- No down migration: which rows originally carried '.php' isn't recorded
-- anywhere, and rows added since never carry it — a blanket re-add would
-- corrupt those. Rely on the pre-rollback DB backup if this narrow gap ever
-- matters.
UPDATE `crontab` SET `filename` = REPLACE(`filename`, '.php', '') WHERE `filename` LIKE '%.php';
