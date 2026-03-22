<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Testing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Testing\FakeEventBus;
use SomeWork\CqrsBundle\Testing\RecordsBusDispatches;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;

#[CoversClass(FakeEventBus::class)]
final class FakeEventBusTest extends TestCase
{
    public function test_implements_records_bus_dispatches(): void
    {
        $bus = new FakeEventBus();

        /* @phpstan-ignore staticMethod.alreadyNarrowedType */
        self::assertInstanceOf(RecordsBusDispatches::class, $bus);
    }

    public function test_dispatch_records_message_mode_and_stamps(): void
    {
        $bus = new FakeEventBus();
        $event = new class implements Event {};
        $stamp = new DelayStamp(1000);

        $result = $bus->dispatch($event, DispatchMode::ASYNC, $stamp);

        /* @phpstan-ignore staticMethod.alreadyNarrowedType */
        self::assertInstanceOf(Envelope::class, $result);
        self::assertSame($event, $result->getMessage());

        $dispatched = $bus->getDispatched();
        self::assertCount(1, $dispatched);
        self::assertSame($event, $dispatched[0]['message']);
        /* @phpstan-ignore offsetAccess.notFound */
        self::assertSame(DispatchMode::ASYNC, $dispatched[0]['mode']);
        self::assertSame([$stamp], $dispatched[0]['stamps']);
    }

    public function test_dispatch_uses_default_mode(): void
    {
        $bus = new FakeEventBus();
        $event = new class implements Event {};

        $bus->dispatch($event);

        $dispatched = $bus->getDispatched();
        /* @phpstan-ignore offsetAccess.notFound */
        self::assertSame(DispatchMode::DEFAULT, $dispatched[0]['mode']);
    }

    public function test_dispatch_sync_records_with_sync_mode(): void
    {
        $bus = new FakeEventBus();
        $event = new class implements Event {};

        $result = $bus->dispatchSync($event);

        /* @phpstan-ignore staticMethod.alreadyNarrowedType */
        self::assertInstanceOf(Envelope::class, $result);

        $dispatched = $bus->getDispatched();
        self::assertCount(1, $dispatched);
        /* @phpstan-ignore offsetAccess.notFound */
        self::assertSame(DispatchMode::SYNC, $dispatched[0]['mode']);
    }

    public function test_dispatch_async_records_with_async_mode(): void
    {
        $bus = new FakeEventBus();
        $event = new class implements Event {};
        $stamp = new DelayStamp(5000);

        $result = $bus->dispatchAsync($event, $stamp);

        /* @phpstan-ignore staticMethod.alreadyNarrowedType */
        self::assertInstanceOf(Envelope::class, $result);

        $dispatched = $bus->getDispatched();
        self::assertCount(1, $dispatched);
        /* @phpstan-ignore offsetAccess.notFound */
        self::assertSame(DispatchMode::ASYNC, $dispatched[0]['mode']);
        self::assertSame([$stamp], $dispatched[0]['stamps']);
    }

    public function test_records_multiple_dispatches(): void
    {
        $bus = new FakeEventBus();
        $event1 = new class implements Event {};
        $event2 = new class implements Event {};

        $bus->dispatch($event1);
        $bus->dispatchSync($event2);
        $bus->dispatchAsync($event1);

        self::assertCount(3, $bus->getDispatched());
    }

    public function test_get_dispatched_returns_empty_array_initially(): void
    {
        $bus = new FakeEventBus();

        self::assertSame([], $bus->getDispatched());
    }

    public function test_reset_clears_dispatched(): void
    {
        $bus = new FakeEventBus();
        $event = new class implements Event {};

        $bus->dispatch($event);
        $bus->reset();

        self::assertSame([], $bus->getDispatched());
    }

    public function test_dispatches_preserve_chronological_order_across_modes(): void
    {
        $bus = new FakeEventBus();
        $event = new class implements Event {};

        $bus->dispatch($event, DispatchMode::DEFAULT);
        $bus->dispatchSync($event);
        $bus->dispatchAsync($event);

        $dispatched = $bus->getDispatched();
        self::assertCount(3, $dispatched);
        /* @phpstan-ignore offsetAccess.notFound */
        self::assertSame(DispatchMode::DEFAULT, $dispatched[0]['mode']);
        self::assertSame(DispatchMode::SYNC, $dispatched[1]['mode']);
        self::assertSame(DispatchMode::ASYNC, $dispatched[2]['mode']);
    }

    public function test_dispatch_sync_with_multiple_stamps(): void
    {
        $bus = new FakeEventBus();
        $event = new class implements Event {};
        $stamp1 = new DelayStamp(100);
        $stamp2 = new DelayStamp(200);

        $bus->dispatchSync($event, $stamp1, $stamp2);

        $dispatched = $bus->getDispatched();
        /* @phpstan-ignore offsetAccess.notFound */
        self::assertCount(2, $dispatched[0]['stamps']);
        /* @phpstan-ignore offsetAccess.notFound */
        self::assertSame($stamp1, $dispatched[0]['stamps'][0]);
        /* @phpstan-ignore offsetAccess.notFound */
        self::assertSame($stamp2, $dispatched[0]['stamps'][1]);
    }

    public function test_dispatch_without_stamps_records_empty_stamps_array(): void
    {
        $bus = new FakeEventBus();
        $event = new class implements Event {};

        $bus->dispatch($event);

        $dispatched = $bus->getDispatched();
        /* @phpstan-ignore offsetAccess.notFound */
        self::assertSame([], $dispatched[0]['stamps']);
    }

    public function test_dispatch_sync_returns_envelope_wrapping_event(): void
    {
        $bus = new FakeEventBus();
        $event = new class implements Event {};

        $envelope = $bus->dispatchSync($event);

        /* @phpstan-ignore staticMethod.alreadyNarrowedType */
        self::assertInstanceOf(Envelope::class, $envelope);
        self::assertSame($event, $envelope->getMessage());
    }

    public function test_dispatch_async_returns_envelope_wrapping_event(): void
    {
        $bus = new FakeEventBus();
        $event = new class implements Event {};

        $envelope = $bus->dispatchAsync($event);

        /* @phpstan-ignore staticMethod.alreadyNarrowedType */
        self::assertInstanceOf(Envelope::class, $envelope);
        self::assertSame($event, $envelope->getMessage());
    }

    public function test_reset_then_dispatch_starts_fresh(): void
    {
        $bus = new FakeEventBus();
        $event = new class implements Event {};

        $bus->dispatch($event);
        $bus->dispatch($event);
        $bus->reset();
        $bus->dispatch($event);

        self::assertCount(1, $bus->getDispatched());
    }
}
