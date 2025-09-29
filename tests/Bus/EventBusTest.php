<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Bus;

use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Bus\EventBus;
use SomeWork\CqrsBundle\Contract\MessageSerializer;
use SomeWork\CqrsBundle\Contract\RetryPolicy;
use SomeWork\CqrsBundle\Support\NullMessageSerializer;
use SomeWork\CqrsBundle\Support\RetryPolicyResolver;
use SomeWork\CqrsBundle\Tests\Fixture\DummyStamp;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskCreatedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Message\RetryAwareMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\SerializerStamp;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class EventBusTest extends TestCase
{
    public function test_dispatch_uses_sync_bus_by_default(): void
    {
        $event = new TaskCreatedEvent('123');
        $envelope = new Envelope($event);

        $syncBus = $this->createMock(MessageBusInterface::class);
        $syncBus->expects(self::once())
            ->method('dispatch')
            ->with($event, [])
            ->willReturn($envelope);

        $bus = new EventBus($syncBus, null, RetryPolicyResolver::withoutOverrides(), new NullMessageSerializer());

        self::assertSame($envelope, $bus->dispatch($event));
    }

    public function test_dispatch_to_async_bus_when_configured(): void
    {
        $event = new TaskCreatedEvent('123');
        $envelope = new Envelope($event);

        $syncBus = $this->createMock(MessageBusInterface::class);
        $syncBus->expects(self::never())->method('dispatch');

        $asyncBus = $this->createMock(MessageBusInterface::class);
        $asyncBus->expects(self::once())
            ->method('dispatch')
            ->with($event, [])
            ->willReturn($envelope);

        $bus = new EventBus($syncBus, $asyncBus, RetryPolicyResolver::withoutOverrides(), new NullMessageSerializer());

        self::assertSame($envelope, $bus->dispatch($event, DispatchMode::ASYNC));
    }

    public function test_async_dispatch_without_bus_throws_exception(): void
    {
        $event = new TaskCreatedEvent('123');

        $syncBus = $this->createMock(MessageBusInterface::class);

        $bus = new EventBus($syncBus, null, RetryPolicyResolver::withoutOverrides(), new NullMessageSerializer());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Asynchronous event bus is not configured.');

        $bus->dispatch($event, DispatchMode::ASYNC);
    }

    public function test_dispatch_applies_retry_policy_and_serializer(): void
    {
        $event = new TaskCreatedEvent('123');
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

        $resolver = new RetryPolicyResolver($policy, new ServiceLocator([]));

        $bus = new EventBus($syncBus, null, $resolver, $serializer);

        $bus->dispatch($event);
    }

    public function test_dispatch_uses_message_specific_retry_policy_for_async_mode(): void
    {
        $event = new TaskCreatedEvent('123');
        $retryStamp = new DummyStamp('retry');

        $defaultPolicy = $this->createMock(RetryPolicy::class);
        $defaultPolicy->expects(self::never())->method('getStamps');

        $eventPolicy = $this->createMock(RetryPolicy::class);
        $eventPolicy->expects(self::once())
            ->method('getStamps')
            ->with($event, DispatchMode::ASYNC)
            ->willReturn([$retryStamp]);

        $serializer = new NullMessageSerializer();

        $syncBus = $this->createMock(MessageBusInterface::class);
        $syncBus->expects(self::never())->method('dispatch');

        $asyncBus = $this->createMock(MessageBusInterface::class);
        $asyncBus->expects(self::once())
            ->method('dispatch')
            ->with($event, [$retryStamp])
            ->willReturn(new Envelope($event));

        $resolver = new RetryPolicyResolver(
            $defaultPolicy,
            new ServiceLocator([
                TaskCreatedEvent::class => static fn (): RetryPolicy => $eventPolicy,
            ])
        );

        $bus = new EventBus($syncBus, $asyncBus, $resolver, $serializer);

        $bus->dispatch($event, DispatchMode::ASYNC);
    }

    public function test_dispatch_uses_retry_policy_bound_to_interface(): void
    {
        $event = new TaskCreatedEvent('123');
        $retryStamp = new DummyStamp('retry');

        $defaultPolicy = $this->createMock(RetryPolicy::class);
        $defaultPolicy->expects(self::never())->method('getStamps');

        $interfacePolicy = $this->createMock(RetryPolicy::class);
        $interfacePolicy->expects(self::once())
            ->method('getStamps')
            ->with($event, DispatchMode::SYNC)
            ->willReturn([$retryStamp]);

        $syncBus = $this->createMock(MessageBusInterface::class);
        $syncBus->expects(self::once())
            ->method('dispatch')
            ->with($event, [$retryStamp])
            ->willReturn(new Envelope($event));

        $resolver = new RetryPolicyResolver(
            $defaultPolicy,
            new ServiceLocator([
                RetryAwareMessage::class => static fn (): RetryPolicy => $interfacePolicy,
            ])
        );

        $bus = new EventBus($syncBus, null, $resolver, new NullMessageSerializer());

        $bus->dispatch($event);
    }
}
