<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Support;

use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Support\StampDecider;
use SomeWork\CqrsBundle\Support\StampsDecider;
use SomeWork\CqrsBundle\Tests\Fixture\DummyStamp;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;

final class StampsDeciderTest extends TestCase
{
    public function test_invokes_registered_deciders_in_order(): void
    {
        $message = new CreateTaskCommand('1', 'Test');
        $initialStamps = [new DummyStamp('base')];

        $first = new class implements StampDecider {
            public function decide(object $message, DispatchMode $mode, array $stamps): array
            {
                $stamps[] = new DummyStamp('first');

                return $stamps;
            }
        };

        $second = new class implements StampDecider {
            public function decide(object $message, DispatchMode $mode, array $stamps): array
            {
                $stamps[] = new DummyStamp('second');

                return $stamps;
            }
        };

        $decider = new StampsDecider([$first, $second]);
        $stamps = $decider->decide($message, DispatchMode::ASYNC, $initialStamps);

        self::assertCount(3, $stamps);
        self::assertSame('base', $stamps[0]->name);
        self::assertSame('first', $stamps[1]->name);
        self::assertSame('second', $stamps[2]->name);
    }
}
