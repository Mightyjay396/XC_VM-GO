-- Reverse 055_add_digest_n1.sql.
ALTER TABLE `settings` DROP COLUMN IF EXISTS `lb_digest_nonce_required`;
ALTER TABLE `cluster_nodes` DROP COLUMN IF EXISTS `digest_n1`;
