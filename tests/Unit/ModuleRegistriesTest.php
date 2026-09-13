<?php

use XcVm\Core\Module\PermissionRegistry;
use XcVm\Core\Module\QuickToolsRegistry;
use XcVm\Core\Module\TableRegistry;
use XcVm\Core\Module\TopbarRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Module extension registries — the in-memory surfaces modules register into
 * during boot (serverSide tables, topbar buttons, quick-tools actions, reseller
 * sub-permissions). They hold process-global static state, so each test resets
 * them; these lock down the register/lookup/override/order contracts the core
 * controllers and views depend on.
 */
final class ModuleRegistriesTest extends TestCase {

	protected function setUp(): void {
		TableRegistry::reset();
		TopbarRegistry::reset();
		QuickToolsRegistry::reset();
		PermissionRegistry::reset();
	}

	protected function tearDown(): void {
		TableRegistry::reset();
		TopbarRegistry::reset();
		QuickToolsRegistry::reset();
		PermissionRegistry::reset();
	}

	// ── TableRegistry ────────────────────────────────────────────────

	public function testTableRegistryRegistersAndLooksUpAHandler(): void {
		$this->assertFalse(TableRegistry::has('watch_output'));
		$this->assertNull(TableRegistry::get('watch_output'));

		$handler = static fn(array $r, int $s, int $l, bool $api): array => $r;
		TableRegistry::register('watch_output', $handler);

		$this->assertTrue(TableRegistry::has('watch_output'));
		$this->assertSame($handler, TableRegistry::get('watch_output'));
	}

	public function testTableRegistryIsLastWinsPerId(): void {
		$first = static fn(): array => ['first'];
		$second = static fn(): array => ['second'];
		TableRegistry::register('dup', $first);
		TableRegistry::register('dup', $second);

		$this->assertSame($second, TableRegistry::get('dup'));
	}

	public function testTableRegistryResetClearsHandlers(): void {
		TableRegistry::register('x', static fn(): array => []);
		TableRegistry::reset();
		$this->assertFalse(TableRegistry::has('x'));
	}

	// ── TopbarRegistry ───────────────────────────────────────────────

	public function testTopbarEntriesAreReturnedOrderedByOrder(): void {
		TopbarRegistry::add('watch', 'Later', '/z', null, null, 200);
		TopbarRegistry::add('watch', 'Sooner', '/a', 'adv', 'id="s"', 50);

		$page = TopbarRegistry::forPage('watch');

		$this->assertSame(['Sooner', 'Later'], array_keys($page), 'ascending by order');
		$this->assertSame(['/a', 'adv', 'id="s"'], $page['Sooner'], 'spec = [url, permission, attr]');
		$this->assertSame(['/z', null, null], $page['Later']);
		$this->assertSame(['watch'], TopbarRegistry::pages());
	}

	public function testTopbarAddIsLastWinsPerLabelOnAPage(): void {
		TopbarRegistry::add('movies', 'Export', '/old');
		TopbarRegistry::add('movies', 'Export', '/new', null, null, 10);

		$page = TopbarRegistry::forPage('movies');
		$this->assertCount(1, $page);
		$this->assertSame(['/new', null, null], $page['Export']);
	}

	public function testTopbarForUnknownPageIsEmpty(): void {
		$this->assertSame([], TopbarRegistry::forPage('nope'));
		$this->assertSame([], TopbarRegistry::pages());
	}

	public function testTopbarExportPageAndLogTypeFlags(): void {
		$this->assertFalse(TopbarRegistry::isExportPage('reports'));
		$this->assertNull(TopbarRegistry::logType('reports'));

		TopbarRegistry::markExportPage('reports');
		TopbarRegistry::setLogType('reports', 'plex_logs');

		$this->assertTrue(TopbarRegistry::isExportPage('reports'));
		$this->assertSame('plex_logs', TopbarRegistry::logType('reports'));
		$this->assertFalse(TopbarRegistry::isExportPage('other'));
	}

	// ── QuickToolsRegistry ───────────────────────────────────────────

	public function testQuickToolsGroupsCollectRowsAndHandlers(): void {
		$clear = static function (): void {};
		$sync = static function (): void {};
		QuickToolsRegistry::add('cache', 'clear_cache', 'Clear cache', $clear);
		QuickToolsRegistry::add('cache', 'sync_cache', 'Sync cache', $sync);
		QuickToolsRegistry::add('epg', 'reload_epg', 'Reload EPG', static function (): void {});

		$this->assertSame(['cache', 'epg'], QuickToolsRegistry::groups());
		$this->assertSame(
			[['clear_cache', 'Clear cache'], ['sync_cache', 'Sync cache']],
			QuickToolsRegistry::forGroup('cache')
		);
		$this->assertSame(['clear_cache', 'sync_cache', 'reload_epg'], QuickToolsRegistry::keys());
		$this->assertSame($sync, QuickToolsRegistry::handler('sync_cache'));
		$this->assertNull(QuickToolsRegistry::handler('missing'));
		$this->assertSame([], QuickToolsRegistry::forGroup('missing'));
	}

	// ── PermissionRegistry ───────────────────────────────────────────

	public function testPermissionRegistryIsAnOrderedDedupedSet(): void {
		$this->assertSame([], PermissionRegistry::keys());

		PermissionRegistry::add('plex_manage');
		PermissionRegistry::add('watch_view');
		PermissionRegistry::add('plex_manage');

		$this->assertSame(['plex_manage', 'watch_view'], PermissionRegistry::keys(), 'registration order, deduped');
	}
}
