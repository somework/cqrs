<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Testing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Contract\Query;
use SomeWork\CqrsBundle\Testing\FakeQueryBus;
use SomeWork\CqrsBundle\Testing\RecordsBusDispatches;
use Symfony\Component\Messenger\Stamp\DelayStamp;

#[CoversClass(FakeQueryBus::class)]
final class FakeQueryBusTest extends TestCase
{
    public function test_implements_records_bus_dispatches(): void
    {
        $bus = new FakeQueryBus();

        /* @phpstan-ignore staticMethod.alreadyNarrowedType */
        self::assertInstanceOf(RecordsBusDispatches::class, $bus);
    }

    public function test_ask_records_message_and_stamps(): void
    {
        $bus = new FakeQueryBus();
        $query = new class implements Query {};
        $stamp = new DelayStamp(1000);

        $bus->ask($query, $stamp);

        $dispatched = $bus->getDispatched();
        self::assertCount(1, $dispatched);
        self::assertSame($query, $dispatched[0]['message']);
        /* @phpstan-ignore offsetAccess.notFound */
        self::assertSame([$stamp], $dispatched[0]['stamps']);
    }

    public function test_ask_returns_null_by_default(): void
    {
        $bus = new FakeQueryBus();
        $query = new class implements Query {};

        self::assertNull($bus->ask($query));
    }

    public function test_will_return_sets_default_result(): void
    {
        $bus = new FakeQueryBus();
        $query = new class implements Query {};

        $bus->willReturn(['id' => 1, 'name' => 'Test']);

        $result = $bus->ask($query);

        self::assertSame(['id' => 1, 'name' => 'Test'], $result);
    }

    public function test_will_return_for_sets_per_class_result(): void
    {
        $bus = new FakeQueryBus();
        $query = new class implements Query {};

        $bus->willReturnFor($query::class, 'specific-result');

        $result = $bus->ask($query);

        self::assertSame('specific-result', $result);
    }

    public function test_will_return_for_takes_precedence_over_default(): void
    {
        $bus = new FakeQueryBus();
        $query = new class implements Query {};

        $bus->willReturn('default');
        $bus->willReturnFor($query::class, 'specific');

        self::assertSame('specific', $bus->ask($query));
    }

    public function test_different_query_classes_return_different_results(): void
    {
        $bus = new FakeQueryBus();
        $query1 = new class implements Query {};
        $query2 = new class implements Query {};

        $bus->willReturnFor($query1::class, 'result-1');
        $bus->willReturnFor($query2::class, 'result-2');

        self::assertSame('result-1', $bus->ask($query1));
        self::assertSame('result-2', $bus->ask($query2));
    }

    public function test_unregistered_class_falls_back_to_default(): void
    {
        $bus = new FakeQueryBus();
        $registeredQuery = new class implements Query {};
        $unregisteredQuery = new class implements Query {};

        $bus->willReturn('fallback');
        $bus->willReturnFor($registeredQuery::class, 'specific');

        self::assertSame('fallback', $bus->ask($unregisteredQuery));
    }

    public function test_get_dispatched_returns_empty_array_initially(): void
    {
        $bus = new FakeQueryBus();

        self::assertSame([], $bus->getDispatched());
    }

    public function test_reset_clears_dispatched_and_configured_returns(): void
    {
        $bus = new FakeQueryBus();
        $query = new class implements Query {};

        $bus->willReturn('default');
        $bus->willReturnFor($query::class, 'specific');
        $bus->ask($query);
        $bus->reset();

        self::assertSame([], $bus->getDispatched());
        self::assertNull($bus->ask($query));
    }

    public function test_multiple_asks_recorded_in_chronological_order(): void
    {
        $bus = new FakeQueryBus();
        $query1 = new class implements Query {};
        $query2 = new class implements Query {};
        $query3 = new class implements Query {};

        $bus->ask($query1);
        $bus->ask($query2);
        $bus->ask($query3);

        $dispatched = $bus->getDispatched();
        self::assertCount(3, $dispatched);
        self::assertSame($query1, $dispatched[0]['message']);
        self::assertSame($query2, $dispatched[1]['message']);
        self::assertSame($query3, $dispatched[2]['message']);
    }

    public function test_ask_without_stamps_records_empty_stamps_array(): void
    {
        $bus = new FakeQueryBus();
        $query = new class implements Query {};

        $bus->ask($query);

        $dispatched = $bus->getDispatched();
        /* @phpstan-ignore offsetAccess.notFound */
        self::assertSame([], $dispatched[0]['stamps']);
    }

    public function test_ask_with_multiple_stamps(): void
    {
        $bus = new FakeQueryBus();
        $query = new class implements Query {};
        $stamp1 = new DelayStamp(100);
        $stamp2 = new DelayStamp(200);

        $bus->ask($query, $stamp1, $stamp2);

        $dispatched = $bus->getDispatched();
        /* @phpstan-ignore offsetAccess.notFound */
        self::assertCount(2, $dispatched[0]['stamps']);
        /* @phpstan-ignore offsetAccess.notFound */
        self::assertSame($stamp1, $dispatched[0]['stamps'][0]);
        /* @phpstan-ignore offsetAccess.notFound */
        self::assertSame($stamp2, $dispatched[0]['stamps'][1]);
    }

    public function test_will_return_overwrites_previous_default(): void
    {
        $bus = new FakeQueryBus();
        $query = new class implements Query {};

        $bus->willReturn('first');
        $bus->willReturn('second');

        self::assertSame('second', $bus->ask($query));
    }

    public function test_will_return_for_overwrites_previous_per_class_result(): void
    {
        $bus = new FakeQueryBus();
        $query = new class implements Query {};

        $bus->willReturnFor($query::class, 'first');
        $bus->willReturnFor($query::class, 'second');

        self::assertSame('second', $bus->ask($query));
    }

    public function test_will_return_accepts_various_types(): void
    {
        $bus = new FakeQueryBus();
        $query = new class implements Query {};

        $bus->willReturn(42);
        self::assertSame(42, $bus->ask($query));

        $bus->willReturn(false);
        self::assertFalse($bus->ask($query));

        $bus->willReturn(['a', 'b']);
        self::assertSame(['a', 'b'], $bus->ask($query));
    }
}
