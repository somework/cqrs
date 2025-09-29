<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Bus;

use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Bus\QueryBus;
use SomeWork\CqrsBundle\Contract\MessageSerializer;
use SomeWork\CqrsBundle\Contract\Query;
use SomeWork\CqrsBundle\Contract\RetryPolicy;
use SomeWork\CqrsBundle\Support\NullMessageSerializer;
use SomeWork\CqrsBundle\Support\NullRetryPolicy;
use SomeWork\CqrsBundle\Tests\Fixture\DummyStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\SerializerStamp;

final class QueryBusTest extends TestCase
{
    public function test_ask_returns_handled_result(): void
    {
        $query = $this->createStub(Query::class);

        $handled = new HandledStamp('value', 'handler');
        $envelope = (new Envelope($query))->with($handled);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->with($query, [])
            ->willReturn($envelope);

        $queryBus = new QueryBus($bus, new NullRetryPolicy(), new NullMessageSerializer());

        self::assertSame('value', $queryBus->ask($query));
    }

    public function test_ask_without_result_throws_exception(): void
    {
        $query = $this->createStub(Query::class);
        $envelope = new Envelope($query);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->with($query, [])
            ->willReturn($envelope);

        $queryBus = new QueryBus($bus, new NullRetryPolicy(), new NullMessageSerializer());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Query was not handled by any handler.');

        $queryBus->ask($query);
    }

    public function test_ask_merges_supplied_stamps_with_retry_and_serializer(): void
    {
        $query = $this->createStub(Query::class);
        $userStamp = new DummyStamp('user');
        $retryStamp = new DummyStamp('retry');
        $serializerStamp = new SerializerStamp(['format' => 'json']);

        $policy = $this->createMock(RetryPolicy::class);
        $policy->expects(self::once())
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

        $queryBus = new QueryBus($bus, $policy, $serializer);

        self::assertSame('result', $queryBus->ask($query, $userStamp));
    }
}
