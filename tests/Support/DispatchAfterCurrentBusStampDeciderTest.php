<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Support\DispatchAfterCurrentBusDecider;
use SomeWork\CqrsBundle\Support\DispatchAfterCurrentBusStampDecider;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskCreatedEvent;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

#[CoversClass(DispatchAfterCurrentBusStampDecider::class)]
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

    public function test_keeps_caller_stamp_when_override_disables_deferral(): void
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

        $callerStamp = new DispatchAfterCurrentBusStamp();

        self::assertSame([$callerStamp], $decider->decide(new TaskCreatedEvent('1'), DispatchMode::ASYNC, [$callerStamp]));
        self::assertSame([], $decider->decide(new TaskCreatedEvent('1'), DispatchMode::ASYNC, []));
    }

    public function test_keeps_caller_stamp_for_sync_dispatch(): void
    {
        $decider = new DispatchAfterCurrentBusStampDecider(DispatchAfterCurrentBusDecider::defaults());
        $callerStamp = new DispatchAfterCurrentBusStamp();

        self::assertSame([$callerStamp], $decider->decide(new TaskCreatedEvent('1'), DispatchMode::SYNC, [$callerStamp]));
    }

    public function test_does_not_add_stamp_for_sync_dispatch(): void
    {
        $decider = new DispatchAfterCurrentBusStampDecider(DispatchAfterCurrentBusDecider::defaults());

        self::assertSame([], $decider->decide(new CreateTaskCommand('1', 'Test'), DispatchMode::SYNC, []));
    }

    public function test_does_not_duplicate_caller_stamp_when_deferring(): void
    {
        $decider = new DispatchAfterCurrentBusStampDecider(DispatchAfterCurrentBusDecider::defaults());
        $callerStamp = new DispatchAfterCurrentBusStamp();

        self::assertSame([$callerStamp], $decider->decide(new CreateTaskCommand('1', 'Test'), DispatchMode::ASYNC, [$callerStamp]));
    }
}
