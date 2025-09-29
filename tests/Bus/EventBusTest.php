<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Bus;

use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Bus\EventBus;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Contract\MessageSerializer;
use SomeWork\CqrsBundle\Contract\RetryPolicy;
use SomeWork\CqrsBundle\Support\NullMessageSerializer;
use SomeWork\CqrsBundle\Support\NullRetryPolicy;
use SomeWork\CqrsBundle\Tests\Fixture\DummyStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\SerializerStamp;

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

        $bus = new EventBus($syncBus, null, new NullRetryPolicy(), new NullMessageSerializer());

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

        $bus = new EventBus($syncBus, $asyncBus, new NullRetryPolicy(), new NullMessageSerializer());

        self::assertSame($envelope, $bus->dispatch($event, DispatchMode::ASYNC));
    }

    public function test_async_dispatch_without_bus_throws_exception(): void
    {
        $event = $this->createStub(Event::class);

        $syncBus = $this->createMock(MessageBusInterface::class);

        $bus = new EventBus($syncBus, null, new NullRetryPolicy(), new NullMessageSerializer());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Asynchronous event bus is not configured.');

        $bus->dispatch($event, DispatchMode::ASYNC);
    }

    public function test_dispatch_applies_retry_policy_and_serializer(): void
    {
        $event = $this->createStub(Event::class);
        $retryStamp = new DummyStamp('retry');
        $serializerStamp = new SerializerStamp(['format' => 'json']);

        $policy = $this->createMock(RetryPolicy::class);
        $policy->expects(self::once())
            ->method('getStamps')
            ->with($event, DispatchMode::SYNC)
            ->willReturn([$retryStamp]);

        $serializer = $this->createMock(MessageSerializer::class);
        $serializer->expects(self::once())
            ->method('getStamp')
            ->with($event, DispatchMode::SYNC)
            ->willReturn($serializerStamp);

        $syncBus = $this->createMock(MessageBusInterface::class);
        $syncBus->expects(self::once())
            ->method('dispatch')
            ->with(
                $event,
                self::callback(function (array $stamps) use ($retryStamp, $serializerStamp): bool {
                    self::assertSame([$retryStamp, $serializerStamp], $stamps);

                    return true;
                })
            )
            ->willReturn(new Envelope($event));

        $bus = new EventBus($syncBus, null, $policy, $serializer);

        $bus->dispatch($event);
    }
}
