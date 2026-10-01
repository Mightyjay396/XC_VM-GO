-- The kid of the viewer-token key a node holds (ViewerKey, H1): the node reports
-- it (node.state) once it applied the key the replica's `secrets` section sent,
-- and MAIN mints that node's viewer tokens with its own key only while the kid
-- matches. NULL = no report: the node gets tokens of the shared secret, as before.
ALTER TABLE `servers`
      ADD COLUMN IF NOT EXISTS `viewer_key_fp` char(16) COLLATE utf8_unicode_ci DEFAULT NULL AFTER `ssh_hostkey_sha1`;
