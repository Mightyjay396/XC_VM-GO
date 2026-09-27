<?php

use XcVm\Core\Events\Contract\StoppableEventInterface;
use XcVm\Core\Events\EventDispatcher;
use XcVm\Core\Events\AbstractEvent;
use PHPUnit\Framework\TestCase;

final class EventDispatcherTest extends TestCase {

    protected function setUp(): void {
        EventDispatcher::clear();
    }

    protected function tearDown(): void {
        EventDispatcher::clear();
    }

    // ── dispatch / listen ──────────────────────────────────────

    public function testDispatchCallsMatchingListener(): void {
        $received = null;

        EventDispatcher::listen(TestPlainEvent::class, function (TestPlainEvent $e) use (&$received) {
            $received = $e->value;
        });

        EventDispatcher::dispatch(new TestPlainEvent('hello'));

        $this->assertSame('hello', $received);
    }

    public function testDispatchReturnsEvent(): void {
        $event = new TestPlainEvent('x');
        $returned = EventDispatcher::dispatch($event);

        $this->assertSame($event, $returned);
    }

    public function testDispatchDoesNotCallUnrelatedListeners(): void {
        $called = false;
        EventDispatcher::listen(TestPlainEvent::class, function () use (&$called) {
            $called = true;
        });

        EventDispatcher::dispatch(new TestOtherEvent());

        $this->assertFalse($called);
    }

    // ── Priority ordering ──────────────────────────────────────

    public function testListenersAreCalledInPriorityDescendingOrder(): void {
        $order = [];

        EventDispatcher::listen(TestPlainEvent::class, function () use (&$order) { $order[] = 'low'; },  10);
        EventDispatcher::listen(TestPlainEvent::class, function () use (&$order) { $order[] = 'high'; }, 50);
        EventDispatcher::listen(TestPlainEvent::class, function () use (&$order) { $order[] = 'mid'; },  30);

        EventDispatcher::dispatch(new TestPlainEvent('x'));

        $this->assertSame(['high', 'mid', 'low'], $order);
    }

    // ── StoppableEventInterface ────────────────────────────────

    public function testStoppableEventStopsPropagation(): void {
        $calls = 0;

        EventDispatcher::listen(TestStoppableEvent::class, function (TestStoppableEvent $e) use (&$calls) {
            $calls++;
            $e->stopPropagation();
        }, 10);

        EventDispatcher::listen(TestStoppableEvent::class, function () use (&$calls) {
            $calls++;
        }, 5);

        EventDispatcher::dispatch(new TestStoppableEvent());

        $this->assertSame(1, $calls);
    }

    // ── hasListeners ───────────────────────────────────────────

    public function testHasListenersReturnsTrueWhenRegistered(): void {
        EventDispatcher::listen(TestPlainEvent::class, fn() => null);

        $this->assertTrue(EventDispatcher::hasListeners(TestPlainEvent::class));
    }

    public function testHasListenersReturnsFalseWhenNone(): void {
        $this->assertFalse(EventDispatcher::hasListeners(TestPlainEvent::class));
    }

    // ── unlisten / clear ───────────────────────────────────────

    public function testUnlistenRemovesAllListenersForEvent(): void {
        $called = false;
        EventDispatcher::listen(TestPlainEvent::class, fn() => $called = true);
        EventDispatcher::unlisten(TestPlainEvent::class);

        EventDispatcher::dispatch(new TestPlainEvent('x'));

        $this->assertFalse($called);
    }

    public function testClearRemovesAllListeners(): void {
        $called = false;
        EventDispatcher::listen(TestPlainEvent::class, fn() => $called = true);
        EventDispatcher::clear();

        EventDispatcher::dispatch(new TestPlainEvent('x'));

        $this->assertFalse($called);
    }

    // ── Multiple listeners on same event ───────────────────────

    public function testMultipleListenersAllCalled(): void {
        $results = [];
        EventDispatcher::listen(TestPlainEvent::class, function () use (&$results) { $results[] = 'a'; });
        EventDispatcher::listen(TestPlainEvent::class, function () use (&$results) { $results[] = 'b'; });

        EventDispatcher::dispatch(new TestPlainEvent('x'));

        $this->assertCount(2, $results);
    }

    // ── subscribe(): #[ListensTo] outside modules ──────────────

    public function testSubscribeRegistersAClassesStaticListenersOnly(): void {
        TestStaticSubscriber::$rSeen = [];
        EventDispatcher::subscribe(TestStaticSubscriber::class);

        EventDispatcher::dispatch(new TestPlainEvent('a'));
        EventDispatcher::dispatch(new TestOtherEvent());

        $this->assertSame(['high:a', 'static:a', 'static:other'], TestStaticSubscriber::$rSeen, 'priority first, both attributes, never the instance method');
        $this->assertFalse(EventDispatcher::hasListeners('No\\Such\\Event'), 'an event class that does not load is skipped');
    }

    public function testSubscribeRegistersAnObjectsListeners(): void {
        $rSubscriber = new TestStaticSubscriber();
        TestStaticSubscriber::$rSeen = [];
        EventDispatcher::subscribe($rSubscriber);

        EventDispatcher::dispatch(new TestPlainEvent('b'));

        $this->assertSame(['high:b', 'static:b', 'instance:b'], TestStaticSubscriber::$rSeen);
    }
}

// ── Test event fixtures ────────────────────────────────────────────

final class TestStaticSubscriber {
    /** @var list<string> */
    public static array $rSeen = [];

    #[\XcVm\Core\Events\ListensTo(TestPlainEvent::class)]
    #[\XcVm\Core\Events\ListensTo(TestOtherEvent::class)]
    public static function onEvent(object $rEvent): void {
        self::$rSeen[] = 'static:' . ($rEvent instanceof TestPlainEvent ? $rEvent->value : 'other');
    }

    #[\XcVm\Core\Events\ListensTo(TestPlainEvent::class, priority: 10)]
    public static function first(TestPlainEvent $rEvent): void {
        self::$rSeen[] = 'high:' . $rEvent->value;
    }

    #[\XcVm\Core\Events\ListensTo('No\\Such\\Event')]
    public static function never(object $rEvent): void {
        self::$rSeen[] = 'never';
    }

    #[\XcVm\Core\Events\ListensTo(TestPlainEvent::class, priority: -1)]
    public function onInstance(TestPlainEvent $rEvent): void {
        self::$rSeen[] = 'instance:' . $rEvent->value;
    }
}

final class TestPlainEvent {
    public function __construct(public readonly string $value = '') {}
}

final class TestOtherEvent {}

final class TestStoppableEvent extends AbstractEvent {}
