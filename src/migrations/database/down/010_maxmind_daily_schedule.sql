-- Reverse 010_maxmind_daily_schedule.sql. Same guard as the up migration
-- (mirrored): only touch the row if it still holds the daily default, to
-- preserve a schedule an operator may have customised since.
UPDATE `crontab` SET `time` = '0 4 * * 2' WHERE `filename` = 'maxmind' AND `time` = '0 4 * * *';
