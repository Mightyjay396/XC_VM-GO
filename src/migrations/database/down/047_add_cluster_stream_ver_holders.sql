-- Reverse 047_add_cluster_stream_ver_holders.sql: every version row (only
-- the code 047 came with writes them), the counter, the floors and the
-- pruning's place, and the key. A later upgrade then seeds the table afresh.
DELETE FROM `cluster_stream_ver`;
DELETE FROM `cluster_meta` WHERE `name` IN ('stream_ver', 'stream_ver_floor', 'stream_ver_prune') OR `name` LIKE 'stream\_ver\_floor.%';
ALTER TABLE `cluster_stream_ver` DROP KEY IF EXISTS `stream_id`;
