-- No down migration: the UPDATE below overwrites existing 0/1/NULL values
-- with 5, and the originals aren't recorded anywhere — not safely
-- invertible. Rely on the pre-rollback DB backup if this narrow gap ever
-- matters.
ALTER TABLE `settings`
	MODIFY `queue_loop` int(11) DEFAULT '5';

UPDATE `settings`
SET `queue_loop` = 5
WHERE `queue_loop` IS NULL OR `queue_loop` IN (0, 1);
