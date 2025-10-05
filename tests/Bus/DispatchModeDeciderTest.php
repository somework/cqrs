<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Bus;

use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Bus\DispatchModeDecider;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskCreatedEvent;

final class DispatchModeDeciderTest extends TestCase
{
    public function test_command_uses_configured_default(): void
    {
        $decider = new DispatchModeDecider(DispatchMode::ASYNC, DispatchMode::SYNC);

        $mode = $decider->resolve(new CreateTaskCommand('id', 'name'), DispatchMode::DEFAULT);

        self::assertSame(DispatchMode::ASYNC, $mode);
    }

    public function test_event_map_overrides_default(): void
    {
        $event = new TaskCreatedEvent('task');
        $decider = new DispatchModeDecider(
            DispatchMode::SYNC,
            DispatchMode::SYNC,
            [],
            [TaskCreatedEvent::class => DispatchMode::ASYNC],
        );

        $mode = $decider->resolve($event, DispatchMode::DEFAULT);

        self::assertSame(DispatchMode::ASYNC, $mode);
    }

    public function test_explicit_mode_takes_precedence(): void
    {
        $decider = new DispatchModeDecider(DispatchMode::ASYNC, DispatchMode::ASYNC);

        $mode = $decider->resolve(new CreateTaskCommand('id', 'name'), DispatchMode::SYNC);

        self::assertSame(DispatchMode::SYNC, $mode);
    }

    public function test_non_cqrs_message_defaults_to_sync(): void
    {
        $decider = new DispatchModeDecider(DispatchMode::ASYNC, DispatchMode::ASYNC);

        $mode = $decider->resolve(new \stdClass(), DispatchMode::DEFAULT);

        self::assertSame(DispatchMode::SYNC, $mode);
    }
}
