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
use SomeWork\CqrsBundle\Exception\OutboxRequiresTransactionException;
use SomeWork\CqrsBundle\Outbox\OutboxMessage;
use SomeWork\CqrsBundle\Stamp\MessageMetadataStamp;
use SomeWork\CqrsBundle\Stamp\OutboxStoredStamp;
use SomeWork\CqrsBundle\Tests\Fixture\Kernel\OutboxTestKernel;
use SomeWork\CqrsBundle\Tests\Fixture\Message\ArchiveTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskArchivedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Service\TaskRecorder;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

use function array_map;

/**
 * Messages dispatched through the CQRS buses into the outbox: explicitly with DispatchMode::OUTBOX,
 * by #[Outbox] on the message, or by the dispatch_modes configuration.
 */
#[Group('database')]
#[CoversNothing]
final class OutboxDispatchKernelTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return OutboxTestKernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();
        self::assertSame(Command::SUCCESS, $this->console('somework:cqrs:outbox:setup')->getStatusCode());
    }

    public function test_an_event_with_the_outbox_attribute_is_stored_then_relayed_and_handled(): void
    {
        $envelope = $this->connection()->transactional(fn () => $this->eventBus()->dispatch(new TaskArchivedEvent('task-1')));

        $stored = $envelope->last(OutboxStoredStamp::class);
        self::assertInstanceOf(OutboxStoredStamp::class, $stored);
        self::assertSame(['async'], $stored->transportNames, 'The transport of an asynchronous dispatch (transports.event_async).');
        self::assertSame([], $this->transport()->getSent(), 'Stored, not sent.');
        self::assertSame($stored->ids, array_map(static fn (OutboxMessage $row): string => $row->id, $this->storage()->fetchUnpublished(10)));

        self::assertSame(Command::SUCCESS, $this->console('somework:cqrs:outbox:relay')->getStatusCode());
        $sent = $this->transport()->getSent();
        self::assertCount(1, $sent);
        self::assertSame('event.async_bus', $sent[0]->last(BusNameStamp::class)?->getBusName());
        self::assertSame([], $this->storage()->fetchUnpublished(10), 'The relay dispatches on Messenger buses: the message is not stored again.');
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM somework_cqrs_outbox'));
        self::assertNotNull($sent[0]->last(MessageMetadataStamp::class), 'The stamp pipeline ran when it was stored.');

        self::assertSame(Command::SUCCESS, $this->console('messenger:consume', ['receivers' => ['async'], '--limit' => '1', '--time-limit' => '5'])->getStatusCode());
        self::assertSame(['task-1'], $this->recorder()->events());
    }

    public function test_a_command_mapped_to_the_outbox_is_stored_and_a_rollback_removes_it(): void
    {
        $this->connection()->beginTransaction();
        $this->commandBus()->dispatch(new ArchiveTaskCommand('task-2'));
        self::assertCount(1, $this->storage()->fetchUnpublished(10), 'Stored in the transaction.');
        $this->connection()->rollBack();

        self::assertSame([], $this->storage()->fetchUnpublished(10), 'Rolled back with the business change.');
        self::assertSame([], $this->transport()->getSent());
    }

    public function test_any_message_can_be_stored_explicitly(): void
    {
        $this->connection()->transactional(fn () => $this->commandBus()->dispatch(new CreateTaskCommand('task-3', 'Write docs'), DispatchMode::OUTBOX));

        self::assertSame(Command::SUCCESS, $this->console('somework:cqrs:outbox:relay')->getStatusCode());
        self::assertSame(Command::SUCCESS, $this->console('messenger:consume', ['receivers' => ['async'], '--limit' => '1', '--time-limit' => '5'])->getStatusCode());
        self::assertSame('Write docs', $this->recorder()->task('task-3'));
    }

    public function test_storing_outside_a_transaction_is_refused(): void
    {
        try {
            $this->commandBus()->dispatch(new ArchiveTaskCommand('task-4'));
            self::fail('Expected the store to be refused.');
        } catch (OutboxRequiresTransactionException $exception) {
            self::assertSame(ArchiveTaskCommand::class, $exception->messageClass);
        }

        self::assertSame([], $this->storage()->fetchUnpublished(10));
    }

    public function test_the_synchronous_and_asynchronous_dispatch_methods_bypass_the_outbox(): void
    {
        $this->commandBus()->dispatchSync(new ArchiveTaskCommand('task-5'));
        $this->eventBus()->dispatchAsync(new TaskArchivedEvent('task-6'));

        self::assertSame('archived', $this->recorder()->task('task-5'));
        self::assertCount(1, $this->transport()->getSent());
        self::assertSame([], $this->storage()->fetchUnpublished(10));
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

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
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
