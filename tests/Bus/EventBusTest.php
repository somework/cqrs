<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Bus;

use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Bus\DispatchModeDecider;
use SomeWork\CqrsBundle\Bus\EventBus;
use SomeWork\CqrsBundle\Contract\Event as EventContract;
use SomeWork\CqrsBundle\Contract\MessageSerializer;
use SomeWork\CqrsBundle\Contract\RetryPolicy;
use SomeWork\CqrsBundle\Support\DispatchAfterCurrentBusDecider;
use SomeWork\CqrsBundle\Support\DispatchAfterCurrentBusStampDecider;
use SomeWork\CqrsBundle\Support\MessageSerializerResolver;
use SomeWork\CqrsBundle\Support\MessageSerializerStampDecider;
use SomeWork\CqrsBundle\Support\NullMessageSerializer;
use SomeWork\CqrsBundle\Support\RetryPolicyResolver;
use SomeWork\CqrsBundle\Support\RetryPolicyStampDecider;
use SomeWork\CqrsBundle\Support\StampsDecider;
use SomeWork\CqrsBundle\Tests\Fixture\DummyStamp;
use SomeWork\CqrsBundle\Tests\Fixture\Message\RetryAwareMessage;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskCreatedEvent;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;
use Symfony\Component\Messenger\Stamp\SerializerStamp;

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

        $bus = new EventBus($syncBus, stampsDecider: $this->createEventStampsDecider());

        self::assertSame($envelope, $bus->dispatch($event));
    }

    public function test_dispatch_sync_helper_uses_sync_bus(): void
    {
        $event = new TaskCreatedEvent('123');
        $envelope = new Envelope($event);

        $syncBus = $this->createMock(MessageBusInterface::class);
        $syncBus->expects(self::once())
            ->method('dispatch')
            ->with($event, [])
            ->willReturn($envelope);

        $asyncBus = $this->createMock(MessageBusInterface::class);
        $asyncBus->expects(self::never())->method('dispatch');

        $bus = new EventBus($syncBus, $asyncBus, stampsDecider: $this->createEventStampsDecider());

        self::assertSame($envelope, $bus->dispatchSync($event));
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
            ->with($event, self::callback(static function (array $stamps): bool {
                foreach ($stamps as $stamp) {
                    if ($stamp instanceof DispatchAfterCurrentBusStamp) {
                        return true;
                    }
                }

                return false;
            }))
            ->willReturn($envelope);

        $bus = new EventBus($syncBus, $asyncBus, stampsDecider: $this->createEventStampsDecider());

        self::assertSame($envelope, $bus->dispatch($event, DispatchMode::ASYNC));
    }

    public function test_dispatch_async_helper_to_async_bus_when_configured(): void
    {
        $event = new TaskCreatedEvent('123');
        $envelope = new Envelope($event);

        $syncBus = $this->createMock(MessageBusInterface::class);
        $syncBus->expects(self::never())->method('dispatch');

        $asyncBus = $this->createMock(MessageBusInterface::class);
        $asyncBus->expects(self::once())
            ->method('dispatch')
            ->with($event, self::callback(static function (array $stamps): bool {
                foreach ($stamps as $stamp) {
                    if ($stamp instanceof DispatchAfterCurrentBusStamp) {
                        return true;
                    }
                }

                return false;
            }))
            ->willReturn($envelope);

        $bus = new EventBus($syncBus, $asyncBus, stampsDecider: $this->createEventStampsDecider());

        self::assertSame($envelope, $bus->dispatchAsync($event));
    }

    public function test_dispatch_uses_decider_default_when_mode_not_explicit(): void
    {
        $event = new TaskCreatedEvent('123');
        $envelope = new Envelope($event);

        $syncBus = $this->createMock(MessageBusInterface::class);
        $syncBus->expects(self::never())->method('dispatch');

        $asyncBus = $this->createMock(MessageBusInterface::class);
        $asyncBus->expects(self::once())
            ->method('dispatch')
            ->with($event, self::callback(static function (array $stamps): bool {
                foreach ($stamps as $stamp) {
                    if ($stamp instanceof DispatchAfterCurrentBusStamp) {
                        return true;
                    }
                }

                return false;
            }))
            ->willReturn($envelope);

        $bus = new EventBus(
            $syncBus,
            $asyncBus,
            dispatchModeDecider: new DispatchModeDecider(DispatchMode::SYNC, DispatchMode::ASYNC),
            stampsDecider: $this->createEventStampsDecider(),
        );

        self::assertSame($envelope, $bus->dispatch($event));
    }

    public function test_dispatch_uses_decider_map_override(): void
    {
        $event = new TaskCreatedEvent('123');
        $envelope = new Envelope($event);

        $syncBus = $this->createMock(MessageBusInterface::class);
        $syncBus->expects(self::never())->method('dispatch');

        $asyncBus = $this->createMock(MessageBusInterface::class);
        $asyncBus->expects(self::once())
            ->method('dispatch')
            ->with($event, self::callback(static function (array $stamps): bool {
                foreach ($stamps as $stamp) {
                    if ($stamp instanceof DispatchAfterCurrentBusStamp) {
                        return true;
                    }
                }

                return false;
            }))
            ->willReturn($envelope);

        $bus = new EventBus(
            $syncBus,
            $asyncBus,
            dispatchModeDecider: new DispatchModeDecider(DispatchMode::SYNC, DispatchMode::SYNC, [], [TaskCreatedEvent::class => DispatchMode::ASYNC]),
            stampsDecider: $this->createEventStampsDecider(),
        );

        self::assertSame($envelope, $bus->dispatch($event));
    }

    public function test_async_dispatch_appends_dispatch_after_current_bus_stamp_by_default(): void
    {
        $event = new TaskCreatedEvent('123');
        $envelope = new Envelope($event);

        $syncBus = $this->createMock(MessageBusInterface::class);
        $syncBus->expects(self::never())->method('dispatch');

        $asyncBus = $this->createMock(MessageBusInterface::class);
        $asyncBus->expects(self::once())
            ->method('dispatch')
            ->with($event, self::callback(static function (array $stamps): bool {
                foreach ($stamps as $stamp) {
                    if ($stamp instanceof DispatchAfterCurrentBusStamp) {
                        return true;
                    }
                }

                return false;
            }))
            ->willReturn($envelope);

        $bus = new EventBus(
            $syncBus,
            $asyncBus,
            stampsDecider: $this->createEventStampsDecider(),
        );

        self::assertSame($envelope, $bus->dispatch($event, DispatchMode::ASYNC));
    }

    public function test_async_dispatch_respects_message_override(): void
    {
        $event = new TaskCreatedEvent('123');
        $envelope = new Envelope($event);

        $syncBus = $this->createMock(MessageBusInterface::class);
        $syncBus->expects(self::never())->method('dispatch');

        $asyncBus = $this->createMock(MessageBusInterface::class);
        $asyncBus->expects(self::once())
            ->method('dispatch')
            ->with($event, self::callback(static function (array $stamps): bool {
                foreach ($stamps as $stamp) {
                    if ($stamp instanceof DispatchAfterCurrentBusStamp) {
                        return false;
                    }
                }

                return true;
            }))
            ->willReturn($envelope);

        $bus = new EventBus(
            $syncBus,
            $asyncBus,
            stampsDecider: $this->createEventStampsDecider(
                dispatchAfter: new DispatchAfterCurrentBusDecider(
                    true,
                    new ServiceLocator([]),
                    true,
                    new ServiceLocator([
                        TaskCreatedEvent::class => static fn (): bool => false,
                    ]),
                )
            ),
        );

        self::assertSame($envelope, $bus->dispatch($event, DispatchMode::ASYNC));
    }

    public function test_explicit_mode_bypasses_decider(): void
    {
        $event = new TaskCreatedEvent('123');
        $envelope = new Envelope($event);

        $syncBus = $this->createMock(MessageBusInterface::class);
        $syncBus->expects(self::once())
            ->method('dispatch')
            ->with($event, [])
            ->willReturn($envelope);

        $asyncBus = $this->createMock(MessageBusInterface::class);
        $asyncBus->expects(self::never())->method('dispatch');

        $bus = new EventBus(
            $syncBus,
            $asyncBus,
            dispatchModeDecider: new DispatchModeDecider(DispatchMode::ASYNC, DispatchMode::ASYNC),
            stampsDecider: $this->createEventStampsDecider(),
        );

        self::assertSame($envelope, $bus->dispatch($event, DispatchMode::SYNC));
    }

    public function test_async_dispatch_without_bus_throws_exception(): void
    {
        $event = new TaskCreatedEvent('123');

        $syncBus = $this->createMock(MessageBusInterface::class);

        $bus = new EventBus($syncBus, stampsDecider: $this->createEventStampsDecider());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Asynchronous event bus is not configured.');

        $bus->dispatch($event, DispatchMode::ASYNC);
    }

    public function test_dispatch_async_helper_without_bus_throws_exception(): void
    {
        $event = new TaskCreatedEvent('123');

        $syncBus = $this->createMock(MessageBusInterface::class);

        $bus = new EventBus($syncBus, stampsDecider: $this->createEventStampsDecider());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Asynchronous event bus is not configured.');

        $bus->dispatchAsync($event);
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
        $serializers = $this->createSerializerResolver(new NullMessageSerializer(), null, [
            TaskCreatedEvent::class => $serializer,
        ]);

        $bus = new EventBus($syncBus, stampsDecider: $this->createEventStampsDecider($resolver, $serializers));

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
            ->with($event, self::callback(static function (array $stamps) use ($retryStamp): bool {
                self::assertCount(2, $stamps);
                self::assertSame($retryStamp, $stamps[0]);
                self::assertInstanceOf(DispatchAfterCurrentBusStamp::class, $stamps[1]);

                return true;
            }))
            ->willReturn(new Envelope($event));

        $resolver = new RetryPolicyResolver(
            $defaultPolicy,
            new ServiceLocator([
                TaskCreatedEvent::class => static fn (): RetryPolicy => $eventPolicy,
            ])
        );

        $serializers = MessageSerializerResolver::withoutOverrides($serializer);

        $bus = new EventBus($syncBus, $asyncBus, stampsDecider: $this->createEventStampsDecider($resolver, $serializers));

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

        $bus = new EventBus(
            $syncBus,
            stampsDecider: $this->createEventStampsDecider(
                $resolver,
                MessageSerializerResolver::withoutOverrides(new NullMessageSerializer()),
            ),
        );

        $bus->dispatch($event);
    }

    public function test_dispatch_prefers_message_specific_serializer(): void
    {
        $event = new TaskCreatedEvent('123');

        $messageSerializer = $this->createMock(MessageSerializer::class);
        $messageSerializer->expects(self::once())
            ->method('getStamp')
            ->with($event, DispatchMode::SYNC)
            ->willReturn(null);

        $typeSerializer = $this->createMock(MessageSerializer::class);
        $typeSerializer->expects(self::never())->method('getStamp');

        $globalSerializer = $this->createMock(MessageSerializer::class);
        $globalSerializer->expects(self::never())->method('getStamp');

        $serializers = $this->createSerializerResolver(
            $globalSerializer,
            $typeSerializer,
            [TaskCreatedEvent::class => $messageSerializer],
        );

        $syncBus = $this->createMock(MessageBusInterface::class);
        $syncBus->expects(self::once())
            ->method('dispatch')
            ->with($event, [])
            ->willReturn(new Envelope($event));

        $bus = new EventBus($syncBus, stampsDecider: $this->createEventStampsDecider(null, $serializers));

        $bus->dispatch($event);
    }

    public function test_dispatch_uses_type_default_serializer_when_no_override(): void
    {
        $event = new TaskCreatedEvent('123');

        $typeSerializer = $this->createMock(MessageSerializer::class);
        $typeSerializer->expects(self::once())
            ->method('getStamp')
            ->with($event, DispatchMode::SYNC)
            ->willReturn(null);

        $globalSerializer = $this->createMock(MessageSerializer::class);
        $globalSerializer->expects(self::never())->method('getStamp');

        $serializers = $this->createSerializerResolver($globalSerializer, $typeSerializer);

        $syncBus = $this->createMock(MessageBusInterface::class);
        $syncBus->expects(self::once())
            ->method('dispatch')
            ->with($event, [])
            ->willReturn(new Envelope($event));

        $bus = new EventBus($syncBus, stampsDecider: $this->createEventStampsDecider(null, $serializers));

        $bus->dispatch($event);
    }

    public function test_dispatch_falls_back_to_global_default_serializer(): void
    {
        $event = new TaskCreatedEvent('123');

        $globalSerializer = $this->createMock(MessageSerializer::class);
        $globalSerializer->expects(self::once())
            ->method('getStamp')
            ->with($event, DispatchMode::SYNC)
            ->willReturn(null);

        $serializers = $this->createSerializerResolver($globalSerializer, $globalSerializer);

        $syncBus = $this->createMock(MessageBusInterface::class);
        $syncBus->expects(self::once())
            ->method('dispatch')
            ->with($event, [])
            ->willReturn(new Envelope($event));

        $bus = new EventBus($syncBus, stampsDecider: $this->createEventStampsDecider(null, $serializers));

        $bus->dispatch($event);
    }

    public function test_dispatch_skips_null_serializer_stamp(): void
    {
        $event = new TaskCreatedEvent('123');
        $retryStamp = new DummyStamp('retry');

        $policy = $this->createMock(RetryPolicy::class);
        $policy->expects(self::once())
            ->method('getStamps')
            ->with($event, DispatchMode::SYNC)
            ->willReturn([$retryStamp]);

        $serializer = $this->createMock(MessageSerializer::class);
        $serializer->expects(self::once())
            ->method('getStamp')
            ->with($event, DispatchMode::SYNC)
            ->willReturn(null);

        $syncBus = $this->createMock(MessageBusInterface::class);
        $syncBus->expects(self::once())
            ->method('dispatch')
            ->with(
                $event,
                self::callback(function (array $stamps) use ($retryStamp): bool {
                    self::assertSame([$retryStamp], $stamps);

                    return true;
                })
            )
            ->willReturn(new Envelope($event));

        $retryResolver = new RetryPolicyResolver($policy, new ServiceLocator([]));
        $serializers = $this->createSerializerResolver(new NullMessageSerializer(), null, [
            TaskCreatedEvent::class => $serializer,
        ]);

        $bus = new EventBus($syncBus, stampsDecider: $this->createEventStampsDecider($retryResolver, $serializers));

        $bus->dispatch($event);
    }

    private function createEventStampsDecider(
        ?RetryPolicyResolver $retryPolicies = null,
        ?MessageSerializerResolver $serializers = null,
        ?DispatchAfterCurrentBusDecider $dispatchAfter = null,
    ): StampsDecider {
        $retryPolicies ??= RetryPolicyResolver::withoutOverrides();
        $serializers ??= MessageSerializerResolver::withoutOverrides();
        $dispatchAfter ??= DispatchAfterCurrentBusDecider::defaults();

        return new StampsDecider([
            new RetryPolicyStampDecider($retryPolicies, EventContract::class),
            new MessageSerializerStampDecider($serializers, EventContract::class),
            new DispatchAfterCurrentBusStampDecider($dispatchAfter),
        ]);
    }

    /**
     * @param array<class-string, MessageSerializer> $map
     */
    private function createSerializerResolver(
        MessageSerializer $global,
        ?MessageSerializer $type = null,
        array $map = []
    ): MessageSerializerResolver {
        $type ??= $global;

        $services = [
            MessageSerializerResolver::GLOBAL_DEFAULT_KEY => static fn (): MessageSerializer => $global,
            MessageSerializerResolver::TYPE_DEFAULT_KEY => static fn (): MessageSerializer => $type,
        ];

        foreach ($map as $class => $serializer) {
            $services[$class] = static fn (): MessageSerializer => $serializer;
        }

        return new MessageSerializerResolver(new ServiceLocator($services));
    }
}
