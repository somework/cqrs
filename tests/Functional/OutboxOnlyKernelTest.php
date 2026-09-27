<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Functional;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use SomeWork\CqrsBundle\Bus\CommandBus;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Bus\EventBus;
use SomeWork\CqrsBundle\Contract\Outbox\OutboxStorage;
use SomeWork\CqrsBundle\Outbox\OutboxMessage;
use SomeWork\CqrsBundle\Outbox\OutboxWriter;
use SomeWork\CqrsBundle\Stamp\OutboxStoredStamp;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\TaskProjectionHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Kernel\OutboxOnlyTestKernel;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskArchivedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskCreatedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Service\TaskRecorder;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

use function array_map;
use function sort;

/**
 * An outbox-only application (no async bus): rows stored in the outbox carry the transport of
 * "transports.command_async" / "transports.event_async", and the relay sends them there.
 */
#[Group('database')]
#[CoversNothing]
final class OutboxOnlyKernelTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return OutboxOnlyTestKernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();
        self::assertSame(Command::SUCCESS, $this->console('somework:cqrs:outbox:setup')->getStatusCode());
    }

    public function test_a_command_is_stored_for_its_async_transport_and_relayed_to_it(): void
    {
        $envelope = $this->connection()->transactional(fn () => $this->commandBus()->dispatch(new CreateTaskCommand('task-1', 'Write docs'), DispatchMode::OUTBOX));

        self::assertSame(['commands'], $envelope->last(OutboxStoredStamp::class)?->transportNames);
        self::assertSame(['commands'], $this->storedTransportNames());

        self::assertSame(Command::SUCCESS, $this->console('somework:cqrs:outbox:relay')->getStatusCode());
        self::assertNull($this->recorder()->task('task-1'), 'Sent to the transport, not handled in the relay.');
        self::assertCount(1, $this->transport('commands')->getSent());
        self::assertSame([], $this->transport('events')->getSent());

        self::assertSame(Command::SUCCESS, $this->console('messenger:consume', ['receivers' => ['commands'], '--limit' => '1', '--time-limit' => '5'])->getStatusCode());
        self::assertSame('Write docs', $this->recorder()->task('task-1'));
    }

    public function test_an_event_is_stored_for_its_async_transport_and_relayed_to_it(): void
    {
        $envelope = $this->connection()->transactional(fn () => $this->eventBus()->dispatch(new TaskCreatedEvent('task-2'), DispatchMode::OUTBOX));

        self::assertSame(['events'], $envelope->last(OutboxStoredStamp::class)?->transportNames);
        self::assertSame(['events'], $this->storedTransportNames());

        self::assertSame(Command::SUCCESS, $this->console('somework:cqrs:outbox:relay')->getStatusCode());
        self::assertSame([], $this->recorder()->handledMessages(TaskProjectionHandler::class), 'Sent to the transport, not handled in the relay.');
        self::assertCount(1, $this->transport('events')->getSent());
        self::assertSame([], $this->transport('commands')->getSent());

        self::assertSame(Command::SUCCESS, $this->console('messenger:consume', ['receivers' => ['events'], '--limit' => '1', '--time-limit' => '5'])->getStatusCode());
        self::assertCount(1, $this->recorder()->handledMessages(TaskProjectionHandler::class));
    }

    public function test_a_bare_outbox_attribute_uses_the_async_transport_of_its_type(): void
    {
        $envelope = $this->connection()->transactional(fn () => $this->eventBus()->dispatch(new TaskArchivedEvent('task-5')));

        self::assertSame(['events'], $envelope->last(OutboxStoredStamp::class)?->transportNames, 'Not the "async" transport of a bare #[Outbox].');
        self::assertSame(['events'], $this->storedTransportNames());
    }

    public function test_the_delay_of_a_stored_message_reaches_the_transport(): void
    {
        $this->connection()->transactional(fn () => $this->commandBus()->dispatch(new CreateTaskCommand('task-3', 'Later'), DispatchMode::OUTBOX, new DelayStamp(60_000)));

        self::assertSame(Command::SUCCESS, $this->console('somework:cqrs:outbox:relay')->getStatusCode());

        self::assertNull($this->recorder()->task('task-3'), 'Not handled in the relay, which would ignore the delay.');
        $sent = $this->transport('commands')->getSent();
        self::assertCount(1, $sent);
        self::assertSame(60_000, $sent[0]->last(DelayStamp::class)?->getDelay());
    }

    public function test_the_outbox_writer_stores_rows_for_the_async_transports(): void
    {
        $writer = self::getContainer()->get('test.outbox_writer');
        self::assertInstanceOf(OutboxWriter::class, $writer);

        $this->connection()->transactional(static fn () => $writer->store(new CreateTaskCommand('task-4', 'Stored')));
        self::assertSame(['commands'], $this->storedTransportNames());

        $this->connection()->transactional(static fn () => $writer->store(new TaskCreatedEvent('task-4')));
        $stored = $this->storedTransportNames();
        sort($stored);
        self::assertSame(['commands', 'events'], $stored);
    }

    /**
     * @return list<string|null>
     */
    private function storedTransportNames(): array
    {
        return array_map(static fn (OutboxMessage $row): ?string => $row->transportName, $this->storage()->fetchUnpublished(10));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function console(string $command, array $input = []): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        $tester = new CommandTester((new Application($kernel))->find($command));
        $tester->execute($input, ['interactive' => false]);

        return $tester;
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

    private function storage(): OutboxStorage
    {
        $storage = self::getContainer()->get(OutboxStorage::class);
        self::assertInstanceOf(OutboxStorage::class, $storage);

        return $storage;
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    private function transport(string $name): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.'.$name);
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function recorder(): TaskRecorder
    {
        $recorder = self::getContainer()->get(TaskRecorder::class);
        self::assertInstanceOf(TaskRecorder::class, $recorder);

        return $recorder;
    }
}
