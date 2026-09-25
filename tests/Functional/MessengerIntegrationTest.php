<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\CoversNothing;
use SomeWork\CqrsBundle\Bus\CommandBus;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Bus\EventBus;
use SomeWork\CqrsBundle\Bus\QueryBus;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\CreateTaskHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\GenerateReportHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\ImportTasksHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\TaskImportedHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Kernel\TestKernel;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\FindTaskQuery;
use SomeWork\CqrsBundle\Tests\Fixture\Message\GenerateReportCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\ImportTasksCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\ListTasksQuery;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskCreatedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Message\UnobservedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Service\TaskRecorder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use function assert;

/**
 * The test kernel has no transports: the async buses handle messages inline, which keeps these
 * tests focused on bus selection and wiring. AsyncTransportRoundTripTest covers real transports.
 */
#[CoversNothing]
final class MessengerIntegrationTest extends KernelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();
        $recorder = static::getContainer()->get(TaskRecorder::class);
        assert($recorder instanceof TaskRecorder);
        $recorder->reset();
    }

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function test_command_bus_dispatches_sync_command(): void
    {
        $commandBus = static::getContainer()->get(CommandBus::class);
        assert($commandBus instanceof CommandBus);
        $recorder = static::getContainer()->get(TaskRecorder::class);
        assert($recorder instanceof TaskRecorder);

        $commandBus->dispatch(new CreateTaskCommand('task-1', 'Write docs'));

        self::assertSame('Write docs', $recorder->task('task-1'));
    }

    public function test_async_mode_dispatches_commands_on_the_async_bus(): void
    {
        $commandBus = static::getContainer()->get(CommandBus::class);
        assert($commandBus instanceof CommandBus);
        $recorder = static::getContainer()->get(TaskRecorder::class);
        assert($recorder instanceof TaskRecorder);

        $commandBus->dispatch(new GenerateReportCommand('report-1'), DispatchMode::ASYNC);

        self::assertTrue($recorder->hasReport('report-1'));
    }

    public function test_event_bus_dispatches_on_the_sync_and_async_buses(): void
    {
        $eventBus = static::getContainer()->get(EventBus::class);
        assert($eventBus instanceof EventBus);
        $recorder = static::getContainer()->get(TaskRecorder::class);
        assert($recorder instanceof TaskRecorder);

        $eventBus->dispatch(new TaskCreatedEvent('sync-task'));
        $eventBus->dispatch(new TaskCreatedEvent('async-task'), DispatchMode::ASYNC);

        self::assertContains('sync-task', $recorder->events());
        self::assertContains('async-task', $recorder->asyncEvents());
    }

    public function test_event_bus_allows_events_without_handlers(): void
    {
        $eventBus = static::getContainer()->get(EventBus::class);
        assert($eventBus instanceof EventBus);

        $envelope = $eventBus->dispatch(new UnobservedEvent('no-handlers'));
        $message = $envelope->getMessage();
        assert($message instanceof UnobservedEvent);
        self::assertSame('no-handlers', $message->identifier);
    }

    public function test_query_bus_returns_handler_result(): void
    {
        $commandBus = static::getContainer()->get(CommandBus::class);
        assert($commandBus instanceof CommandBus);
        $queryBus = static::getContainer()->get(QueryBus::class);
        assert($queryBus instanceof QueryBus);

        $commandBus->dispatch(new CreateTaskCommand('task-2', 'Review PR'));
        $result = $queryBus->ask(new FindTaskQuery('task-2'));

        self::assertSame('Review PR', $result);
    }

    public function test_query_handler_with_union_type_hint_is_autowired(): void
    {
        $queryBus = static::getContainer()->get(QueryBus::class);
        assert($queryBus instanceof QueryBus);

        $result = $queryBus->ask(new ListTasksQuery());

        self::assertSame(['task-1', 'task-2'], $result);
    }

    public function test_envelope_is_injected_into_sync_and_async_handlers(): void
    {
        $commandBus = static::getContainer()->get(CommandBus::class);
        assert($commandBus instanceof CommandBus);
        $recorder = static::getContainer()->get(TaskRecorder::class);
        assert($recorder instanceof TaskRecorder);

        $commandBus->dispatch(new CreateTaskCommand('task-envelope-sync', 'Sync envelope check'));
        $commandBus->dispatch(new GenerateReportCommand('report-envelope-async'), DispatchMode::ASYNC);

        self::assertSame(
            [CreateTaskCommand::class],
            $recorder->handledMessages(CreateTaskHandler::class)
        );
        self::assertSame(
            [GenerateReportCommand::class],
            $recorder->handledMessages(GenerateReportHandler::class)
        );

        $syncMetadata = $recorder->metadataStamps(CreateTaskHandler::class);
        self::assertCount(1, $syncMetadata);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $syncMetadata[0]->getCorrelationId());

        $asyncMetadata = $recorder->metadataStamps(GenerateReportHandler::class);
        self::assertCount(1, $asyncMetadata);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $asyncMetadata[0]->getCorrelationId());
        self::assertNotSame($syncMetadata[0]->getCorrelationId(), $asyncMetadata[0]->getCorrelationId());
    }

    public function test_child_messages_share_the_correlation_id_and_name_their_parent(): void
    {
        $commandBus = static::getContainer()->get(CommandBus::class);
        assert($commandBus instanceof CommandBus);
        $recorder = static::getContainer()->get(TaskRecorder::class);
        assert($recorder instanceof TaskRecorder);

        $commandBus->dispatchSync(new ImportTasksCommand(['a', 'b']));
        $commandBus->dispatchSync(new ImportTasksCommand(['c']));

        [$first, $second] = $recorder->metadataStamps(ImportTasksHandler::class);
        self::assertNotSame($first->getCorrelationId(), $second->getCorrelationId(), 'Each command starts a flow.');
        self::assertSame($first->getMessageId(), $first->getCorrelationId());

        $events = $recorder->metadataStamps(TaskImportedHandler::class);
        self::assertCount(3, $events);
        foreach ([[$events[0], $first], [$events[1], $first], [$events[2], $second]] as [$event, $command]) {
            self::assertSame($command->getCorrelationId(), $event->getCorrelationId());
            self::assertSame($command->getMessageId(), $event->getCausationId());
        }
        self::assertNotSame($events[0]->getMessageId(), $events[1]->getMessageId());
    }
}
