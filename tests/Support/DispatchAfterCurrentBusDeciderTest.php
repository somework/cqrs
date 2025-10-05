<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Support;

use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\Event;
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

    public function test_map_overrides_support_inheritance_and_interfaces(): void
    {
        $decider = new DispatchAfterCurrentBusDecider(
            false,
            new ServiceLocator([
                DispatchAfterParentCommand::class => static fn (): bool => true,
                DispatchAfterContractCommand::class => static fn (): bool => false,
            ]),
            true,
            new ServiceLocator([
                DispatchAfterMarkerEvent::class => static fn (): bool => false,
            ]),
        );

        self::assertTrue($decider->shouldDefer(new DispatchAfterChildCommand()));
        self::assertFalse($decider->shouldDefer(new DispatchAfterContractImplementation()));
        self::assertFalse($decider->shouldDefer(new DispatchAfterChildEvent()));
    }
}

interface DispatchAfterContractCommand extends Command
{
}

class DispatchAfterContractImplementation implements DispatchAfterContractCommand
{
}

class DispatchAfterParentCommand implements Command
{
}

class DispatchAfterChildCommand extends DispatchAfterParentCommand
{
}

interface DispatchAfterMarkerEvent extends Event
{
}

class DispatchAfterBaseEvent implements Event
{
}

class DispatchAfterChildEvent extends DispatchAfterBaseEvent implements DispatchAfterMarkerEvent
{
}
