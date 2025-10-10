<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Bus;

use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Bus\QueryBus;
use SomeWork\CqrsBundle\Contract\MessageMetadataProvider;
use SomeWork\CqrsBundle\Contract\MessageSerializer;
use SomeWork\CqrsBundle\Contract\Query;
use SomeWork\CqrsBundle\Contract\RetryPolicy;
use SomeWork\CqrsBundle\Stamp\MessageMetadataStamp;
use SomeWork\CqrsBundle\Support\MessageMetadataProviderResolver;
use SomeWork\CqrsBundle\Support\MessageSerializerResolver;
use SomeWork\CqrsBundle\Support\MessageTransportResolver;
use SomeWork\CqrsBundle\Support\NullMessageSerializer;
use SomeWork\CqrsBundle\Support\RetryPolicyResolver;
use SomeWork\CqrsBundle\Support\StampsDecider;
use SomeWork\CqrsBundle\Tests\Fixture\DummyStamp;
use SomeWork\CqrsBundle\Tests\Fixture\Message\FindTaskQuery;
use SomeWork\CqrsBundle\Tests\Fixture\Message\RetryAwareMessage;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\SerializerStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

final class QueryBusTest extends TestCase
{
    public function test_ask_returns_handled_result(): void
    {
        $query = new FindTaskQuery('123');

        $handled = new HandledStamp('value', 'handler');
        $envelope = (new Envelope($query))->with($handled);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->with($query, [])
            ->willReturn($envelope);

        $queryBus = new QueryBus(
            $bus,
            StampsDecider::withoutDecorators(),
        );

        self::assertSame('value', $queryBus->ask($query));
    }

    public function test_ask_without_result_throws_exception(): void
    {
        $query = new FindTaskQuery('123');
        $envelope = new Envelope($query);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->with($query, [])
            ->willReturn($envelope);

        $queryBus = new QueryBus(
            $bus,
            StampsDecider::withoutDecorators(),
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Query was not handled by any handler.');

        $queryBus->ask($query);
    }

    public function test_ask_with_multiple_results_throws_exception(): void
    {
        $query = new FindTaskQuery('123');

        $firstHandled = new HandledStamp('first', 'first_handler');
        $secondHandled = new HandledStamp('second', 'second_handler');
        $envelope = (new Envelope($query))
            ->with($firstHandled)
            ->with($secondHandled);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->with($query, [])
            ->willReturn($envelope);

        $queryBus = new QueryBus(
            $bus,
            StampsDecider::withoutDecorators(),
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Query was handled multiple times (2 handlers returned a result). Exactly one handler must handle a query.');

        $queryBus->ask($query);
    }

    public function test_ask_merges_supplied_stamps_with_default_pipeline(): void
    {
        $query = new FindTaskQuery('123');
        $userStamp = new DummyStamp('user');
        $retryStamp = new DummyStamp('retry');
        $serializerStamp = new SerializerStamp(['format' => 'json']);
        $metadataStamp = new MessageMetadataStamp('correlation-id');

        $defaultPolicy = $this->createMock(RetryPolicy::class);
        $defaultPolicy->expects(self::never())->method('getStamps');

        $queryPolicy = $this->createMock(RetryPolicy::class);
        $queryPolicy->expects(self::once())
            ->method('getStamps')
            ->with($query, DispatchMode::SYNC)
            ->willReturn([$retryStamp]);

        $serializer = $this->createMock(MessageSerializer::class);
        $serializer->expects(self::once())
            ->method('getStamp')
            ->with($query, DispatchMode::SYNC)
            ->willReturn($serializerStamp);

        $defaultMetadata = new class implements MessageMetadataProvider {
            public function getStamp(object $message, DispatchMode $mode): ?MessageMetadataStamp
            {
                return null;
            }
        };

        $metadataProvider = $this->createMock(MessageMetadataProvider::class);
        $metadataProvider->expects(self::once())
            ->method('getStamp')
            ->with($query, DispatchMode::SYNC)
            ->willReturn($metadataStamp);

        $handled = new HandledStamp('result', 'handler');
        $envelope = (new Envelope($query))->with($handled);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->with(
                $query,
                self::callback(function (array $stamps) use ($userStamp, $retryStamp, $serializerStamp, $metadataStamp): bool {
                    self::assertSame([$userStamp, $retryStamp, $serializerStamp, $metadataStamp], $stamps);

                    return true;
                })
            )
            ->willReturn($envelope);

        $resolver = new RetryPolicyResolver(
            $defaultPolicy,
            new ServiceLocator([
                FindTaskQuery::class => static fn (): RetryPolicy => $queryPolicy,
            ])
        );

        $serializers = $this->createSerializerResolver(new NullMessageSerializer(), null, [
            FindTaskQuery::class => $serializer,
        ]);

        $metadata = $this->createMetadataResolver($defaultMetadata, null, [
            FindTaskQuery::class => $metadataProvider,
        ]);

        $stampsDecider = StampsDecider::withDefaultsFor(Query::class, $resolver, $serializers, $metadata);

        $queryBus = new QueryBus($bus, $stampsDecider);

        self::assertSame('result', $queryBus->ask($query, $userStamp));
    }

    public function test_ask_uses_retry_policy_bound_to_interface(): void
    {
        $query = new FindTaskQuery('123');
        $retryStamp = new DummyStamp('retry');

        $defaultPolicy = $this->createMock(RetryPolicy::class);
        $defaultPolicy->expects(self::never())->method('getStamps');

        $interfacePolicy = $this->createMock(RetryPolicy::class);
        $interfacePolicy->expects(self::once())
            ->method('getStamps')
            ->with($query, DispatchMode::SYNC)
            ->willReturn([$retryStamp]);

        $handled = new HandledStamp('result', 'handler');
        $envelope = (new Envelope($query))->with($handled);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->with($query, [$retryStamp])
            ->willReturn($envelope);

        $resolver = new RetryPolicyResolver(
            $defaultPolicy,
            new ServiceLocator([
                RetryAwareMessage::class => static fn (): RetryPolicy => $interfacePolicy,
            ])
        );

        $metadata = $this->createMetadataResolver($this->createNullMetadataProvider());

        $serializers = MessageSerializerResolver::withoutOverrides(new NullMessageSerializer());
        $stampsDecider = StampsDecider::withDefaultsFor(Query::class, $resolver, $serializers, $metadata);

        $queryBus = new QueryBus($bus, $stampsDecider);

        self::assertSame('result', $queryBus->ask($query));
    }

    public function test_ask_prefers_message_specific_serializer(): void
    {
        $query = new FindTaskQuery('123');

        $messageSerializer = $this->createMock(MessageSerializer::class);
        $messageSerializer->expects(self::once())
            ->method('getStamp')
            ->with($query, DispatchMode::SYNC)
            ->willReturn(null);

        $typeSerializer = $this->createMock(MessageSerializer::class);
        $typeSerializer->expects(self::never())->method('getStamp');

        $globalSerializer = $this->createMock(MessageSerializer::class);
        $globalSerializer->expects(self::never())->method('getStamp');

        $serializers = $this->createSerializerResolver(
            $globalSerializer,
            $typeSerializer,
            [FindTaskQuery::class => $messageSerializer],
        );

        $metadata = $this->createMetadataResolver($this->createNullMetadataProvider());

        $handled = new HandledStamp('result', 'handler');
        $envelope = (new Envelope($query))->with($handled);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->with($query, [])
            ->willReturn($envelope);

        $retryResolver = RetryPolicyResolver::withoutOverrides();
        $stampsDecider = StampsDecider::withDefaultsFor(Query::class, $retryResolver, $serializers, $metadata);

        $queryBus = new QueryBus($bus, $stampsDecider);

        self::assertSame('result', $queryBus->ask($query));
    }

    public function test_ask_appends_transport_names_stamp(): void
    {
        $query = new FindTaskQuery('123');
        $handled = new HandledStamp('result', 'handler');
        $envelope = (new Envelope($query))->with($handled);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->with(
                $query,
                self::callback(static function (array $stamps): bool {
                    self::assertCount(1, $stamps);
                    self::assertInstanceOf(TransportNamesStamp::class, $stamps[0]);
                    self::assertSame(['queries'], $stamps[0]->getTransportNames());

                    return true;
                })
            )
            ->willReturn($envelope);

        $retryResolver = RetryPolicyResolver::withoutOverrides();
        $serializers = MessageSerializerResolver::withoutOverrides(new NullMessageSerializer());
        $metadata = $this->createMetadataResolver($this->createNullMetadataProvider());
        $transports = $this->createTransportResolver([], [
            FindTaskQuery::class => ['queries'],
        ]);

        $stampsDecider = StampsDecider::withDefaultsFor(
            Query::class,
            $retryResolver,
            $serializers,
            $metadata,
            transports: $transports,
        );

        $queryBus = new QueryBus($bus, $stampsDecider);

        self::assertSame('result', $queryBus->ask($query));
    }

    public function test_ask_preserves_explicit_transport_stamp(): void
    {
        $query = new FindTaskQuery('123');
        $transportStamp = new TransportNamesStamp(['explicit-query']);
        $handled = new HandledStamp('result', 'handler');
        $envelope = (new Envelope($query))->with($handled);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->with(
                $query,
                self::callback(function (array $stamps) use ($transportStamp): bool {
                    $transportStamps = array_values(array_filter(
                        $stamps,
                        static fn ($stamp): bool => $stamp instanceof TransportNamesStamp,
                    ));

                    self::assertSame([$transportStamp], $transportStamps);

                    return true;
                })
            )
            ->willReturn($envelope);

        $retryResolver = RetryPolicyResolver::withoutOverrides();
        $serializers = MessageSerializerResolver::withoutOverrides(new NullMessageSerializer());
        $metadata = $this->createMetadataResolver($this->createNullMetadataProvider());
        $transports = $this->createTransportResolver([], [
            FindTaskQuery::class => ['queries'],
        ]);

        $stampsDecider = StampsDecider::withDefaultsFor(
            Query::class,
            $retryResolver,
            $serializers,
            $metadata,
            transports: $transports,
        );

        $queryBus = new QueryBus($bus, $stampsDecider);

        self::assertSame('result', $queryBus->ask($query, $transportStamp));
    }

    public function test_ask_uses_type_default_serializer_when_no_override(): void
    {
        $query = new FindTaskQuery('123');

        $typeSerializer = $this->createMock(MessageSerializer::class);
        $typeSerializer->expects(self::once())
            ->method('getStamp')
            ->with($query, DispatchMode::SYNC)
            ->willReturn(null);

        $globalSerializer = $this->createMock(MessageSerializer::class);
        $globalSerializer->expects(self::never())->method('getStamp');

        $serializers = $this->createSerializerResolver($globalSerializer, $typeSerializer);

        $metadata = $this->createMetadataResolver($this->createNullMetadataProvider());

        $handled = new HandledStamp('result', 'handler');
        $envelope = (new Envelope($query))->with($handled);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->with($query, [])
            ->willReturn($envelope);

        $retryResolver = RetryPolicyResolver::withoutOverrides();
        $stampsDecider = StampsDecider::withDefaultsFor(Query::class, $retryResolver, $serializers, $metadata);

        $queryBus = new QueryBus($bus, $stampsDecider);

        self::assertSame('result', $queryBus->ask($query));
    }

    public function test_ask_falls_back_to_global_default_serializer(): void
    {
        $query = new FindTaskQuery('123');

        $globalSerializer = $this->createMock(MessageSerializer::class);
        $globalSerializer->expects(self::once())
            ->method('getStamp')
            ->with($query, DispatchMode::SYNC)
            ->willReturn(null);

        $serializers = $this->createSerializerResolver($globalSerializer, $globalSerializer);

        $metadata = $this->createMetadataResolver($this->createNullMetadataProvider());

        $handled = new HandledStamp('result', 'handler');
        $envelope = (new Envelope($query))->with($handled);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->with($query, [])
            ->willReturn($envelope);

        $retryResolver = RetryPolicyResolver::withoutOverrides();
        $stampsDecider = StampsDecider::withDefaultsFor(Query::class, $retryResolver, $serializers, $metadata);

        $queryBus = new QueryBus($bus, $stampsDecider);

        self::assertSame('result', $queryBus->ask($query));
    }

    public function test_ask_skips_null_serializer_stamp(): void
    {
        $query = new FindTaskQuery('123');
        $retryStamp = new DummyStamp('retry');

        $policy = $this->createMock(RetryPolicy::class);
        $policy->expects(self::once())
            ->method('getStamps')
            ->with($query, DispatchMode::SYNC)
            ->willReturn([$retryStamp]);

        $serializer = $this->createMock(MessageSerializer::class);
        $serializer->expects(self::once())
            ->method('getStamp')
            ->with($query, DispatchMode::SYNC)
            ->willReturn(null);

        $metadata = $this->createMetadataResolver($this->createNullMetadataProvider());

        $handled = new HandledStamp('result', 'handler');
        $envelope = (new Envelope($query))->with($handled);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->with(
                $query,
                self::callback(function (array $stamps) use ($retryStamp): bool {
                    self::assertSame([$retryStamp], $stamps);

                    return true;
                })
            )
            ->willReturn($envelope);

        $retryResolver = new RetryPolicyResolver($policy, new ServiceLocator([]));
        $serializers = $this->createSerializerResolver(new NullMessageSerializer(), null, [
            FindTaskQuery::class => $serializer,
        ]);
        $stampsDecider = StampsDecider::withDefaultsFor(Query::class, $retryResolver, $serializers, $metadata);

        $queryBus = new QueryBus($bus, $stampsDecider);

        self::assertSame('result', $queryBus->ask($query));
    }

    public function test_ask_uses_custom_stamps_decider_when_provided(): void
    {
        $query = new FindTaskQuery('123');
        $customStamp = new DummyStamp('custom');

        $handled = new HandledStamp('value', 'handler');
        $envelope = (new Envelope($query))->with($handled);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->with(
                $query,
                self::callback(static function (array $stamps) use ($customStamp): bool {
                    self::assertSame([$customStamp], $stamps);

                    return true;
                })
            )
            ->willReturn($envelope);

        $decider = new class($customStamp) implements \SomeWork\CqrsBundle\Support\StampDecider {
            public function __construct(private readonly DummyStamp $stamp)
            {
            }

            public function decide(object $message, DispatchMode $mode, array $stamps): array
            {
                $stamps[] = $this->stamp;

                return $stamps;
            }
        };

        $queryBus = new QueryBus(
            $bus,
            new StampsDecider([$decider]),
        );

        self::assertSame('value', $queryBus->ask($query));
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

    private function createNullMetadataProvider(): MessageMetadataProvider
    {
        return new class implements MessageMetadataProvider {
            public function getStamp(object $message, DispatchMode $mode): ?MessageMetadataStamp
            {
                return null;
            }
        };
    }

    /**
     * @param array<class-string, MessageMetadataProvider> $map
     */
    private function createMetadataResolver(
        MessageMetadataProvider $global,
        ?MessageMetadataProvider $type = null,
        array $map = []
    ): MessageMetadataProviderResolver {
        $type ??= $global;

        $services = [
            MessageMetadataProviderResolver::GLOBAL_DEFAULT_KEY => static fn (): MessageMetadataProvider => $global,
            MessageMetadataProviderResolver::TYPE_DEFAULT_KEY => static fn (): MessageMetadataProvider => $type,
        ];

        foreach ($map as $class => $provider) {
            $services[$class] = static fn (): MessageMetadataProvider => $provider;
        }

        return new MessageMetadataProviderResolver(new ServiceLocator($services));
    }
}
