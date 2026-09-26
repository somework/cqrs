<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\CoversNothing;
use SomeWork\CqrsBundle\Contract\CommandBusInterface;
use SomeWork\CqrsBundle\Contract\EventBusInterface;
use SomeWork\CqrsBundle\Contract\QueryBusInterface;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\TaskAuditTrailHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\TaskProjectionHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Kernel\MinimalTestKernel;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\GenerateReportCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\ListTasksQuery;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskCreatedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Service\TaskRecorder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Stamp\HandledStamp;

use function array_map;
use function sort;

/**
 * Boots the bundle the way a fresh installation does: Messenger's single default
 * bus, no "somework_cqrs" configuration and none of the optional packages configured.
 */
#[CoversNothing]
final class MinimalInstallTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return MinimalTestKernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();
        $this->recorder()->reset();
    }

    public function test_command_is_handled_on_the_default_bus(): void
    {
        $this->commandBus()->dispatch(new CreateTaskCommand('task-1', 'Write docs'));

        self::assertSame('Write docs', $this->recorder()->task('task-1'));
    }

    public function test_handler_discovered_through_the_marker_interface_alone(): void
    {
        self::assertSame('report:r-1', $this->commandBus()->dispatchSync(new GenerateReportCommand('r-1')));
        self::assertTrue($this->recorder()->hasReport('r-1'));
    }

    public function test_query_returns_the_handler_result(): void
    {
        self::assertSame(['task-1', 'task-2'], $this->queryBus()->ask(new ListTasksQuery()));
    }

    public function test_every_event_handler_on_the_same_bus_is_invoked(): void
    {
        $envelope = $this->eventBus()->dispatch(new TaskCreatedEvent('task-1'));

        self::assertSame([TaskCreatedEvent::class], $this->recorder()->handledMessages(TaskAuditTrailHandler::class));
        self::assertSame([TaskCreatedEvent::class], $this->recorder()->handledMessages(TaskProjectionHandler::class));

        $handlerNames = array_map(
            static fn (HandledStamp $stamp): string => $stamp->getHandlerName(),
            $envelope->all(HandledStamp::class),
        );
        sort($handlerNames);

        self::assertSame(
            [TaskAuditTrailHandler::class.'::__invoke', TaskProjectionHandler::class.'::__invoke'],
            $handlerNames,
        );
    }

    private function recorder(): TaskRecorder
    {
        $recorder = self::getContainer()->get(TaskRecorder::class);
        self::assertInstanceOf(TaskRecorder::class, $recorder);

        return $recorder;
    }

    private function commandBus(): CommandBusInterface
    {
        $bus = self::getContainer()->get(CommandBusInterface::class);
        self::assertInstanceOf(CommandBusInterface::class, $bus);

        return $bus;
    }

    private function queryBus(): QueryBusInterface
    {
        $bus = self::getContainer()->get(QueryBusInterface::class);
        self::assertInstanceOf(QueryBusInterface::class, $bus);

        return $bus;
    }

    private function eventBus(): EventBusInterface
    {
        $bus = self::getContainer()->get(EventBusInterface::class);
        self::assertInstanceOf(EventBusInterface::class, $bus);

        return $bus;
    }
}
