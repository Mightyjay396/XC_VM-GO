<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Events\EventDispatcher;
use XcVm\Core\Events\Stream\StreamSavedEvent;
use XcVm\Core\Module\SourceDriverRegistry;
use XcVm\Core\Module\StreamFormRegistry;
use XcVm\Domain\Stream\StreamService;

/**
 * Module tabs on the stream form: who sees them, which posted fields a module
 * gets back, a module refusing a save, and StreamSavedEvent after one.
 * No admin session is set up here, so Authorization::check() says no.
 */
final class StreamFormRegistryTest extends TestCase {
	protected function setUp(): void {
		StreamFormRegistry::reset();
		SourceDriverRegistry::reset();
		SourceDriverRegistry::register(new TestSourceDriver(['acmedash']));
	}

	protected function tearDown(): void {
		StreamFormRegistry::reset();
		SourceDriverRegistry::reset();
		EventDispatcher::unlisten(StreamSavedEvent::class);
		unset($_FILES['m3u_file']);
	}

	private static function call(string $rMethod, ...$rArgs) {
		$m = new ReflectionMethod(StreamService::class, $rMethod);
		$m->setAccessible(true);
		return $m->invoke(null, ...$rArgs);
	}

	private static function tab(string $rID, ?string $rPermission = null, ?callable $rValidate = null): void {
		StreamFormRegistry::add($rID, 'Tab ' . $rID, static fn(?array $s, string $m): string => $m . ':' . ($s['id'] ?? 'new'), $rPermission, $rValidate);
	}

	public function testTabsNeedTheirPermission(): void {
		self::tab('acme');
		self::tab('secret', 'manage_acme');
		$this->assertSame(['acme'], array_keys(StreamFormRegistry::tabs()));
		$this->assertSame('edit:7', (StreamFormRegistry::tabs()['acme']['render'])(['id' => 7], 'edit'));
	}

	public function testInvalidIdIsRejected(): void {
		$this->expectException(InvalidArgumentException::class);
		self::tab('bad id"');
	}

	/** Fields of a tab the admin can't see, and non-array junk, never reach a module. */
	public function testOnlyVisibleTabsFieldsAreTrusted(): void {
		self::tab('acme');
		self::tab('secret', 'manage_acme');
		$rPosted = StreamFormRegistry::posted(['module' => ['acme' => ['q' => 'hd'], 'secret' => ['x' => 1], 'ghost' => ['y' => 2]], 'stream_display_name' => 'X']);
		$this->assertSame(['acme' => ['q' => 'hd']], $rPosted);
		$this->assertSame([], StreamFormRegistry::posted(['module' => 'junk']));
		$this->assertSame([], StreamFormRegistry::posted([]));
	}

	public function testFirstRefusalWins(): void {
		self::tab('ok', null, static fn(array $f, ?array $s): ?string => null);
		self::tab('picky', null, static fn(array $f, ?array $s): ?string => ($f['q'] ?? '') === '' ? 'Pick a quality' : null);
		self::tab('plain');
		$this->assertSame('Pick a quality', StreamFormRegistry::validate([], null));
		$this->assertNull(StreamFormRegistry::validate(['picky' => ['q' => 'hd']], null));
	}

	public function testValidatorSeesTheStreamWhenEditing(): void {
		$rSeen = 'unset';
		self::tab('acme', null, static function (array $f, ?array $s) use (&$rSeen): ?string {
			$rSeen = $s;
			return null;
		});
		self::call('saveRefusal', [['stream_source' => ['http://a/b.ts']]], ['id' => 5], ['edit' => 5], ['acme' => []]);
		$this->assertSame(['id' => 5], $rSeen);
		self::call('saveRefusal', [['stream_source' => ['http://a/b.ts']]], [], [], ['acme' => []]);
		$this->assertNull($rSeen);
	}

	/** A core rule answers with a language key, a module with its own text. */
	public function testSaveRefusalShapes(): void {
		self::tab('acme', null, static fn(): ?string => 'Provider is disabled');
		$this->assertSame(['error' => 'source_driver_no_direct'], self::call('saveRefusal', [['stream_source' => ['acmedash://p/c']]], ['direct_source' => 1], [], ['acme' => []]));
		$this->assertSame(['message' => 'Provider is disabled'], self::call('saveRefusal', [['stream_source' => ['http://a/b.ts']]], [], [], ['acme' => []]));
		StreamFormRegistry::reset();
		$this->assertNull(self::call('saveRefusal', [['stream_source' => ['http://a/b.ts']]], [], [], []));
	}

	public function testSavedEventCarriesTheSave(): void {
		$rEvents = [];
		EventDispatcher::listen(StreamSavedEvent::class, static function (StreamSavedEvent $e) use (&$rEvents): void {
			$rEvents[] = $e;
		});
		self::call('dispatchSaved', [3, 4], [], false, ['acme' => ['q' => 'hd']]);
		self::call('dispatchSaved', [3], ['edit' => 3], true, []);
		$_FILES['m3u_file'] = ['name' => 'x.m3u'];
		self::call('dispatchSaved', [9], [], false, []);

		$this->assertSame([[3, 4], true, 'form', ['acme' => ['q' => 'hd']]], [$rEvents[0]->streamIds, $rEvents[0]->isNew, $rEvents[0]->source, $rEvents[0]->moduleFields]);
		$this->assertSame([false, 'review'], [$rEvents[1]->isNew, $rEvents[1]->source]);
		$this->assertSame('import', $rEvents[2]->source);
	}

	/** The rows are written already: a broken listener must not turn the save into an error. */
	public function testFailingListenerDoesNotFailTheSave(): void {
		EventDispatcher::listen(StreamSavedEvent::class, static function (): void {
			throw new RuntimeException('module bug');
		});
		$rLog = ini_set('error_log', '/dev/null');
		self::call('dispatchSaved', [3], [], false, []);
		ini_set('error_log', (string) $rLog);
		$this->addToAssertionCount(1);
	}
}
