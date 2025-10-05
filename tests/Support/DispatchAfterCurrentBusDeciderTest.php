<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Support;

use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Support\DispatchAfterCurrentBusDecider;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskCreatedEvent;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class DispatchAfterCurrentBusDeciderTest extends TestCase
{
    public function test_defaults_enable_dispatch_after_current_bus(): void
    {
        $decider = DispatchAfterCurrentBusDecider::defaults();

        self::assertTrue($decider->shouldDefer(new CreateTaskCommand('1', 'Test')));
        self::assertTrue($decider->shouldDefer(new TaskCreatedEvent('1')));
    }

    public function test_map_overrides_are_respected(): void
    {
        $decider = new DispatchAfterCurrentBusDecider(
            true,
            new ServiceLocator([
                CreateTaskCommand::class => static fn (): bool => false,
            ]),
            false,
            new ServiceLocator([
                TaskCreatedEvent::class => static fn (): bool => true,
            ]),
        );

        self::assertFalse($decider->shouldDefer(new CreateTaskCommand('1', 'Test')));
        self::assertTrue($decider->shouldDefer(new TaskCreatedEvent('1')));
        self::assertFalse($decider->shouldDefer(new \stdClass()));
    }
}
