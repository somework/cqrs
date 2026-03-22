<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\DependencyInjection\Compiler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\ValidateHandlerCountPass;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\CreateTaskHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\FindTaskHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\ListTasksHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\TaskNotificationHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\FindTaskQuery;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskCreatedEvent;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(ValidateHandlerCountPass::class)]
final class ValidateHandlerCountPassTest extends TestCase
{
    public function test_does_nothing_when_parameter_is_missing(): void
    {
        $container = new ContainerBuilder();

        (new ValidateHandlerCountPass())->process($container);

        // No exception thrown — pass succeeds
        $this->addToAssertionCount(1);
    }

    public function test_does_nothing_when_metadata_is_empty_arrays(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('somework_cqrs.handler_metadata', [
            'command' => [],
            'query' => [],
            'event' => [],
        ]);

        (new ValidateHandlerCountPass())->process($container);

        $this->addToAssertionCount(1);
    }

    public function test_succeeds_when_each_command_has_exactly_one_handler(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('somework_cqrs.handler_metadata', [
            'command' => [
                ['type' => 'command', 'message' => CreateTaskCommand::class, 'handler_class' => CreateTaskHandler::class, 'service_id' => 'handler.create_task', 'bus' => null],
            ],
            'query' => [],
            'event' => [],
        ]);

        (new ValidateHandlerCountPass())->process($container);

        $this->addToAssertionCount(1);
    }

    public function test_succeeds_when_each_query_has_exactly_one_handler(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('somework_cqrs.handler_metadata', [
            'command' => [],
            'query' => [
                ['type' => 'query', 'message' => FindTaskQuery::class, 'handler_class' => FindTaskHandler::class, 'service_id' => 'handler.find_task', 'bus' => null],
            ],
            'event' => [],
        ]);

        (new ValidateHandlerCountPass())->process($container);

        $this->addToAssertionCount(1);
    }

    public function test_throws_when_command_has_zero_handlers(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('somework_cqrs.handler_metadata', [
            'command' => [],
            'query' => [],
            'event' => [],
        ]);
        $container->setParameter('somework_cqrs.discovered_messages', [
            'command' => [CreateTaskCommand::class],
            'query' => [],
            'event' => [],
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/'.preg_quote(CreateTaskCommand::class, '/').'/');
        $this->expectExceptionMessageMatches('/has no handler/');

        (new ValidateHandlerCountPass())->process($container);
    }

    public function test_throws_when_command_has_two_handlers(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('somework_cqrs.handler_metadata', [
            'command' => [
                ['type' => 'command', 'message' => CreateTaskCommand::class, 'handler_class' => CreateTaskHandler::class, 'service_id' => 'handler.create_task', 'bus' => null],
                ['type' => 'command', 'message' => CreateTaskCommand::class, 'handler_class' => TaskNotificationHandler::class, 'service_id' => 'handler.notification', 'bus' => null],
            ],
            'query' => [],
            'event' => [],
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/'.preg_quote(CreateTaskCommand::class, '/').'/');
        $this->expectExceptionMessageMatches('/'.preg_quote(CreateTaskHandler::class, '/').'/');
        $this->expectExceptionMessageMatches('/'.preg_quote(TaskNotificationHandler::class, '/').'/');
        $this->expectExceptionMessageMatches('/2 handlers/');

        (new ValidateHandlerCountPass())->process($container);
    }

    public function test_throws_when_query_has_zero_handlers(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('somework_cqrs.handler_metadata', [
            'command' => [],
            'query' => [],
            'event' => [],
        ]);
        $container->setParameter('somework_cqrs.discovered_messages', [
            'command' => [],
            'query' => [FindTaskQuery::class],
            'event' => [],
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/'.preg_quote(FindTaskQuery::class, '/').'/');
        $this->expectExceptionMessageMatches('/has no handler/');

        (new ValidateHandlerCountPass())->process($container);
    }

    public function test_throws_when_query_has_two_handlers(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('somework_cqrs.handler_metadata', [
            'command' => [],
            'query' => [
                ['type' => 'query', 'message' => FindTaskQuery::class, 'handler_class' => FindTaskHandler::class, 'service_id' => 'handler.find_task', 'bus' => null],
                ['type' => 'query', 'message' => FindTaskQuery::class, 'handler_class' => ListTasksHandler::class, 'service_id' => 'handler.list_tasks', 'bus' => null],
            ],
            'event' => [],
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/'.preg_quote(FindTaskQuery::class, '/').'/');
        $this->expectExceptionMessageMatches('/'.preg_quote(FindTaskHandler::class, '/').'/');
        $this->expectExceptionMessageMatches('/'.preg_quote(ListTasksHandler::class, '/').'/');
        $this->expectExceptionMessageMatches('/2 handlers/');

        (new ValidateHandlerCountPass())->process($container);
    }

    public function test_does_not_throw_when_event_has_zero_handlers(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('somework_cqrs.handler_metadata', [
            'command' => [],
            'query' => [],
            'event' => [],
        ]);

        (new ValidateHandlerCountPass())->process($container);

        $this->addToAssertionCount(1);
    }

    public function test_does_not_throw_when_event_has_three_handlers(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('somework_cqrs.handler_metadata', [
            'command' => [],
            'query' => [],
            'event' => [
                ['type' => 'event', 'message' => TaskCreatedEvent::class, 'handler_class' => 'App\\Handler\\EventHandler1', 'service_id' => 'handler.eh1', 'bus' => null],
                ['type' => 'event', 'message' => TaskCreatedEvent::class, 'handler_class' => 'App\\Handler\\EventHandler2', 'service_id' => 'handler.eh2', 'bus' => null],
                ['type' => 'event', 'message' => TaskCreatedEvent::class, 'handler_class' => 'App\\Handler\\EventHandler3', 'service_id' => 'handler.eh3', 'bus' => null],
            ],
        ]);

        (new ValidateHandlerCountPass())->process($container);

        $this->addToAssertionCount(1);
    }

    public function test_collects_all_violations_in_single_exception(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('somework_cqrs.handler_metadata', [
            'command' => [
                ['type' => 'command', 'message' => CreateTaskCommand::class, 'handler_class' => CreateTaskHandler::class, 'service_id' => 'handler.create_task', 'bus' => null],
                ['type' => 'command', 'message' => CreateTaskCommand::class, 'handler_class' => TaskNotificationHandler::class, 'service_id' => 'handler.notification', 'bus' => null],
            ],
            'query' => [
                ['type' => 'query', 'message' => FindTaskQuery::class, 'handler_class' => FindTaskHandler::class, 'service_id' => 'handler.find_task', 'bus' => null],
                ['type' => 'query', 'message' => FindTaskQuery::class, 'handler_class' => ListTasksHandler::class, 'service_id' => 'handler.list_tasks', 'bus' => null],
            ],
            'event' => [],
        ]);

        try {
            (new ValidateHandlerCountPass())->process($container);
            self::fail('Expected LogicException was not thrown');
        } catch (\LogicException $e) {
            $message = $e->getMessage();
            // Both violations must appear in the single exception
            self::assertStringContainsString(CreateTaskCommand::class, $message);
            self::assertStringContainsString(FindTaskQuery::class, $message);
            self::assertStringContainsString('CQRS handler validation failed', $message);
        }
    }

    public function test_collects_zero_handler_and_duplicate_handler_violations_together(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('somework_cqrs.handler_metadata', [
            'command' => [
                ['type' => 'command', 'message' => CreateTaskCommand::class, 'handler_class' => CreateTaskHandler::class, 'service_id' => 'handler.create_task', 'bus' => null],
                ['type' => 'command', 'message' => CreateTaskCommand::class, 'handler_class' => TaskNotificationHandler::class, 'service_id' => 'handler.notification', 'bus' => null],
            ],
            'query' => [],
            'event' => [],
        ]);
        $container->setParameter('somework_cqrs.discovered_messages', [
            'command' => [],
            'query' => [FindTaskQuery::class],
        ]);

        try {
            (new ValidateHandlerCountPass())->process($container);
            self::fail('Expected LogicException was not thrown');
        } catch (\LogicException $e) {
            $message = $e->getMessage();
            // Duplicate command handler violation
            self::assertStringContainsString(CreateTaskCommand::class, $message);
            self::assertStringContainsString('2 handlers', $message);
            // Zero query handler violation
            self::assertStringContainsString(FindTaskQuery::class, $message);
            self::assertStringContainsString('has no handler', $message);
        }
    }

    public function test_skips_gracefully_when_handler_metadata_is_not_array(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('somework_cqrs.handler_metadata', 'invalid');

        (new ValidateHandlerCountPass())->process($container);

        $this->addToAssertionCount(1);
    }

    public function test_skips_gracefully_when_discovered_messages_is_not_array(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('somework_cqrs.handler_metadata', [
            'command' => [],
            'query' => [],
            'event' => [],
        ]);
        $container->setParameter('somework_cqrs.discovered_messages', 'invalid');

        (new ValidateHandlerCountPass())->process($container);

        $this->addToAssertionCount(1);
    }

    public function test_skips_type_when_entries_are_not_array(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('somework_cqrs.handler_metadata', [
            'command' => 'not-an-array',
            'query' => [],
            'event' => [],
        ]);

        (new ValidateHandlerCountPass())->process($container);

        $this->addToAssertionCount(1);
    }

    public function test_skips_discovered_messages_type_when_not_array(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('somework_cqrs.handler_metadata', [
            'command' => [],
            'query' => [],
            'event' => [],
        ]);
        $container->setParameter('somework_cqrs.discovered_messages', [
            'command' => 'not-an-array',
            'query' => [],
        ]);

        (new ValidateHandlerCountPass())->process($container);

        $this->addToAssertionCount(1);
    }

    public function test_no_false_positive_when_discovered_message_has_handler(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('somework_cqrs.handler_metadata', [
            'command' => [
                ['type' => 'command', 'message' => CreateTaskCommand::class, 'handler_class' => CreateTaskHandler::class, 'service_id' => 'handler.create_task', 'bus' => null],
            ],
            'query' => [],
            'event' => [],
        ]);
        $container->setParameter('somework_cqrs.discovered_messages', [
            'command' => [CreateTaskCommand::class],
            'query' => [],
        ]);

        (new ValidateHandlerCountPass())->process($container);

        $this->addToAssertionCount(1);
    }

    public function test_throws_when_command_has_three_handlers(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('somework_cqrs.handler_metadata', [
            'command' => [
                ['type' => 'command', 'message' => CreateTaskCommand::class, 'handler_class' => CreateTaskHandler::class, 'service_id' => 'handler.create_task', 'bus' => null],
                ['type' => 'command', 'message' => CreateTaskCommand::class, 'handler_class' => TaskNotificationHandler::class, 'service_id' => 'handler.notification', 'bus' => null],
                ['type' => 'command', 'message' => CreateTaskCommand::class, 'handler_class' => FindTaskHandler::class, 'service_id' => 'handler.find_task', 'bus' => null],
            ],
            'query' => [],
            'event' => [],
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/3 handlers/');
        $this->expectExceptionMessageMatches('/'.preg_quote(CreateTaskHandler::class, '/').'/');
        $this->expectExceptionMessageMatches('/'.preg_quote(TaskNotificationHandler::class, '/').'/');
        $this->expectExceptionMessageMatches('/'.preg_quote(FindTaskHandler::class, '/').'/');

        (new ValidateHandlerCountPass())->process($container);
    }

    public function test_discovered_messages_with_empty_arrays_causes_no_violation(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('somework_cqrs.handler_metadata', [
            'command' => [],
            'query' => [],
            'event' => [],
        ]);
        $container->setParameter('somework_cqrs.discovered_messages', [
            'command' => [],
            'query' => [],
        ]);

        (new ValidateHandlerCountPass())->process($container);

        $this->addToAssertionCount(1);
    }

    public function test_handles_missing_type_keys_in_metadata(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('somework_cqrs.handler_metadata', []);

        (new ValidateHandlerCountPass())->process($container);

        $this->addToAssertionCount(1);
    }

    public function test_multiple_zero_handler_violations_across_types(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('somework_cqrs.handler_metadata', [
            'command' => [],
            'query' => [],
            'event' => [],
        ]);
        $container->setParameter('somework_cqrs.discovered_messages', [
            'command' => [CreateTaskCommand::class],
            'query' => [FindTaskQuery::class],
        ]);

        try {
            (new ValidateHandlerCountPass())->process($container);
            self::fail('Expected LogicException was not thrown');
        } catch (\LogicException $e) {
            $message = $e->getMessage();
            self::assertStringContainsString(CreateTaskCommand::class, $message);
            self::assertStringContainsString(FindTaskQuery::class, $message);
            self::assertStringContainsString('has no handler', $message);
            // Verify both are "no handler" violations (appears twice)
            self::assertSame(2, substr_count($message, 'has no handler'));
        }
    }
}
