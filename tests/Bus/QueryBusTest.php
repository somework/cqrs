<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Bus;

use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Bus\QueryBus;
use SomeWork\CqrsBundle\Contract\MessageSerializer;
use SomeWork\CqrsBundle\Contract\RetryPolicy;
use SomeWork\CqrsBundle\Support\MessageSerializerResolver;
use SomeWork\CqrsBundle\Support\NullMessageSerializer;
use SomeWork\CqrsBundle\Support\RetryPolicyResolver;
use SomeWork\CqrsBundle\Tests\Fixture\DummyStamp;
use SomeWork\CqrsBundle\Tests\Fixture\Message\FindTaskQuery;
use SomeWork\CqrsBundle\Tests\Fixture\Message\RetryAwareMessage;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\SerializerStamp;

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

        $queryBus = new QueryBus($bus, RetryPolicyResolver::withoutOverrides(), MessageSerializerResolver::withoutOverrides());

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

        $queryBus = new QueryBus($bus, RetryPolicyResolver::withoutOverrides(), MessageSerializerResolver::withoutOverrides());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Query was not handled by any handler.');

        $queryBus->ask($query);
    }

    public function test_ask_merges_supplied_stamps_with_retry_and_serializer(): void
    {
        $query = new FindTaskQuery('123');
        $userStamp = new DummyStamp('user');
        $retryStamp = new DummyStamp('retry');
        $serializerStamp = new SerializerStamp(['format' => 'json']);

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

        $handled = new HandledStamp('result', 'handler');
        $envelope = (new Envelope($query))->with($handled);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->with(
                $query,
                self::callback(function (array $stamps) use ($userStamp, $retryStamp, $serializerStamp): bool {
                    self::assertSame([$userStamp, $retryStamp, $serializerStamp], $stamps);

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

        $queryBus = new QueryBus($bus, $resolver, $serializers);

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

        $queryBus = new QueryBus($bus, $resolver, MessageSerializerResolver::withoutOverrides(new NullMessageSerializer()));

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

        $handled = new HandledStamp('result', 'handler');
        $envelope = (new Envelope($query))->with($handled);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->with($query, [])
            ->willReturn($envelope);

        $queryBus = new QueryBus($bus, RetryPolicyResolver::withoutOverrides(), $serializers);

        self::assertSame('result', $queryBus->ask($query));
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

        $handled = new HandledStamp('result', 'handler');
        $envelope = (new Envelope($query))->with($handled);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->with($query, [])
            ->willReturn($envelope);

        $queryBus = new QueryBus($bus, RetryPolicyResolver::withoutOverrides(), $serializers);

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

        $handled = new HandledStamp('result', 'handler');
        $envelope = (new Envelope($query))->with($handled);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->with($query, [])
            ->willReturn($envelope);

        $queryBus = new QueryBus($bus, RetryPolicyResolver::withoutOverrides(), $serializers);

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

        $queryBus = new QueryBus($bus, $retryResolver, $serializers);

        self::assertSame('result', $queryBus->ask($query));
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
