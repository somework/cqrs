<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Stamp;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Stamp\AggregateSequenceStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;

use function sprintf;

use const PHP_INT_MAX;

#[CoversClass(AggregateSequenceStamp::class)]
final class AggregateSequenceStampTest extends TestCase
{
    public function test_constructor_stores_properties(): void
    {
        $stamp = new AggregateSequenceStamp('order-123', 5, 'App\\Order');

        self::assertSame('order-123', $stamp->aggregateId);
        self::assertSame(5, $stamp->sequenceNumber);
        self::assertSame('App\\Order', $stamp->aggregateType);
    }

    public function test_stamp_implements_stamp_interface(): void
    {
        $stamp = new AggregateSequenceStamp('order-123', 5, 'App\\Order');

        /* @phpstan-ignore staticMethod.alreadyNarrowedType */
        self::assertInstanceOf(StampInterface::class, $stamp);
    }

    public function test_empty_aggregate_id_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Aggregate ID cannot be empty.');

        new AggregateSequenceStamp('', 5, 'App\\Order');
    }

    public function test_negative_sequence_number_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sequence number must be non-negative.');

        new AggregateSequenceStamp('order-123', -1, 'App\\Order');
    }

    public function test_zero_sequence_number_is_valid(): void
    {
        $stamp = new AggregateSequenceStamp('order-123', 0, 'App\\Order');

        self::assertSame(0, $stamp->sequenceNumber);
    }

    public function test_class_is_final(): void
    {
        $reflection = new \ReflectionClass(AggregateSequenceStamp::class);

        self::assertTrue($reflection->isFinal());
    }

    public function test_properties_are_readonly(): void
    {
        $reflection = new \ReflectionClass(AggregateSequenceStamp::class);

        foreach (['aggregateId', 'sequenceNumber', 'aggregateType'] as $property) {
            self::assertTrue(
                $reflection->getProperty($property)->isReadOnly(),
                sprintf('Property %s should be readonly', $property),
            );
        }
    }

    public function test_large_sequence_number_is_accepted(): void
    {
        $stamp = new AggregateSequenceStamp('agg-1', PHP_INT_MAX, 'App\\Entity');

        self::assertSame(PHP_INT_MAX, $stamp->sequenceNumber);
    }

    public function test_negative_large_sequence_number_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AggregateSequenceStamp('agg-1', -100, 'App\\Entity');
    }

    public function test_aggregate_type_can_be_empty_string(): void
    {
        $stamp = new AggregateSequenceStamp('agg-1', 1, '');

        self::assertSame('', $stamp->aggregateType);
    }

    public function test_uuid_style_aggregate_id_is_accepted(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $stamp = new AggregateSequenceStamp($uuid, 42, 'App\\Order');

        self::assertSame($uuid, $stamp->aggregateId);
    }
}
