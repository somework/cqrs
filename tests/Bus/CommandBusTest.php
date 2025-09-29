<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Bus;

use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\CommandBus;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Support\NullMessageSerializer;
use SomeWork\CqrsBundle\Support\NullRetryPolicy;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class CommandBusTest extends TestCase
{
    public function test_dispatch_uses_sync_bus_by_default(): void
    {
        $command = $this->createStub(Command::class);
        $envelope = new Envelope($command);

        $syncBus = $this->createMock(MessageBusInterface::class);
        $syncBus->expects(self::once())
            ->method('dispatch')
            ->with($command, [])
            ->willReturn($envelope);

        $bus = new CommandBus($syncBus, null, new NullRetryPolicy(), new NullMessageSerializer());

        self::assertSame($envelope, $bus->dispatch($command));
    }

    public function test_dispatch_to_async_bus_when_configured(): void
    {
        $command = $this->createStub(Command::class);
        $envelope = new Envelope($command);

        $syncBus = $this->createMock(MessageBusInterface::class);
        $syncBus->expects(self::never())->method('dispatch');

        $asyncBus = $this->createMock(MessageBusInterface::class);
        $asyncBus->expects(self::once())
            ->method('dispatch')
            ->with($command, [])
            ->willReturn($envelope);

        $bus = new CommandBus($syncBus, $asyncBus, new NullRetryPolicy(), new NullMessageSerializer());

        self::assertSame($envelope, $bus->dispatch($command, DispatchMode::ASYNC));
    }

    public function test_async_dispatch_without_bus_throws_exception(): void
    {
        $command = $this->createStub(Command::class);

        $syncBus = $this->createMock(MessageBusInterface::class);

        $bus = new CommandBus($syncBus, null, new NullRetryPolicy(), new NullMessageSerializer());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Asynchronous command bus is not configured.');

        $bus->dispatch($command, DispatchMode::ASYNC);
    }
}
