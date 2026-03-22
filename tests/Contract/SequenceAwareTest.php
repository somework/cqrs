<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Contract;

use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Contract\SequenceAware;

final class SequenceAwareTest extends TestCase
{
    public function test_stub_event_returns_correct_aggregate_id_and_sequence_number(): void
    {
        $event = new class('order-42', 7) implements Event, SequenceAware {
            public function __construct(
                private readonly string $aggregateId,
                private readonly int $sequenceNumber,
            ) {
            }

            public function getAggregateId(): string
            {
                return $this->aggregateId;
            }

            public function getSequenceNumber(): int
            {
                return $this->sequenceNumber;
            }
        };

        self::assertSame('order-42', $event->getAggregateId());
        self::assertSame(7, $event->getSequenceNumber());
    }

    public function test_interface_declares_expected_methods(): void
    {
        $reflection = new \ReflectionClass(SequenceAware::class);

        self::assertTrue($reflection->isInterface());
        self::assertTrue($reflection->hasMethod('getAggregateId'));
        self::assertTrue($reflection->hasMethod('getSequenceNumber'));

        $getAggregateId = $reflection->getMethod('getAggregateId');
        $aggregateIdReturnType = $getAggregateId->getReturnType();
        self::assertInstanceOf(\ReflectionNamedType::class, $aggregateIdReturnType);
        self::assertSame('string', $aggregateIdReturnType->getName());

        $getSequenceNumber = $reflection->getMethod('getSequenceNumber');
        $sequenceNumberReturnType = $getSequenceNumber->getReturnType();
        self::assertInstanceOf(\ReflectionNamedType::class, $sequenceNumberReturnType);
        self::assertSame('int', $sequenceNumberReturnType->getName());
    }

    public function test_methods_accept_no_parameters(): void
    {
        $reflection = new \ReflectionClass(SequenceAware::class);

        self::assertSame(0, $reflection->getMethod('getAggregateId')->getNumberOfParameters());
        self::assertSame(0, $reflection->getMethod('getSequenceNumber')->getNumberOfParameters());
    }

    public function test_zero_sequence_number_is_valid(): void
    {
        $event = new class('agg-1', 0) implements Event, SequenceAware {
            public function __construct(
                private readonly string $aggregateId,
                private readonly int $sequenceNumber,
            ) {
            }

            public function getAggregateId(): string
            {
                return $this->aggregateId;
            }

            public function getSequenceNumber(): int
            {
                return $this->sequenceNumber;
            }
        };

        self::assertSame(0, $event->getSequenceNumber());
        self::assertSame('agg-1', $event->getAggregateId());
    }

    public function test_interface_does_not_extend_event(): void
    {
        $reflection = new \ReflectionClass(SequenceAware::class);

        self::assertNotContains(Event::class, array_map(
            static fn (\ReflectionClass $r): string => $r->getName(),
            $reflection->getInterfaces(),
        ));
    }
}
