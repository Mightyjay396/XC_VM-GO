<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Auth\PageAuthorization;

/**
 * post.php saves every admin form (63 actions). It used to check the page
 * "post", which no rule names, so a restricted administrator — an admin group
 * with a list of advanced permissions — could save anything, their own group
 * included. checkPostAction() applies the rule of the page each action saves.
 */
final class PageAuthorizationPostActionTest extends TestCase {

	protected function setUp(): void {
		// A restricted administrator: may manage and edit lines, nothing else.
		$GLOBALS['rUserInfo'] = ['id' => 9, 'member_group_id' => 5];
		$GLOBALS['rPermissions'] = ['is_admin' => 1, 'advanced' => ['users', 'edit_user']];
		$GLOBALS['db'] = new stdClass();
	}

	protected function tearDown(): void {
		unset($GLOBALS['rUserInfo'], $GLOBALS['rPermissions'], $GLOBALS['db']);
	}

	public function testRestrictedAdminIsHeldToThePageRules(): void {
		$this->assertTrue(PageAuthorization::checkPostAction('line', true), 'edit a line (edit_user)');
		$this->assertFalse(PageAuthorization::checkPostAction('line', false), 'add a line (add_user)');
		$this->assertFalse(PageAuthorization::checkPostAction('user', true), 'edit a panel user, e.g. their own group');
		$this->assertFalse(PageAuthorization::checkPostAction('settings', false));
		$this->assertFalse(PageAuthorization::checkPostAction('quick_tools', false));
		$this->assertFalse(PageAuthorization::checkPostAction('mass_delete_lines', false), 'mass delete');
		$this->assertFalse(PageAuthorization::checkPostAction('enigma', false), 'add an Enigma2 device');
		$this->assertFalse(PageAuthorization::checkPostAction('import_tmdb_categories', false));
		$this->assertTrue(PageAuthorization::checkPostAction('edit_profile', false), 'own profile');
	}

	public function testFullAdministratorKeepsEverything(): void {
		$GLOBALS['rUserInfo']['member_group_id'] = 1;
		foreach (['line', 'user', 'settings', 'quick_tools', 'mass_delete_lines', 'enigma', 'stream', 'server'] as $rAction) {
			$this->assertTrue(PageAuthorization::checkPostAction($rAction, false), $rAction);
			$this->assertTrue(PageAuthorization::checkPostAction($rAction, true), $rAction . ' (edit)');
		}
	}

	/** A save action with no rule is refused — pages without one stay open. */
	public function testUnruledSaveIsRefusedButUnruledPageIsNot(): void {
		$this->assertFalse(PageAuthorization::checkPostAction('no_such_form', false));
		$this->assertFalse(PageAuthorization::checkPostAction('no_such_form', true));
		$this->assertTrue(PageAuthorization::checkPermissions('no_such_page', false));
	}

	/** Plex / Watch saves need the permission the modules give their own pages. */
	public function testModuleSavesNeedTheirPermission(): void {
		foreach (['settings_plex', 'settings_watch', 'plex_add', 'watch_add'] as $rAction) {
			$this->assertFalse(PageAuthorization::checkPostAction($rAction, false), $rAction);
		}
		$GLOBALS['rPermissions']['advanced'] = ['folder_watch_settings'];
		$this->assertTrue(PageAuthorization::checkPostAction('settings_plex', false));
		$this->assertTrue(PageAuthorization::checkPostAction('settings_watch', false));
		$this->assertFalse(PageAuthorization::checkPostAction('plex_add', false));
		$GLOBALS['rPermissions']['advanced'] = ['folder_watch_add'];
		$this->assertTrue(PageAuthorization::checkPostAction('plex_add', false));
		$this->assertTrue(PageAuthorization::checkPostAction('watch_add', true));
	}

	public function testEnigmaPageFollowsTheMagRules(): void {
		$GLOBALS['rPermissions']['advanced'] = ['edit_e2'];
		$this->assertTrue(PageAuthorization::checkPermissions('enigma', true));
		$this->assertFalse(PageAuthorization::checkPermissions('enigma', false));
	}
}
