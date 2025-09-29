<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Bus;

use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\QueryBus;
use SomeWork\CqrsBundle\Contract\Query;
use SomeWork\CqrsBundle\Support\NullMessageSerializer;
use SomeWork\CqrsBundle\Support\NullRetryPolicy;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

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
}
