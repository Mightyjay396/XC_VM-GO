-- Reverse 057_ffmpeg_label_81.sql.
UPDATE `settings` SET `ffmpeg_cpu` = '8.0' WHERE `ffmpeg_cpu` = '8.1';
UPDATE `settings` SET `ffmpeg_gpu` = '8.0' WHERE `ffmpeg_gpu` = '8.1';
