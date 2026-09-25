<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Bus;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Bus\DispatchModeDecider;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Tests\Fixture\Message\AsyncTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\AuditLogEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Message\BulkImportCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\HighPriorityEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Message\ImportLegacyDataCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\OrderPlacedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskCreatedEvent;

#[CoversClass(DispatchModeDecider::class)]
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

    public function test_command_interface_override_applies(): void
    {
        $decider = new DispatchModeDecider(
            DispatchMode::SYNC,
            DispatchMode::SYNC,
            [BulkImportCommand::class => DispatchMode::ASYNC],
        );

        $mode = $decider->resolve(new ImportLegacyDataCommand('legacy.csv'), DispatchMode::DEFAULT);

        self::assertSame(DispatchMode::ASYNC, $mode);
    }

    public function test_event_interface_override_applies(): void
    {
        $decider = new DispatchModeDecider(
            DispatchMode::SYNC,
            DispatchMode::SYNC,
            [],
            [AuditLogEvent::class => DispatchMode::ASYNC],
        );

        $mode = $decider->resolve(new OrderPlacedEvent('order-1'), DispatchMode::DEFAULT);

        self::assertSame(DispatchMode::ASYNC, $mode);
    }

    public function test_more_specific_interface_takes_precedence(): void
    {
        $decider = new DispatchModeDecider(
            DispatchMode::SYNC,
            DispatchMode::SYNC,
            [],
            [
                AuditLogEvent::class => DispatchMode::SYNC,
                HighPriorityEvent::class => DispatchMode::ASYNC,
            ],
        );

        $mode = $decider->resolve(new OrderPlacedEvent('order-1'), DispatchMode::DEFAULT);

        self::assertSame(DispatchMode::SYNC, $mode);
    }

    public function test_class_mapping_beats_interface_mapping(): void
    {
        $decider = new DispatchModeDecider(
            DispatchMode::SYNC,
            DispatchMode::SYNC,
            [],
            [
                AuditLogEvent::class => DispatchMode::ASYNC,
                OrderPlacedEvent::class => DispatchMode::SYNC,
            ],
        );

        $mode = $decider->resolve(new OrderPlacedEvent('order-1'), DispatchMode::DEFAULT);

        self::assertSame(DispatchMode::SYNC, $mode);
    }

    public function test_resolve_caches_command_mode_after_first_lookup(): void
    {
        $message = new ImportLegacyDataCommand('legacy.csv');
        $decider = new DispatchModeDecider(
            DispatchMode::SYNC,
            DispatchMode::SYNC,
            [BulkImportCommand::class => DispatchMode::ASYNC],
        );

        $firstResult = $decider->resolve($message, DispatchMode::DEFAULT);
        self::assertSame(DispatchMode::ASYNC, $firstResult);

        $commandCache = new ReflectionProperty($decider, 'commandModeCache');
        $commandCache->setAccessible(true);
        $cachedModes = $commandCache->getValue($decider);

        self::assertIsArray($cachedModes);
        self::assertArrayHasKey($message::class, $cachedModes);
        self::assertSame(DispatchMode::ASYNC, $cachedModes[$message::class]);

        // The cached mode is returned without another lookup.
        $commandCache->setValue($decider, [$message::class => DispatchMode::SYNC]);

        self::assertSame(DispatchMode::SYNC, $decider->resolve($message, DispatchMode::DEFAULT));
    }

    public function test_reset_clears_all_caches(): void
    {
        $command = new ImportLegacyDataCommand('legacy.csv');
        $event = new OrderPlacedEvent('order-1');

        $decider = new DispatchModeDecider(
            DispatchMode::SYNC,
            DispatchMode::SYNC,
            [BulkImportCommand::class => DispatchMode::ASYNC],
            [AuditLogEvent::class => DispatchMode::ASYNC],
        );

        $decider->resolve($command, DispatchMode::DEFAULT);
        $decider->resolve($event, DispatchMode::DEFAULT);

        $commandCache = new ReflectionProperty($decider, 'commandModeCache');
        $eventCache = new ReflectionProperty($decider, 'eventModeCache');

        self::assertNotEmpty($commandCache->getValue($decider));
        self::assertNotEmpty($eventCache->getValue($decider));

        $decider->reset();

        self::assertSame([], $commandCache->getValue($decider));
        self::assertSame([], $eventCache->getValue($decider));
    }

    public function test_reset_allows_re_resolution_with_fresh_state(): void
    {
        $command = new CreateTaskCommand('id', 'name');
        $decider = new DispatchModeDecider(DispatchMode::ASYNC, DispatchMode::SYNC);

        $first = $decider->resolve($command, DispatchMode::DEFAULT);
        self::assertSame(DispatchMode::ASYNC, $first);

        $decider->reset();

        $second = $decider->resolve($command, DispatchMode::DEFAULT);
        self::assertSame(DispatchMode::ASYNC, $second);
    }

    public function test_asynchronous_attribute_makes_default_dispatch_async(): void
    {
        $decider = DispatchModeDecider::syncDefaults();

        self::assertSame(DispatchMode::ASYNC, $decider->resolve(new AsyncTaskCommand('1'), DispatchMode::DEFAULT));
    }

    public function test_explicit_mode_beats_asynchronous_attribute(): void
    {
        $decider = DispatchModeDecider::syncDefaults();

        self::assertSame(DispatchMode::SYNC, $decider->resolve(new AsyncTaskCommand('1'), DispatchMode::SYNC));
    }

    public function test_exact_class_mapping_beats_asynchronous_attribute(): void
    {
        $decider = new DispatchModeDecider(DispatchMode::SYNC, DispatchMode::SYNC, [AsyncTaskCommand::class => DispatchMode::SYNC]);

        self::assertSame(DispatchMode::SYNC, $decider->resolve(new AsyncTaskCommand('1'), DispatchMode::DEFAULT));
    }

    public function test_asynchronous_attribute_beats_interface_mapping(): void
    {
        $decider = new DispatchModeDecider(DispatchMode::SYNC, DispatchMode::SYNC, [Command::class => DispatchMode::SYNC]);

        self::assertSame(DispatchMode::ASYNC, $decider->resolve(new AsyncTaskCommand('1'), DispatchMode::DEFAULT));
    }
}
