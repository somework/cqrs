<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Testing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Testing\FakeCommandBus;
use SomeWork\CqrsBundle\Testing\RecordsBusDispatches;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;

#[CoversClass(FakeCommandBus::class)]
final class FakeCommandBusTest extends TestCase
{
    public function test_implements_records_bus_dispatches(): void
    {
        $bus = new FakeCommandBus();

        /* @phpstan-ignore staticMethod.alreadyNarrowedType */
        self::assertInstanceOf(RecordsBusDispatches::class, $bus);
    }

    public function test_dispatch_records_message_mode_and_stamps(): void
    {
        $bus = new FakeCommandBus();
        $command = new class implements Command {};
        $stamp = new DelayStamp(1000);

        $result = $bus->dispatch($command, DispatchMode::ASYNC, $stamp);

        /* @phpstan-ignore staticMethod.alreadyNarrowedType */
        self::assertInstanceOf(Envelope::class, $result);
        self::assertSame($command, $result->getMessage());

        $dispatched = $bus->getDispatched();
        self::assertCount(1, $dispatched);
        self::assertSame($command, $dispatched[0]['message']);
        /* @phpstan-ignore offsetAccess.notFound */
        self::assertSame(DispatchMode::ASYNC, $dispatched[0]['mode']);
        self::assertSame([$stamp], $dispatched[0]['stamps']);
    }

    public function test_dispatch_uses_default_mode(): void
    {
        $bus = new FakeCommandBus();
        $command = new class implements Command {};

        $bus->dispatch($command);

        $dispatched = $bus->getDispatched();
        /* @phpstan-ignore offsetAccess.notFound */
        self::assertSame(DispatchMode::DEFAULT, $dispatched[0]['mode']);
        self::assertSame([], $dispatched[0]['stamps']);
    }

    public function test_dispatch_sync_records_with_sync_mode(): void
    {
        $bus = new FakeCommandBus();
        $command = new class implements Command {};

        $result = $bus->dispatchSync($command);

        self::assertNull($result);

        $dispatched = $bus->getDispatched();
        self::assertCount(1, $dispatched);
        self::assertSame($command, $dispatched[0]['message']);
        /* @phpstan-ignore offsetAccess.notFound */
        self::assertSame(DispatchMode::SYNC, $dispatched[0]['mode']);
    }

    public function test_dispatch_sync_returns_configured_result(): void
    {
        $bus = new FakeCommandBus();
        $command = new class implements Command {};

        $bus->willReturn('some-id');

        $result = $bus->dispatchSync($command);

        self::assertSame('some-id', $result);
    }

    public function test_dispatch_async_records_with_async_mode(): void
    {
        $bus = new FakeCommandBus();
        $command = new class implements Command {};
        $stamp = new DelayStamp(5000);

        $result = $bus->dispatchAsync($command, $stamp);

        /* @phpstan-ignore staticMethod.alreadyNarrowedType */
        self::assertInstanceOf(Envelope::class, $result);
        self::assertSame($command, $result->getMessage());

        $dispatched = $bus->getDispatched();
        self::assertCount(1, $dispatched);
        /* @phpstan-ignore offsetAccess.notFound */
        self::assertSame(DispatchMode::ASYNC, $dispatched[0]['mode']);
        self::assertSame([$stamp], $dispatched[0]['stamps']);
    }

    public function test_get_dispatched_returns_empty_array_initially(): void
    {
        $bus = new FakeCommandBus();

        self::assertSame([], $bus->getDispatched());
    }

    public function test_records_multiple_dispatches(): void
    {
        $bus = new FakeCommandBus();
        $command1 = new class implements Command {};
        $command2 = new class implements Command {};

        $bus->dispatch($command1);
        $bus->dispatchSync($command2);

        self::assertCount(2, $bus->getDispatched());
    }

    public function test_reset_clears_dispatched_and_sync_result(): void
    {
        $bus = new FakeCommandBus();
        $command = new class implements Command {};

        $bus->willReturn('result');
        $bus->dispatch($command);
        $bus->reset();

        self::assertSame([], $bus->getDispatched());
        self::assertNull($bus->dispatchSync($command));
    }

    public function test_dispatches_preserve_chronological_order(): void
    {
        $bus = new FakeCommandBus();
        $command1 = new class implements Command {};
        $command2 = new class implements Command {};
        $command3 = new class implements Command {};

        $bus->dispatch($command1);
        $bus->dispatchAsync($command2);
        $bus->dispatchSync($command3);

        $dispatched = $bus->getDispatched();
        self::assertCount(3, $dispatched);
        self::assertSame($command1, $dispatched[0]['message']);
        /* @phpstan-ignore offsetAccess.notFound */
        self::assertSame(DispatchMode::DEFAULT, $dispatched[0]['mode']);
        self::assertSame($command2, $dispatched[1]['message']);
        self::assertSame(DispatchMode::ASYNC, $dispatched[1]['mode']);
        self::assertSame($command3, $dispatched[2]['message']);
        self::assertSame(DispatchMode::SYNC, $dispatched[2]['mode']);
    }

    public function test_dispatch_with_multiple_stamps(): void
    {
        $bus = new FakeCommandBus();
        $command = new class implements Command {};
        $stamp1 = new DelayStamp(1000);
        $stamp2 = new DelayStamp(2000);

        $bus->dispatch($command, DispatchMode::DEFAULT, $stamp1, $stamp2);

        $dispatched = $bus->getDispatched();
        /* @phpstan-ignore offsetAccess.notFound */
        self::assertCount(2, $dispatched[0]['stamps']);
        /* @phpstan-ignore offsetAccess.notFound */
        self::assertSame($stamp1, $dispatched[0]['stamps'][0]);
        /* @phpstan-ignore offsetAccess.notFound */
        self::assertSame($stamp2, $dispatched[0]['stamps'][1]);
    }

    public function test_dispatch_sync_with_stamps(): void
    {
        $bus = new FakeCommandBus();
        $command = new class implements Command {};
        $stamp = new DelayStamp(500);

        $bus->dispatchSync($command, $stamp);

        $dispatched = $bus->getDispatched();
        /* @phpstan-ignore offsetAccess.notFound */
        self::assertSame([$stamp], $dispatched[0]['stamps']);
    }

    public function test_dispatch_async_returns_envelope_wrapping_command(): void
    {
        $bus = new FakeCommandBus();
        $command = new class implements Command {};

        $envelope = $bus->dispatchAsync($command);

        /* @phpstan-ignore staticMethod.alreadyNarrowedType */
        self::assertInstanceOf(Envelope::class, $envelope);
        self::assertSame($command, $envelope->getMessage());
    }

    public function test_will_return_overwrites_previous_value(): void
    {
        $bus = new FakeCommandBus();
        $command = new class implements Command {};

        $bus->willReturn('first');
        $bus->willReturn('second');

        self::assertSame('second', $bus->dispatchSync($command));
    }

    public function test_dispatch_returns_envelope_wrapping_command(): void
    {
        $bus = new FakeCommandBus();
        $command = new class implements Command {};

        $envelope = $bus->dispatch($command);

        /* @phpstan-ignore staticMethod.alreadyNarrowedType */
        self::assertInstanceOf(Envelope::class, $envelope);
        self::assertSame($command, $envelope->getMessage());
    }
}
