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

use function sprintf;

#[CoversClass(ValidateHandlerCountPass::class)]
final class ValidateHandlerCountPassTest extends TestCase
{
    public function test_does_nothing_when_parameter_is_missing(): void
    {
        $this->expectNotToPerformAssertions();

        (new ValidateHandlerCountPass())->process(new ContainerBuilder());
    }

    public function test_accepts_one_handler_per_command_and_query(): void
    {
        $this->expectNotToPerformAssertions();

        $this->process([
            'command' => [self::entry('command', CreateTaskCommand::class, CreateTaskHandler::class, 'handler.create_task', 'bus.commands')],
            'query' => [self::entry('query', FindTaskQuery::class, FindTaskHandler::class, 'handler.find_task', 'bus.queries')],
        ]);
    }

    public function test_same_handler_on_sync_and_async_bus_counts_once(): void
    {
        $this->expectNotToPerformAssertions();

        $this->process([
            'command' => [
                self::entry('command', CreateTaskCommand::class, CreateTaskHandler::class, 'handler.create_task', 'bus.commands'),
                self::entry('command', CreateTaskCommand::class, CreateTaskHandler::class, 'handler.create_task', 'bus.commands_async'),
            ],
        ]);
    }

    public function test_different_handlers_on_different_buses_are_allowed(): void
    {
        $this->expectNotToPerformAssertions();

        $this->process([
            'command' => [
                self::entry('command', CreateTaskCommand::class, CreateTaskHandler::class, 'handler.create_task', 'bus.commands'),
                self::entry('command', CreateTaskCommand::class, ListTasksHandler::class, 'handler.other', 'bus.commands_async'),
            ],
        ]);
    }

    public function test_events_may_have_many_handlers(): void
    {
        $this->expectNotToPerformAssertions();

        $this->process([
            'event' => [
                self::entry('event', TaskCreatedEvent::class, TaskNotificationHandler::class, 'handler.notify', 'bus.events'),
                self::entry('event', TaskCreatedEvent::class, ListTasksHandler::class, 'handler.project', 'bus.events'),
            ],
        ]);
    }

    public function test_throws_when_command_has_two_handlers_on_the_same_bus(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(sprintf('Command %s has 2 handlers on bus "bus.commands": handler.create_task, handler.duplicate.', CreateTaskCommand::class));

        $this->process([
            'command' => [
                self::entry('command', CreateTaskCommand::class, CreateTaskHandler::class, 'handler.create_task', 'bus.commands'),
                self::entry('command', CreateTaskCommand::class, ListTasksHandler::class, 'handler.duplicate', 'bus.commands'),
            ],
        ]);
    }

    public function test_throws_when_query_has_two_handlers_without_explicit_bus(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(sprintf('Query %s has 2 handlers: handler.a, handler.b.', FindTaskQuery::class));

        $this->process([
            'query' => [
                self::entry('query', FindTaskQuery::class, FindTaskHandler::class, 'handler.a', null),
                self::entry('query', FindTaskQuery::class, ListTasksHandler::class, 'handler.b', null),
            ],
        ]);
    }

    public function test_a_handler_without_bus_competes_with_the_handlers_of_every_bus(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(sprintf('Command %s has 2 handlers on bus "bus.commands": handler.create_task, handler.plain_messenger.', CreateTaskCommand::class));

        $this->process([
            'command' => [
                self::entry('command', CreateTaskCommand::class, CreateTaskHandler::class, 'handler.create_task', 'bus.commands'),
                // e.g. #[AsMessageHandler] on __invoke(CreateTaskCommand): Messenger puts it on every bus.
                self::entry('command', CreateTaskCommand::class, ListTasksHandler::class, 'handler.plain_messenger', null),
            ],
        ]);
    }

    public function test_the_same_service_with_and_without_bus_counts_once(): void
    {
        $this->expectNotToPerformAssertions();

        $this->process([
            'command' => [
                self::entry('command', CreateTaskCommand::class, CreateTaskHandler::class, 'handler.create_task', 'bus.commands'),
                self::entry('command', CreateTaskCommand::class, CreateTaskHandler::class, 'handler.create_task', null),
            ],
        ]);
    }

    public function test_reports_all_violations_at_once(): void
    {
        try {
            $this->process([
                'command' => [
                    self::entry('command', CreateTaskCommand::class, CreateTaskHandler::class, 'handler.a', 'bus'),
                    self::entry('command', CreateTaskCommand::class, ListTasksHandler::class, 'handler.b', 'bus'),
                ],
                'query' => [
                    self::entry('query', FindTaskQuery::class, FindTaskHandler::class, 'handler.c', 'bus'),
                    self::entry('query', FindTaskQuery::class, ListTasksHandler::class, 'handler.d', 'bus'),
                ],
            ]);
            self::fail('Expected a LogicException.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('Command '.CreateTaskCommand::class, $exception->getMessage());
            self::assertStringContainsString('Query '.FindTaskQuery::class, $exception->getMessage());
        }
    }

    /**
     * @param array<string, list<array<string, string|null>>> $metadata
     */
    private function process(array $metadata): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('somework_cqrs.handler_metadata', $metadata + ['command' => [], 'query' => [], 'event' => []]);

        (new ValidateHandlerCountPass())->process($container);
    }

    /**
     * @return array{type: string, message: string, handler_class: string, service_id: string, bus: string|null}
     */
    private static function entry(string $type, string $message, string $handlerClass, string $serviceId, ?string $bus): array
    {
        return [
            'type' => $type,
            'message' => $message,
            'handler_class' => $handlerClass,
            'service_id' => $serviceId,
            'bus' => $bus,
        ];
    }
}
