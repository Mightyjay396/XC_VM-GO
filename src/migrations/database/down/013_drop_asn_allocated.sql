-- Reverse 013_drop_asn_allocated.sql: restore the `allocated` column so an
-- older panel (pre-013) that still reads it doesn't error. Historical
-- per-row values aren't recoverable (they were dropped from the seed data in
-- the same commit that removed the column), so this restores shape/default
-- only — the older code never depended on the actual value, only its presence.
ALTER TABLE `blocked_asns` ADD COLUMN IF NOT EXISTS `allocated` timestamp NULL DEFAULT '0000-00-00 00:00:00';
