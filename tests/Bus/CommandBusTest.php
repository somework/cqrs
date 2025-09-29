<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Bus;

use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\CommandBus;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\MessageSerializer;
use SomeWork\CqrsBundle\Contract\RetryPolicy;
use SomeWork\CqrsBundle\Support\NullMessageSerializer;
use SomeWork\CqrsBundle\Support\NullRetryPolicy;
use SomeWork\CqrsBundle\Tests\Fixture\DummyStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\SerializerStamp;

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

    public function test_dispatch_appends_retry_and_serializer_stamps(): void
    {
        $command = $this->createStub(Command::class);
        $retryStamp = new DummyStamp('retry');
        $serializerStamp = new SerializerStamp(['format' => 'json']);

        $policy = $this->createMock(RetryPolicy::class);
        $policy->expects(self::once())
            ->method('getStamps')
            ->with($command, DispatchMode::SYNC)
            ->willReturn([$retryStamp]);

        $serializer = $this->createMock(MessageSerializer::class);
        $serializer->expects(self::once())
            ->method('getStamp')
            ->with($command, DispatchMode::SYNC)
            ->willReturn($serializerStamp);

        $syncBus = $this->createMock(MessageBusInterface::class);
        $syncBus->expects(self::once())
            ->method('dispatch')
            ->with(
                $command,
                self::callback(function (array $stamps) use ($retryStamp, $serializerStamp): bool {
                    self::assertCount(2, $stamps);
                    self::assertSame($retryStamp, $stamps[0]);
                    self::assertSame($serializerStamp, $stamps[1]);

                    return true;
                })
            )
            ->willReturn(new Envelope($command));

        $bus = new CommandBus($syncBus, null, $policy, $serializer);

        $bus->dispatch($command);
    }

    public function test_dispatch_passes_mode_to_retry_policy_and_serializer(): void
    {
        $command = $this->createStub(Command::class);
        $retryStamp = new DummyStamp('retry');
        $serializerStamp = new SerializerStamp(['format' => 'json']);

        $policy = $this->createMock(RetryPolicy::class);
        $policy->expects(self::once())
            ->method('getStamps')
            ->with($command, DispatchMode::ASYNC)
            ->willReturn([$retryStamp]);

        $serializer = $this->createMock(MessageSerializer::class);
        $serializer->expects(self::once())
            ->method('getStamp')
            ->with($command, DispatchMode::ASYNC)
            ->willReturn($serializerStamp);

        $syncBus = $this->createMock(MessageBusInterface::class);
        $syncBus->expects(self::never())->method('dispatch');

        $asyncBus = $this->createMock(MessageBusInterface::class);
        $asyncBus->expects(self::once())
            ->method('dispatch')
            ->with(
                $command,
                self::callback(function (array $stamps) use ($retryStamp, $serializerStamp): bool {
                    self::assertSame([$retryStamp, $serializerStamp], $stamps);

                    return true;
                })
            )
            ->willReturn(new Envelope($command));

        $bus = new CommandBus($syncBus, $asyncBus, $policy, $serializer);

        $bus->dispatch($command, DispatchMode::ASYNC);
    }
}
