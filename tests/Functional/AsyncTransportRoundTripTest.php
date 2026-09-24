<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\CoversNothing;
use SomeWork\CqrsBundle\Bus\CommandBus;
use SomeWork\CqrsBundle\Bus\EventBus;
use SomeWork\CqrsBundle\Stamp\MessageMetadataStamp;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\CreateTaskHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\TaskAuditTrailHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\TaskProjectionHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Kernel\AsyncTransportTestKernel;
use SomeWork\CqrsBundle\Tests\Fixture\Message\AsyncTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskCreatedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Service\TaskRecorder;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Dispatches through the CQRS buses into a serializing transport and consumes the messages with
 * a real worker, the way an application runs "messenger:consume".
 */
#[CoversNothing]
final class AsyncTransportRoundTripTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return AsyncTransportTestKernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();
    }

    public function test_async_command_is_sent_and_handled_by_the_worker(): void
    {
        $this->commandBus()->dispatchAsync(new CreateTaskCommand('task-1', 'Write docs'));

        self::assertNull($this->recorder()->task('task-1'), 'Async dispatch must not handle the command inline.');
        $sent = $this->transport()->getSent();
        self::assertCount(1, $sent);
        self::assertSame('command.async_bus', $sent[0]->last(BusNameStamp::class)?->getBusName());

        $this->consume(1);

        self::assertSame('Write docs', $this->recorder()->task('task-1'));
        self::assertSame([CreateTaskCommand::class], $this->recorder()->handledMessages(CreateTaskHandler::class));
        self::assertCount(1, $this->recorder()->metadataStamps(CreateTaskHandler::class), 'Stamps survive serialization.');
    }

    public function test_asynchronous_attribute_routes_default_dispatch_to_the_transport(): void
    {
        $this->commandBus()->dispatch(new AsyncTaskCommand('task-2'));

        self::assertNull($this->recorder()->task('task-2'));
        self::assertCount(1, $this->transport()->getSent());

        $this->consume(1);

        self::assertSame('async', $this->recorder()->task('task-2'));
    }

    public function test_every_handler_of_an_async_event_runs_in_the_worker(): void
    {
        $this->eventBus()->dispatchAsync(new TaskCreatedEvent('task-3'));

        self::assertSame([], $this->recorder()->handledMessages(TaskAuditTrailHandler::class));

        $this->consume(1);

        self::assertSame([TaskCreatedEvent::class], $this->recorder()->handledMessages(TaskAuditTrailHandler::class));
        self::assertSame([TaskCreatedEvent::class], $this->recorder()->handledMessages(TaskProjectionHandler::class));
        self::assertInstanceOf(MessageMetadataStamp::class, $this->recorder()->metadataStamps(TaskProjectionHandler::class)[0] ?? null);
    }

    public function test_sync_dispatch_does_not_use_the_transport(): void
    {
        $this->commandBus()->dispatchSync(new CreateTaskCommand('task-4', 'Inline'));
        $this->eventBus()->dispatchSync(new TaskCreatedEvent('task-4'));

        self::assertSame('Inline', $this->recorder()->task('task-4'));
        self::assertSame([TaskCreatedEvent::class], $this->recorder()->handledMessages(TaskAuditTrailHandler::class));
        self::assertSame([], $this->transport()->getSent());
    }

    private function consume(int $limit): void
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        $tester = new CommandTester((new Application($kernel))->find('messenger:consume'));
        $tester->execute(['receivers' => ['async'], '--limit' => (string) $limit, '--time-limit' => '5']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function commandBus(): CommandBus
    {
        $bus = self::getContainer()->get(CommandBus::class);
        self::assertInstanceOf(CommandBus::class, $bus);

        return $bus;
    }

    private function eventBus(): EventBus
    {
        $bus = self::getContainer()->get(EventBus::class);
        self::assertInstanceOf(EventBus::class, $bus);

        return $bus;
    }

    private function recorder(): TaskRecorder
    {
        $recorder = self::getContainer()->get(TaskRecorder::class);
        self::assertInstanceOf(TaskRecorder::class, $recorder);

        return $recorder;
    }
}
