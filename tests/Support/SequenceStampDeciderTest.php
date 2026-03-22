<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Contract\SequenceAware;
use SomeWork\CqrsBundle\Stamp\AggregateSequenceStamp;
use SomeWork\CqrsBundle\Support\MessageTypeAwareStampDecider;
use SomeWork\CqrsBundle\Support\SequenceStampDecider;
use Symfony\Component\Messenger\Stamp\DelayStamp;

use function count;

#[CoversClass(SequenceStampDecider::class)]
final class SequenceStampDeciderTest extends TestCase
{
    private SequenceStampDecider $decider;

    protected function setUp(): void
    {
        $this->decider = new SequenceStampDecider();
    }

    public function test_implements_message_type_aware_stamp_decider(): void
    {
        /* @phpstan-ignore staticMethod.alreadyNarrowedType */
        self::assertInstanceOf(MessageTypeAwareStampDecider::class, $this->decider);
    }

    public function test_message_types_returns_event_class(): void
    {
        self::assertSame([Event::class], $this->decider->messageTypes());
    }

    public function test_decide_appends_stamp_for_sequence_aware_event(): void
    {
        $event = new class implements Event, SequenceAware {
            public function getAggregateId(): string
            {
                return 'order-99';
            }

            public function getSequenceNumber(): int
            {
                return 3;
            }
        };

        $result = $this->decider->decide($event, DispatchMode::SYNC, []);

        self::assertCount(1, $result);
        self::assertInstanceOf(AggregateSequenceStamp::class, $result[0]);
        self::assertSame('order-99', $result[0]->aggregateId);
        self::assertSame(3, $result[0]->sequenceNumber);
        self::assertSame($event::class, $result[0]->aggregateType);
    }

    public function test_decide_returns_stamps_unchanged_for_non_sequence_aware_event(): void
    {
        $event = new class implements Event {};
        $existingStamp = new DelayStamp(1000);
        $stamps = [$existingStamp];

        $result = $this->decider->decide($event, DispatchMode::SYNC, $stamps);

        self::assertSame($stamps, $result);
    }

    public function test_decide_preserves_existing_stamps(): void
    {
        $event = new class implements Event, SequenceAware {
            public function getAggregateId(): string
            {
                return 'order-1';
            }

            public function getSequenceNumber(): int
            {
                return 1;
            }
        };

        $existingStamp = new DelayStamp(500);
        $stamps = [$existingStamp];

        $result = $this->decider->decide($event, DispatchMode::SYNC, $stamps);

        self::assertCount(2, $result);
        self::assertSame($existingStamp, $result[0]);
        self::assertInstanceOf(AggregateSequenceStamp::class, $result[1]);
    }

    public function test_decide_works_identically_for_sync_and_async_modes(): void
    {
        $event = new class implements Event, SequenceAware {
            public function getAggregateId(): string
            {
                return 'order-5';
            }

            public function getSequenceNumber(): int
            {
                return 10;
            }
        };

        $syncResult = $this->decider->decide($event, DispatchMode::SYNC, []);
        $asyncResult = $this->decider->decide($event, DispatchMode::ASYNC, []);

        self::assertCount(1, $syncResult);
        self::assertCount(1, $asyncResult);
        self::assertInstanceOf(AggregateSequenceStamp::class, $syncResult[0]);
        self::assertInstanceOf(AggregateSequenceStamp::class, $asyncResult[0]);
        self::assertSame($syncResult[0]->aggregateId, $asyncResult[0]->aggregateId);
        self::assertSame($syncResult[0]->sequenceNumber, $asyncResult[0]->sequenceNumber);
    }

    public function test_class_is_final(): void
    {
        $reflection = new \ReflectionClass(SequenceStampDecider::class);

        self::assertTrue($reflection->isFinal());
    }

    public function test_decide_returns_empty_array_for_non_sequence_aware_with_no_stamps(): void
    {
        $event = new class implements Event {};

        $result = $this->decider->decide($event, DispatchMode::SYNC, []);

        self::assertSame([], $result);
    }

    public function test_decide_preserves_multiple_existing_stamps(): void
    {
        $event = new class implements Event, SequenceAware {
            public function getAggregateId(): string
            {
                return 'agg-1';
            }

            public function getSequenceNumber(): int
            {
                return 5;
            }
        };

        $delay1 = new DelayStamp(100);
        $delay2 = new DelayStamp(200);

        $result = $this->decider->decide($event, DispatchMode::SYNC, [$delay1, $delay2]);

        self::assertCount(3, $result);
        self::assertSame($delay1, $result[0]);
        self::assertSame($delay2, $result[1]);
        self::assertInstanceOf(AggregateSequenceStamp::class, $result[2]);
    }

    public function test_decide_uses_message_class_as_aggregate_type(): void
    {
        $event = new class implements Event, SequenceAware {
            public function getAggregateId(): string
            {
                return 'agg-1';
            }

            public function getSequenceNumber(): int
            {
                return 1;
            }
        };

        $result = $this->decider->decide($event, DispatchMode::SYNC, []);

        $stamp = $result[0];
        self::assertInstanceOf(AggregateSequenceStamp::class, $stamp);
        self::assertSame($event::class, $stamp->aggregateType);
    }

    public function test_decide_does_not_modify_input_stamps_array(): void
    {
        $event = new class implements Event, SequenceAware {
            public function getAggregateId(): string
            {
                return 'agg-1';
            }

            public function getSequenceNumber(): int
            {
                return 1;
            }
        };

        $original = [new DelayStamp(100)];
        $originalCount = count($original);

        $this->decider->decide($event, DispatchMode::SYNC, $original);

        /* @phpstan-ignore staticMethod.alreadyNarrowedType */
        self::assertCount($originalCount, $original);
    }
}
