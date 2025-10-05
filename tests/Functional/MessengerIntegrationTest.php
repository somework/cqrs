<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Functional;

use SomeWork\CqrsBundle\Bus\CommandBus;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Bus\EventBus;
use SomeWork\CqrsBundle\Bus\QueryBus;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\CreateTaskHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\GenerateReportHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Kernel\TestKernel;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\FindTaskQuery;
use SomeWork\CqrsBundle\Tests\Fixture\Message\GenerateReportCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskCreatedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Service\TaskRecorder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MessengerIntegrationTest extends KernelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();
        static::getContainer()->get(TaskRecorder::class)->reset();
    }

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function test_command_bus_dispatches_sync_command(): void
    {
        $commandBus = static::getContainer()->get(CommandBus::class);
        $recorder = static::getContainer()->get(TaskRecorder::class);

        $commandBus->dispatch(new CreateTaskCommand('task-1', 'Write docs'));

        self::assertSame('Write docs', $recorder->task('task-1'));
    }

    public function test_command_bus_dispatches_async_command(): void
    {
        $commandBus = static::getContainer()->get(CommandBus::class);
        $recorder = static::getContainer()->get(TaskRecorder::class);

        $commandBus->dispatch(new GenerateReportCommand('report-1'), DispatchMode::ASYNC);

        self::assertTrue($recorder->hasReport('report-1'));
    }

    public function test_event_bus_supports_sync_and_async_dispatch(): void
    {
        $eventBus = static::getContainer()->get(EventBus::class);
        $recorder = static::getContainer()->get(TaskRecorder::class);

        $eventBus->dispatch(new TaskCreatedEvent('sync-task'));
        $eventBus->dispatch(new TaskCreatedEvent('async-task'), DispatchMode::ASYNC);

        self::assertContains('sync-task', $recorder->events());
        self::assertContains('async-task', $recorder->asyncEvents());
    }

    public function test_query_bus_returns_handler_result(): void
    {
        $commandBus = static::getContainer()->get(CommandBus::class);
        $queryBus = static::getContainer()->get(QueryBus::class);

        $commandBus->dispatch(new CreateTaskCommand('task-2', 'Review PR'));
        $result = $queryBus->ask(new FindTaskQuery('task-2'));

        self::assertSame('Review PR', $result);
    }

    public function test_envelope_is_injected_into_sync_and_async_handlers(): void
    {
        $commandBus = static::getContainer()->get(CommandBus::class);
        $recorder = static::getContainer()->get(TaskRecorder::class);

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
    }
}
