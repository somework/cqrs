<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Bus;

use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\CommandBus;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Bus\DispatchModeDecider;
use SomeWork\CqrsBundle\Contract\Command as CommandContract;
use SomeWork\CqrsBundle\Contract\MessageSerializer;
use SomeWork\CqrsBundle\Contract\RetryPolicy;
use SomeWork\CqrsBundle\Support\DispatchAfterCurrentBusDecider;
use SomeWork\CqrsBundle\Support\DispatchAfterCurrentBusStampDecider;
use SomeWork\CqrsBundle\Support\MessageSerializerResolver;
use SomeWork\CqrsBundle\Support\MessageSerializerStampDecider;
use SomeWork\CqrsBundle\Support\MessageTransportResolver;
use SomeWork\CqrsBundle\Support\MessageTransportStampDecider;
use SomeWork\CqrsBundle\Support\NullMessageSerializer;
use SomeWork\CqrsBundle\Support\RetryPolicyResolver;
use SomeWork\CqrsBundle\Support\RetryPolicyStampDecider;
use SomeWork\CqrsBundle\Support\StampsDecider;
use SomeWork\CqrsBundle\Tests\Fixture\DummyStamp;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\RetryAwareMessage;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;
use Symfony\Component\Messenger\Stamp\SerializerStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

final class CommandBusTest extends TestCase
{
    public function test_dispatch_uses_sync_bus_by_default(): void
    {
        $command = new CreateTaskCommand('123', 'Test');
        $envelope = new Envelope($command);

        $syncBus = $this->createMock(MessageBusInterface::class);
        $syncBus->expects(self::once())
            ->method('dispatch')
            ->with($command, [])
            ->willReturn($envelope);

        $bus = new CommandBus($syncBus, stampsDecider: $this->createCommandStampsDecider());

        self::assertSame($envelope, $bus->dispatch($command));
    }

    public function test_dispatch_sync_helper_uses_sync_bus(): void
    {
        $command = new CreateTaskCommand('123', 'Test');
        $envelope = new Envelope($command);

        $syncBus = $this->createMock(MessageBusInterface::class);
        $syncBus->expects(self::once())
            ->method('dispatch')
            ->with($command, [])
            ->willReturn($envelope);

        $asyncBus = $this->createMock(MessageBusInterface::class);
        $asyncBus->expects(self::never())->method('dispatch');

        $bus = new CommandBus($syncBus, $asyncBus, stampsDecider: $this->createCommandStampsDecider());

        self::assertSame($envelope, $bus->dispatchSync($command));
    }

    public function test_dispatch_to_async_bus_when_configured(): void
    {
        $command = new CreateTaskCommand('123', 'Test');
        $envelope = new Envelope($command);

        $syncBus = $this->createMock(MessageBusInterface::class);
        $syncBus->expects(self::never())->method('dispatch');

        $asyncBus = $this->createMock(MessageBusInterface::class);
        $asyncBus->expects(self::once())
            ->method('dispatch')
            ->with($command, self::callback(static function (array $stamps): bool {
                foreach ($stamps as $stamp) {
                    if ($stamp instanceof DispatchAfterCurrentBusStamp) {
                        return true;
                    }
                }

                return false;
            }))
            ->willReturn($envelope);

        $bus = new CommandBus($syncBus, $asyncBus, stampsDecider: $this->createCommandStampsDecider());

        self::assertSame($envelope, $bus->dispatch($command, DispatchMode::ASYNC));
    }

    public function test_dispatch_async_helper_to_async_bus_when_configured(): void
    {
        $command = new CreateTaskCommand('123', 'Test');
        $envelope = new Envelope($command);

        $syncBus = $this->createMock(MessageBusInterface::class);
        $syncBus->expects(self::never())->method('dispatch');

        $asyncBus = $this->createMock(MessageBusInterface::class);
        $asyncBus->expects(self::once())
            ->method('dispatch')
            ->with($command, self::callback(static function (array $stamps): bool {
                foreach ($stamps as $stamp) {
                    if ($stamp instanceof DispatchAfterCurrentBusStamp) {
                        return true;
                    }
                }

                return false;
            }))
            ->willReturn($envelope);

        $bus = new CommandBus($syncBus, $asyncBus, stampsDecider: $this->createCommandStampsDecider());

        self::assertSame($envelope, $bus->dispatchAsync($command));
    }

    public function test_dispatch_uses_decider_default_when_mode_not_explicit(): void
    {
        $command = new CreateTaskCommand('123', 'Test');
        $envelope = new Envelope($command);

        $syncBus = $this->createMock(MessageBusInterface::class);
        $syncBus->expects(self::never())->method('dispatch');

        $asyncBus = $this->createMock(MessageBusInterface::class);
        $asyncBus->expects(self::once())
            ->method('dispatch')
            ->with($command, self::callback(static function (array $stamps): bool {
                foreach ($stamps as $stamp) {
                    if ($stamp instanceof DispatchAfterCurrentBusStamp) {
                        return true;
                    }
                }

                return false;
            }))
            ->willReturn($envelope);

        $bus = new CommandBus(
            $syncBus,
            $asyncBus,
            dispatchModeDecider: new DispatchModeDecider(DispatchMode::ASYNC, DispatchMode::SYNC),
            stampsDecider: $this->createCommandStampsDecider(),
        );

        self::assertSame($envelope, $bus->dispatch($command));
    }

    public function test_dispatch_uses_decider_map_override(): void
    {
        $command = new CreateTaskCommand('123', 'Test');
        $envelope = new Envelope($command);

        $syncBus = $this->createMock(MessageBusInterface::class);
        $syncBus->expects(self::never())->method('dispatch');

        $asyncBus = $this->createMock(MessageBusInterface::class);
        $asyncBus->expects(self::once())
            ->method('dispatch')
            ->with($command, self::callback(static function (array $stamps): bool {
                foreach ($stamps as $stamp) {
                    if ($stamp instanceof DispatchAfterCurrentBusStamp) {
                        return true;
                    }
                }

                return false;
            }))
            ->willReturn($envelope);

        $bus = new CommandBus(
            $syncBus,
            $asyncBus,
            dispatchModeDecider: new DispatchModeDecider(DispatchMode::SYNC, DispatchMode::SYNC, [CreateTaskCommand::class => DispatchMode::ASYNC]),
            stampsDecider: $this->createCommandStampsDecider(),
        );

        self::assertSame($envelope, $bus->dispatch($command));
    }

    public function test_async_dispatch_appends_dispatch_after_current_bus_stamp_by_default(): void
    {
        $command = new CreateTaskCommand('123', 'Test');
        $envelope = new Envelope($command);

        $syncBus = $this->createMock(MessageBusInterface::class);
        $syncBus->expects(self::never())->method('dispatch');

        $asyncBus = $this->createMock(MessageBusInterface::class);
        $asyncBus->expects(self::once())
            ->method('dispatch')
            ->with($command, self::callback(static function (array $stamps): bool {
                foreach ($stamps as $stamp) {
                    if ($stamp instanceof DispatchAfterCurrentBusStamp) {
                        return true;
                    }
                }

                return false;
            }))
            ->willReturn($envelope);

        $bus = new CommandBus(
            $syncBus,
            $asyncBus,
            stampsDecider: $this->createCommandStampsDecider(),
        );

        self::assertSame($envelope, $bus->dispatch($command, DispatchMode::ASYNC));
    }

    public function test_async_dispatch_respects_message_override(): void
    {
        $command = new CreateTaskCommand('123', 'Test');
        $envelope = new Envelope($command);

        $syncBus = $this->createMock(MessageBusInterface::class);
        $syncBus->expects(self::never())->method('dispatch');

        $asyncBus = $this->createMock(MessageBusInterface::class);
        $asyncBus->expects(self::once())
            ->method('dispatch')
            ->with($command, self::callback(static function (array $stamps): bool {
                foreach ($stamps as $stamp) {
                    if ($stamp instanceof DispatchAfterCurrentBusStamp) {
                        return false;
                    }
                }

                return true;
            }))
            ->willReturn($envelope);

        $bus = new CommandBus(
            $syncBus,
            $asyncBus,
            stampsDecider: $this->createCommandStampsDecider(
                dispatchAfter: new DispatchAfterCurrentBusDecider(
                    true,
                    new ServiceLocator([
                        CreateTaskCommand::class => static fn (): bool => false,
                    ]),
                    true,
                    new ServiceLocator([]),
                )
            ),
        );

        self::assertSame($envelope, $bus->dispatch($command, DispatchMode::ASYNC));
    }

    public function test_explicit_mode_bypasses_decider(): void
    {
        $command = new CreateTaskCommand('123', 'Test');
        $envelope = new Envelope($command);

        $syncBus = $this->createMock(MessageBusInterface::class);
        $syncBus->expects(self::once())
            ->method('dispatch')
            ->with($command, [])
            ->willReturn($envelope);

        $asyncBus = $this->createMock(MessageBusInterface::class);
        $asyncBus->expects(self::never())->method('dispatch');

        $bus = new CommandBus(
            $syncBus,
            $asyncBus,
            dispatchModeDecider: new DispatchModeDecider(DispatchMode::ASYNC, DispatchMode::ASYNC),
            stampsDecider: $this->createCommandStampsDecider(),
        );

        self::assertSame($envelope, $bus->dispatch($command, DispatchMode::SYNC));
    }

    public function test_async_dispatch_without_bus_throws_exception(): void
    {
        $command = new CreateTaskCommand('123', 'Test');

        $syncBus = $this->createMock(MessageBusInterface::class);

        $bus = new CommandBus($syncBus, stampsDecider: $this->createCommandStampsDecider());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Asynchronous command bus is not configured.');

        $bus->dispatch($command, DispatchMode::ASYNC);
    }

    public function test_dispatch_async_helper_without_bus_throws_exception(): void
    {
        $command = new CreateTaskCommand('123', 'Test');

        $syncBus = $this->createMock(MessageBusInterface::class);

        $bus = new CommandBus($syncBus, stampsDecider: $this->createCommandStampsDecider());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Asynchronous command bus is not configured.');

        $bus->dispatchAsync($command);
    }

    public function test_dispatch_appends_retry_and_serializer_stamps(): void
    {
        $command = new CreateTaskCommand('123', 'Test');
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

        $resolver = new RetryPolicyResolver($policy, new ServiceLocator([]));
        $serializers = $this->createSerializerResolver(new NullMessageSerializer(), null, [
            CreateTaskCommand::class => $serializer,
        ]);

        $bus = new CommandBus($syncBus, stampsDecider: $this->createCommandStampsDecider($resolver, $serializers));

        $bus->dispatch($command);
    }

    public function test_dispatch_passes_mode_to_retry_policy_and_serializer(): void
    {
        $command = new CreateTaskCommand('123', 'Test');
        $retryStamp = new DummyStamp('retry');
        $serializerStamp = new SerializerStamp(['format' => 'json']);

        $defaultPolicy = $this->createMock(RetryPolicy::class);
        $defaultPolicy->expects(self::never())->method('getStamps');

        $messagePolicy = $this->createMock(RetryPolicy::class);
        $messagePolicy->expects(self::once())
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
                    self::assertCount(4, $stamps);
                    self::assertSame($retryStamp, $stamps[0]);
                    self::assertInstanceOf(TransportNamesStamp::class, $stamps[1]);
                    self::assertSame(['async'], $stamps[1]->getTransportNames());
                    self::assertSame($serializerStamp, $stamps[2]);
                    self::assertInstanceOf(DispatchAfterCurrentBusStamp::class, $stamps[3]);

                    return true;
                })
            )
            ->willReturn(new Envelope($command));

        $resolver = new RetryPolicyResolver(
            $defaultPolicy,
            new ServiceLocator([
                CreateTaskCommand::class => static fn (): RetryPolicy => $messagePolicy,
            ])
        );

        $serializers = $this->createSerializerResolver(new NullMessageSerializer(), null, [
            CreateTaskCommand::class => $serializer,
        ]);

        $transports = $this->createTransportResolver([], [
            CreateTaskCommand::class => ['sync'],
        ]);
        $asyncTransports = $this->createTransportResolver([], [
            CreateTaskCommand::class => ['async'],
        ]);

        $bus = new CommandBus(
            $syncBus,
            $asyncBus,
            stampsDecider: $this->createCommandStampsDecider(
                $resolver,
                $serializers,
                transports: $transports,
                asyncTransports: $asyncTransports,
            ),
        );

        $bus->dispatch($command, DispatchMode::ASYNC);
    }

    public function test_async_dispatch_preserves_explicit_transport_stamp(): void
    {
        $command = new CreateTaskCommand('123', 'Test');
        $transportStamp = new TransportNamesStamp(['explicit']);

        $syncBus = $this->createMock(MessageBusInterface::class);
        $syncBus->expects(self::never())->method('dispatch');

        $asyncBus = $this->createMock(MessageBusInterface::class);
        $asyncBus->expects(self::once())
            ->method('dispatch')
            ->with(
                $command,
                self::callback(function (array $stamps) use ($transportStamp): bool {
                    $transportStamps = array_values(array_filter(
                        $stamps,
                        static fn ($stamp): bool => $stamp instanceof TransportNamesStamp,
                    ));

                    self::assertSame([$transportStamp], $transportStamps);

                    return true;
                })
            )
            ->willReturn(new Envelope($command));

        $asyncTransports = $this->createTransportResolver([], [
            CreateTaskCommand::class => ['async'],
        ]);

        $bus = new CommandBus(
            $syncBus,
            $asyncBus,
            stampsDecider: $this->createCommandStampsDecider(
                transports: null,
                asyncTransports: $asyncTransports,
            ),
        );

        $bus->dispatch($command, DispatchMode::ASYNC, $transportStamp);
    }

    public function test_dispatch_uses_retry_policy_bound_to_interface(): void
    {
        $command = new CreateTaskCommand('123', 'Test');
        $retryStamp = new DummyStamp('retry');

        $defaultPolicy = $this->createMock(RetryPolicy::class);
        $defaultPolicy->expects(self::never())->method('getStamps');

        $interfacePolicy = $this->createMock(RetryPolicy::class);
        $interfacePolicy->expects(self::once())
            ->method('getStamps')
            ->with($command, DispatchMode::SYNC)
            ->willReturn([$retryStamp]);

        $syncBus = $this->createMock(MessageBusInterface::class);
        $syncBus->expects(self::once())
            ->method('dispatch')
            ->with(
                $command,
                self::callback(function (array $stamps) use ($retryStamp): bool {
                    self::assertSame([$retryStamp], $stamps);

                    return true;
                })
            )
            ->willReturn(new Envelope($command));

        $resolver = new RetryPolicyResolver(
            $defaultPolicy,
            new ServiceLocator([
                RetryAwareMessage::class => static fn (): RetryPolicy => $interfacePolicy,
            ])
        );

        $bus = new CommandBus(
            $syncBus,
            stampsDecider: $this->createCommandStampsDecider(
                $resolver,
                MessageSerializerResolver::withoutOverrides(new NullMessageSerializer()),
            ),
        );

        $bus->dispatch($command);
    }

    public function test_dispatch_prefers_message_specific_serializer_over_defaults(): void
    {
        $command = new CreateTaskCommand('123', 'Test');
        $envelope = new Envelope($command);

        $messageSerializer = $this->createMock(MessageSerializer::class);
        $messageSerializer->expects(self::once())
            ->method('getStamp')
            ->with($command, DispatchMode::SYNC)
            ->willReturn(null);

        $typeSerializer = $this->createMock(MessageSerializer::class);
        $typeSerializer->expects(self::never())->method('getStamp');

        $globalSerializer = $this->createMock(MessageSerializer::class);
        $globalSerializer->expects(self::never())->method('getStamp');

        $serializers = $this->createSerializerResolver(
            $globalSerializer,
            $typeSerializer,
            [CreateTaskCommand::class => $messageSerializer],
        );

        $syncBus = $this->createMock(MessageBusInterface::class);
        $syncBus->expects(self::once())
            ->method('dispatch')
            ->with($command, [])
            ->willReturn($envelope);

        $bus = new CommandBus($syncBus, stampsDecider: $this->createCommandStampsDecider(null, $serializers));

        $bus->dispatch($command);
    }

    public function test_dispatch_uses_type_default_serializer_when_no_message_override(): void
    {
        $command = new CreateTaskCommand('123', 'Test');
        $envelope = new Envelope($command);

        $typeSerializer = $this->createMock(MessageSerializer::class);
        $typeSerializer->expects(self::once())
            ->method('getStamp')
            ->with($command, DispatchMode::SYNC)
            ->willReturn(null);

        $globalSerializer = $this->createMock(MessageSerializer::class);
        $globalSerializer->expects(self::never())->method('getStamp');

        $serializers = $this->createSerializerResolver($globalSerializer, $typeSerializer);

        $syncBus = $this->createMock(MessageBusInterface::class);
        $syncBus->expects(self::once())
            ->method('dispatch')
            ->with($command, [])
            ->willReturn($envelope);

        $bus = new CommandBus($syncBus, stampsDecider: $this->createCommandStampsDecider(null, $serializers));

        $bus->dispatch($command);
    }

    public function test_dispatch_falls_back_to_global_default_serializer(): void
    {
        $command = new CreateTaskCommand('123', 'Test');
        $envelope = new Envelope($command);

        $globalSerializer = $this->createMock(MessageSerializer::class);
        $globalSerializer->expects(self::once())
            ->method('getStamp')
            ->with($command, DispatchMode::SYNC)
            ->willReturn(null);

        $serializers = $this->createSerializerResolver($globalSerializer, $globalSerializer);

        $syncBus = $this->createMock(MessageBusInterface::class);
        $syncBus->expects(self::once())
            ->method('dispatch')
            ->with($command, [])
            ->willReturn($envelope);

        $bus = new CommandBus($syncBus, stampsDecider: $this->createCommandStampsDecider(null, $serializers));

        $bus->dispatch($command);
    }

    public function test_dispatch_skips_null_serializer_stamp(): void
    {
        $command = new CreateTaskCommand('123', 'Test');
        $retryStamp = new DummyStamp('retry');

        $policy = $this->createMock(RetryPolicy::class);
        $policy->expects(self::once())
            ->method('getStamps')
            ->with($command, DispatchMode::SYNC)
            ->willReturn([$retryStamp]);

        $serializer = $this->createMock(MessageSerializer::class);
        $serializer->expects(self::once())
            ->method('getStamp')
            ->with($command, DispatchMode::SYNC)
            ->willReturn(null);

        $syncBus = $this->createMock(MessageBusInterface::class);
        $syncBus->expects(self::once())
            ->method('dispatch')
            ->with(
                $command,
                self::callback(function (array $stamps) use ($retryStamp): bool {
                    self::assertSame([$retryStamp], $stamps);

                    return true;
                })
            )
            ->willReturn(new Envelope($command));

        $retryResolver = new RetryPolicyResolver($policy, new ServiceLocator([]));
        $serializers = $this->createSerializerResolver(new NullMessageSerializer(), null, [
            CreateTaskCommand::class => $serializer,
        ]);

        $bus = new CommandBus($syncBus, stampsDecider: $this->createCommandStampsDecider($retryResolver, $serializers));

        $bus->dispatch($command);
    }

    private function createCommandStampsDecider(
        ?RetryPolicyResolver $retryPolicies = null,
        ?MessageSerializerResolver $serializers = null,
        ?DispatchAfterCurrentBusDecider $dispatchAfter = null,
        ?MessageTransportResolver $transports = null,
        ?MessageTransportResolver $asyncTransports = null,
    ): StampsDecider {
        $retryPolicies ??= RetryPolicyResolver::withoutOverrides();
        $serializers ??= MessageSerializerResolver::withoutOverrides();
        $dispatchAfter ??= DispatchAfterCurrentBusDecider::defaults();

        return new StampsDecider([
            new RetryPolicyStampDecider($retryPolicies, CommandContract::class),
            new MessageTransportStampDecider(
                $transports,
                $asyncTransports,
                null,
                null,
                null,
            ),
            new MessageSerializerStampDecider($serializers, CommandContract::class),
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

    /**
     * @param list<string>                      $default
     * @param array<class-string, list<string>> $map
     */
    private function createTransportResolver(array $default = [], array $map = []): MessageTransportResolver
    {
        $services = [];

        if ([] !== $default) {
            $services[MessageTransportResolver::DEFAULT_KEY] = static fn (): array => $default;
        }

        foreach ($map as $class => $transports) {
            $services[$class] = static fn (): array => $transports;
        }

        return new MessageTransportResolver(new ServiceLocator($services));
    }
}
