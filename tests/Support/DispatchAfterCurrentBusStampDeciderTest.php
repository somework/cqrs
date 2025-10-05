<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Support;

use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Support\DispatchAfterCurrentBusDecider;
use SomeWork\CqrsBundle\Support\DispatchAfterCurrentBusStampDecider;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskCreatedEvent;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

final class DispatchAfterCurrentBusStampDeciderTest extends TestCase
{
    public function test_appends_stamp_when_message_should_defer(): void
    {
        $decider = new DispatchAfterCurrentBusStampDecider(DispatchAfterCurrentBusDecider::defaults());
        $command = new CreateTaskCommand('1', 'Test');

        $stamps = $decider->decide($command, DispatchMode::ASYNC, []);

        self::assertCount(1, $stamps);
        self::assertInstanceOf(DispatchAfterCurrentBusStamp::class, $stamps[0]);
    }

    public function test_removes_stamp_when_override_disables_deferral(): void
    {
        $decider = new DispatchAfterCurrentBusStampDecider(
            new DispatchAfterCurrentBusDecider(
                true,
                new ServiceLocator([]),
                true,
                new ServiceLocator([
                    TaskCreatedEvent::class => static fn (): bool => false,
                ]),
            ),
        );

        $event = new TaskCreatedEvent('1');
        $existingStamps = [new DispatchAfterCurrentBusStamp()];

        $stamps = $decider->decide($event, DispatchMode::ASYNC, $existingStamps);

        self::assertSame([], $stamps);
    }

    public function test_ignores_stamp_for_sync_dispatch(): void
    {
        $decider = new DispatchAfterCurrentBusStampDecider(DispatchAfterCurrentBusDecider::defaults());
        $command = new CreateTaskCommand('1', 'Test');

        $stamps = $decider->decide($command, DispatchMode::SYNC, [new DispatchAfterCurrentBusStamp()]);

        self::assertSame([], $stamps);
    }
}
