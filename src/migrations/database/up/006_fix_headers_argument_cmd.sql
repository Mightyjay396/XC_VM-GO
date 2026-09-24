-- Fix streams_arguments: remove erroneous bash $'...' ANSI-C quoting from headers argument_cmd.
-- The stored value contained a literal $ and CRLF characters which broke FFmpeg command assembly.
-- No down migration: reversing this would mean re-introducing the corrupted
-- value it fixes.
UPDATE `streams_arguments`
SET `argument_cmd` = '-headers \'%s\''
WHERE `argument_key` = 'headers'
  AND `argument_cmd` LIKE '-headers $%';
