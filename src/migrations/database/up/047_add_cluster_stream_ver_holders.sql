-- The node replica's R2 streams section (Core\Cluster\StreamVersions). A
-- change to a stream stamps it anew for every server that holds it now and
-- every server that held it at its last change, found by stream: the key.
-- Every server that holds a stream today (assigned it, recording its TV
-- archive or thumbnails, or with a recording of it scheduled) gets its row
-- at version 0, so taking the stream off it later reaches it as a removal.
-- A node with nothing yet checks every stream anyway. The counter starts at
-- 1: a node that synced before any change holds 1, never 0 (nothing held);
-- never below a version a row already holds, so a new change is always past
-- every cursor that read the old ones.
ALTER TABLE `cluster_stream_ver` ADD KEY IF NOT EXISTS `stream_id` (`stream_id`);
INSERT IGNORE INTO `cluster_stream_ver` (`server_id`, `stream_id`, `ver`, `updated_at`) SELECT `server_id`, `stream_id`, 0, UNIX_TIMESTAMP() FROM `streams_servers` WHERE `server_id` > 0 AND `stream_id` > 0;
INSERT IGNORE INTO `cluster_stream_ver` (`server_id`, `stream_id`, `ver`, `updated_at`) SELECT `tv_archive_server_id`, `id`, 0, UNIX_TIMESTAMP() FROM `streams` WHERE `tv_archive_server_id` > 0;
INSERT IGNORE INTO `cluster_stream_ver` (`server_id`, `stream_id`, `ver`, `updated_at`) SELECT `vframes_server_id`, `id`, 0, UNIX_TIMESTAMP() FROM `streams` WHERE `vframes_server_id` > 0;
INSERT IGNORE INTO `cluster_stream_ver` (`server_id`, `stream_id`, `ver`, `updated_at`) SELECT `source_id`, `stream_id`, 0, UNIX_TIMESTAMP() FROM `recordings` WHERE `source_id` > 0 AND `stream_id` > 0;
INSERT IGNORE INTO `cluster_meta` (`name`, `value`, `updated_at`) SELECT 'stream_ver', GREATEST(1, COALESCE(MAX(`ver`), 0)), UNIX_TIMESTAMP() FROM `cluster_stream_ver`;
