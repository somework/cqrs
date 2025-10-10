<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Functional;

use SomeWork\CqrsBundle\Registry\HandlerDescriptor;
use SomeWork\CqrsBundle\Registry\HandlerRegistry;
use SomeWork\CqrsBundle\Tests\Fixture\Kernel\TestKernel;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\FindTaskQuery;
use SomeWork\CqrsBundle\Tests\Fixture\Message\GenerateReportCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\ListTasksQuery;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskCreatedEvent;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class HandlerRegistryKernelTest extends KernelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();
    }

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function test_registry_exposes_command_handlers_with_bus_information(): void
    {
        $registry = static::getContainer()->get(HandlerRegistry::class);

        $commands = $registry->byType('command');

        $actual = array_map(
            static fn (HandlerDescriptor $descriptor): array => [
                $descriptor->messageClass,
                $descriptor->handlerClass,
                $descriptor->bus,
            ],
            $commands,
        );

        usort($actual, static fn (array $left, array $right): int => $left[0] <=> $right[0]);

        self::assertSame(
            [
                [CreateTaskCommand::class, 'SomeWork\\CqrsBundle\\Tests\\Fixture\\Handler\\CreateTaskHandler', 'messenger.bus.commands'],
                [GenerateReportCommand::class, 'SomeWork\\CqrsBundle\\Tests\\Fixture\\Handler\\GenerateReportHandler', 'messenger.bus.commands_async'],
            ],
            $actual,
        );
    }

    public function test_registry_exposes_query_handlers(): void
    {
        $registry = static::getContainer()->get(HandlerRegistry::class);

        $queries = $registry->byType('query');

        $actual = array_map(
            static fn (HandlerDescriptor $descriptor): array => [
                $descriptor->messageClass,
                $descriptor->handlerClass,
                $descriptor->bus,
            ],
            $queries,
        );

        usort($actual, static fn (array $left, array $right): int => $left[0] <=> $right[0]);

        self::assertSame(
            [
                [FindTaskQuery::class, 'SomeWork\\CqrsBundle\\Tests\\Fixture\\Handler\\FindTaskHandler', 'messenger.bus.queries'],
                [ListTasksQuery::class, 'SomeWork\\CqrsBundle\\Tests\\Fixture\\Handler\\ListTasksHandler', 'messenger.bus.queries'],
            ],
            $actual,
        );
    }

    public function test_registry_exposes_event_handlers_across_buses(): void
    {
        $registry = static::getContainer()->get(HandlerRegistry::class);

        $events = $registry->byType('event');

        self::assertCount(2, $events);

        $map = [];
        foreach ($events as $descriptor) {
            $map[$descriptor->handlerClass] = [$descriptor->messageClass, $descriptor->bus];
        }

        self::assertSame(
            [TaskCreatedEvent::class, 'messenger.bus.events'],
            $map['SomeWork\\CqrsBundle\\Tests\\Fixture\\Handler\\TaskNotificationHandler'],
        );
        self::assertSame(
            [TaskCreatedEvent::class, 'messenger.bus.events_async'],
            $map['SomeWork\\CqrsBundle\\Tests\\Fixture\\Handler\\AsyncProjectionHandler'],
        );
    }

    public function test_registry_uses_naming_strategy_for_display_name(): void
    {
        $registry = static::getContainer()->get(HandlerRegistry::class);

        $descriptor = null;
        foreach ($registry->byType('command') as $candidate) {
            if (CreateTaskCommand::class === $candidate->messageClass) {
                $descriptor = $candidate;

                break;
            }
        }

        self::assertNotNull($descriptor);
        self::assertSame('CreateTaskCommand', $registry->getDisplayName($descriptor));
    }
}
