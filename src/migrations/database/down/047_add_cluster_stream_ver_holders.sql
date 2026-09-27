-- Reverse 047_add_cluster_stream_ver_holders.sql: the rows it seeded (version
-- 0), the counter and the floors, and the key.
DELETE FROM `cluster_stream_ver` WHERE `ver` = 0;
DELETE FROM `cluster_meta` WHERE `name` IN ('stream_ver', 'stream_ver_floor') OR `name` LIKE 'stream\_ver\_floor.%';
ALTER TABLE `cluster_stream_ver` DROP KEY IF EXISTS `stream_id`;
