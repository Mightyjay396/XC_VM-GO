<?php

/**
 * Provision the administrator the E2E suite logs in as — or re-key it.
 *
 * Runs ON the panel host, with the panel's own PHP, and creates the account the
 * way the first-run setup page does (Views/admin/setup.php): the `users` row
 * defaults from the schema, the panel's password hash, the Administrators group.
 * The password is read from stdin so it never appears in a process list or a
 * shell history:
 *
 *   /home/xc_vm/bin/php/bin/php create-admin.php e2e_admin < password.txt
 *
 * Running it again for an existing username resets that account's password and
 * puts it back in the Administrators group, enabled. XC_VM_HOME overrides the
 * install path (default /home/xc_vm).
 */

use XcVm\Core\Auth\Authenticator;
use XcVm\Core\Database\QueryHelper;

if (PHP_SAPI !== 'cli') {
	exit(1);
}

$rUsername = (string) ($argv[1] ?? '');
$rPassword = rtrim((string) stream_get_contents(STDIN), "\r\n");
// The setup page's own floor for the first administrator.
if (strlen($rUsername) < 8 || strlen($rPassword) < 8) {
	fwrite(STDERR, "usage: php create-admin.php <username, 8+ chars>  (password, 8+ chars, on stdin)\n");
	exit(2);
}

$rHome = rtrim(getenv('XC_VM_HOME') ?: '/home/xc_vm', '/');
require_once $rHome . '/bootstrap.php';
XC_Bootstrap::boot(XC_Bootstrap::CONTEXT_CLI, ['process' => 'XC_VM[E2E admin]']);

global $db;
// group_id 1 is the seeded Administrators group (bin/install/database.sql).
$rAdminGroup = 1;
$rHash = Authenticator::hashPassword($rPassword);

// fetchOne() answers an empty array, not false, when nothing matches.
$rExisting = $db->fetchOne('SELECT `id` FROM `users` WHERE `username` = ?', $rUsername);
if (!empty($rExisting['id'])) {
	$db->query('UPDATE `users` SET `password` = ?, `member_group_id` = ?, `status` = 1 WHERE `id` = ?', $rHash, $rAdminGroup, $rExisting['id']);
	echo 'Updated administrator ' . $rUsername . ' (id ' . $rExisting['id'] . ")\n";
	exit(0);
}

$rArray = QueryHelper::verifyPostTable('users');
$rArray['username'] = $rUsername;
$rArray['password'] = $rHash;
$rArray['email'] = '';
$rArray['date_registered'] = time();
$rArray['last_login'] = null;
$rArray['member_group_id'] = $rAdminGroup;
$rArray['ip'] = '';
$rArray['notes'] = 'E2E test administrator (tests/e2e/tools/create-admin.php)';
$rPrepare = QueryHelper::prepareArray($rArray);
if (!$db->query('INSERT INTO `users`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');', ...$rPrepare['data'])) {
	fwrite(STDERR, "Insert failed.\n");
	exit(1);
}
echo 'Created administrator ' . $rUsername . ' (id ' . $db->last_insert_id() . ")\n";
