-- The 8.x ffmpeg build is 8.1 now (XC_VM_FFMPEG, fetched per distribution by
-- `console.php ffmpeg`): a node that has not fetched it yet takes its 8.0 for
-- 8.1 (FfmpegPaths, the newest build of the same major).
UPDATE `settings` SET `ffmpeg_cpu` = '8.1' WHERE `ffmpeg_cpu` = '8.0';
UPDATE `settings` SET `ffmpeg_gpu` = '8.1' WHERE `ffmpeg_gpu` = '8.0';
