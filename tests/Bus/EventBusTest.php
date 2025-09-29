<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Bus;

use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Bus\EventBus;
use SomeWork\CqrsBundle\Contract\Event;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class EventBusTest extends TestCase
{
    public function test_dispatch_uses_sync_bus_by_default(): void
    {
        $event = $this->createStub(Event::class);
        $envelope = new Envelope($event);

        $syncBus = $this->createMock(MessageBusInterface::class);
        $syncBus->expects(self::once())
            ->method('dispatch')
            ->with($event, [])
            ->willReturn($envelope);

        $bus = new EventBus($syncBus);

        self::assertSame($envelope, $bus->dispatch($event));
    }

    public function test_dispatch_to_async_bus_when_configured(): void
    {
        $event = $this->createStub(Event::class);
        $envelope = new Envelope($event);

        $syncBus = $this->createMock(MessageBusInterface::class);
        $syncBus->expects(self::never())->method('dispatch');

        $asyncBus = $this->createMock(MessageBusInterface::class);
        $asyncBus->expects(self::once())
            ->method('dispatch')
            ->with($event, [])
            ->willReturn($envelope);

        $bus = new EventBus($syncBus, $asyncBus);

        self::assertSame($envelope, $bus->dispatch($event, DispatchMode::ASYNC));
    }

    public function test_async_dispatch_without_bus_throws_exception(): void
    {
        $event = $this->createStub(Event::class);

        $syncBus = $this->createMock(MessageBusInterface::class);

        $bus = new EventBus($syncBus);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Asynchronous event bus is not configured.');

        $bus->dispatch($event, DispatchMode::ASYNC);
    }
}
