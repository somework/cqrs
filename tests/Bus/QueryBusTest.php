<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Bus;

use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Bus\QueryBus;
use SomeWork\CqrsBundle\Contract\MessageSerializer;
use SomeWork\CqrsBundle\Contract\RetryPolicy;
use SomeWork\CqrsBundle\Support\NullMessageSerializer;
use SomeWork\CqrsBundle\Support\RetryPolicyResolver;
use SomeWork\CqrsBundle\Tests\Fixture\DummyStamp;
use SomeWork\CqrsBundle\Tests\Fixture\Message\FindTaskQuery;
use SomeWork\CqrsBundle\Tests\Fixture\Message\RetryAwareMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\SerializerStamp;
use Symfony\Component\DependencyInjection\ServiceLocator;

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

        $queryBus = new QueryBus($bus, RetryPolicyResolver::withoutOverrides(), new NullMessageSerializer());

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

        $queryBus = new QueryBus($bus, RetryPolicyResolver::withoutOverrides(), new NullMessageSerializer());

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

        $queryBus = new QueryBus($bus, $resolver, $serializer);

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

        $queryBus = new QueryBus($bus, $resolver, new NullMessageSerializer());

        self::assertSame('result', $queryBus->ask($query));
    }
}
