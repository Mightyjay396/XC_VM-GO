<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Module\ImportSourceRegistry;

/**
 * Module import kinds on Import & Review: who may use them, and the review
 * rows a kind's channel list turns into. No admin session is set up here, so
 * Authorization::check() says no.
 */
final class ImportSourceRegistryTest extends TestCase {
	protected function setUp(): void {
		ImportSourceRegistry::reset();
	}

	protected function tearDown(): void {
		ImportSourceRegistry::reset();
	}

	private static function kind(string $rKey, callable $rList, ?string $rPermission = null): void {
		ImportSourceRegistry::add($rKey, 'Kind ' . $rKey, static fn(): string => '<input name="import_source[' . $rKey . '][provider]">', $rList, $rPermission);
	}

	public function testKindsNeedTheirPermission(): void {
		self::kind('acme', static fn(): array => []);
		self::kind('secret', static fn(): array => [], 'manage_acme');
		$this->assertSame(['acme'], array_keys(ImportSourceRegistry::kinds()));
	}

	public function testInvalidKeyIsRejected(): void {
		$this->expectException(InvalidArgumentException::class);
		self::kind('Bad Key', static fn(): array => []);
	}

	public function testRowsTakeTheM3uShape(): void {
		$rSeen = null;
		self::kind('acme', static function (array $f) use (&$rSeen): array {
			$rSeen = $f;
			return [
				['url' => 'acmedash://p/one', 'title' => 'One', 'logo' => 'http://l/1.png', 'tvg_id' => 'one.tv', 'category' => 'News'],
				['url' => 'acmedash://p/two'],
				['title' => 'no url'],
			];
		});
		$r = ImportSourceRegistry::rows('acme', ['import_source' => ['acme' => ['provider' => 'p']], 'other' => 1], [], false);

		$this->assertSame(['provider' => 'p'], $rSeen, 'the kind gets only its own fields');
		$this->assertSame(['url' => 'acmedash://p/one', 'title' => 'One', 'logo' => 'http://l/1.png', 'tvg_id' => 'one.tv', 'category' => 'News', 'exists' => false], $r['rows'][0]);
		$this->assertSame(['url' => 'acmedash://p/two', 'title' => 'acmedash://p/two', 'logo' => '', 'tvg_id' => '', 'category' => '', 'exists' => false], $r['rows'][1]);
		$this->assertCount(2, $r['rows'], 'a row without a URL is dropped');
		$this->assertFalse($r['truncated']);
	}

	public function testDuplicatesAreDroppedUnlessAsked(): void {
		self::kind('acme', static fn(): array => [['url' => 'https://s/a.ts'], ['url' => 'acmedash://p/b']]);
		$rExisting = ['http://s/a.ts'];
		$this->assertSame(['acmedash://p/b'], array_column(ImportSourceRegistry::rows('acme', [], $rExisting, false)['rows'], 'url'));
		$r = ImportSourceRegistry::rows('acme', [], $rExisting, true)['rows'];
		$this->assertSame([true, false], array_column($r, 'exists'));
	}

	public function testRowsAreCappedForOnePage(): void {
		self::kind('acme', static fn(): array => array_map(static fn(int $i): array => ['url' => 'acmedash://p/' . $i], range(1, ImportSourceRegistry::MAX_ROWS + 3)));
		$r = ImportSourceRegistry::rows('acme', [], [], false);
		$this->assertCount(ImportSourceRegistry::MAX_ROWS, $r['rows']);
		$this->assertTrue($r['truncated']);
	}

	public function testUnknownHiddenOrFailingKindListsNothing(): void {
		self::kind('secret', static fn(): array => [['url' => 'acmedash://p/x']], 'manage_acme');
		self::kind('broken', static function (): array {
			throw new RuntimeException('provider script timed out');
		});
		$rLog = ini_set('error_log', '/dev/null');
		foreach (['nope', 'secret', 'broken'] as $rKey) {
			$this->assertSame(['rows' => [], 'truncated' => false], ImportSourceRegistry::rows($rKey, [], [], false), $rKey);
		}
		ini_set('error_log', (string) $rLog);
	}
}
