<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Functional;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use SomeWork\CqrsBundle\Contract\OutboxStorage;
use SomeWork\CqrsBundle\Outbox\OutboxMessage;
use SomeWork\CqrsBundle\Outbox\OutboxWriter;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\CreateTaskHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Kernel\OutboxTestKernel;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Service\TaskRecorder;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

use function preg_replace;

/**
 * Stores messages in the outbox inside a business transaction, relays them with the console
 * command and consumes them with a real worker; a poison row ends up with the given-up messages.
 */
#[Group('database')]
#[CoversNothing]
final class OutboxRelayKernelTest extends KernelTestCase
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

    public function test_a_stored_command_is_relayed_to_the_async_bus_and_handled_by_the_worker(): void
    {
        $this->connection()->transactional(function (): void {
            $this->storage()->store(OutboxMessage::fromEnvelope(new Envelope(new CreateTaskCommand('task-1', 'Write docs')), $this->serializer(), 'async'));
        });

        $relay = $this->console('somework:cqrs:outbox:relay');

        self::assertSame(Command::SUCCESS, $relay->getStatusCode(), $relay->getDisplay());
        $sent = $this->transport()->getSent();
        self::assertCount(1, $sent);
        self::assertSame('command.async_bus', $sent[0]->last(BusNameStamp::class)?->getBusName());
        self::assertNull($this->recorder()->task('task-1'), 'The relay sends, the worker handles.');

        $consume = $this->console('messenger:consume', ['receivers' => ['async'], '--limit' => '1', '--time-limit' => '5']);

        self::assertSame(Command::SUCCESS, $consume->getStatusCode(), $consume->getDisplay());
        self::assertSame('Write docs', $this->recorder()->task('task-1'));
        self::assertSame([CreateTaskCommand::class], $this->recorder()->handledMessages(CreateTaskHandler::class));
        self::assertSame([], $this->storage()->fetchUnpublished(10));
    }

    public function test_the_writer_sends_messages_where_an_async_dispatch_would(): void
    {
        // "transports.command_async" of the kernel names the "async" transport.
        $writer = self::getContainer()->get('test.outbox_writer');
        self::assertInstanceOf(OutboxWriter::class, $writer);

        $this->connection()->transactional(static function () use ($writer): void {
            $writer->store(new CreateTaskCommand('task-3', 'Stored with the writer'));
        });

        self::assertSame('async', $this->storage()->fetchUnpublished(10)[0]->transportName);
        self::assertSame(Command::SUCCESS, $this->console('somework:cqrs:outbox:relay')->getStatusCode());
        self::assertCount(1, $this->transport()->getSent());
    }

    public function test_a_poison_row_is_given_up_after_the_configured_attempts_and_can_be_requeued(): void
    {
        $this->storage()->store(new OutboxMessage('00000000-0000-7000-8000-000000000001', 'not a serialized envelope', '{}', new DateTimeImmutable('-1 hour')));
        $this->storage()->store(OutboxMessage::fromEnvelope(new Envelope(new CreateTaskCommand('task-2', 'Behind the poison row')), $this->serializer(), 'async'));

        $first = $this->console('somework:cqrs:outbox:relay');
        self::assertSame(Command::FAILURE, $first->getStatusCode());
        self::assertStringContainsString('(attempt 1 of 2, next attempt after', self::display($first));
        self::assertCount(1, $this->transport()->getSent(), 'The poison row does not block the queue.');

        // Make the postponed row due again: the second failure is the last attempt ("max_attempts: 2").
        $this->connection()->executeStatement('UPDATE somework_cqrs_outbox SET available_at = NULL');
        $second = $this->console('somework:cqrs:outbox:relay');
        self::assertStringContainsString('Gave up on message "00000000-0000-7000-8000-000000000001" after 2 attempt(s)', self::display($second));

        $failed = $this->console('somework:cqrs:outbox:failed');
        self::assertStringContainsString('00000000-0000-7000-8000-000000000001', self::display($failed));
        self::assertMatchesRegularExpression('/ 2 Symfony.Component.Messenger.Exception.\w+: /', self::display($failed), 'Attempts and the last error are listed.');

        $requeue = $this->console('somework:cqrs:outbox:failed', ['--requeue' => true]);
        self::assertStringContainsString('Requeued 1 message(s)', self::display($requeue));
        self::assertCount(1, $this->storage()->fetchUnpublished(10));
    }

    public function test_a_row_written_around_the_storage_is_not_decoded_until_an_operator_signs_it(): void
    {
        // What an attacker with write access to the table (e.g. an SQL injection) would insert:
        // a serialized envelope, which the PHP serializer of the relay would unserialize.
        $forged = OutboxMessage::fromEnvelope(new Envelope(new CreateTaskCommand('forged', 'Injected')), $this->serializer(), 'async');
        $this->connection()->insert('somework_cqrs_outbox', ['id' => $forged->id, 'body' => $forged->body, 'headers' => $forged->headers, 'transport_name' => 'async', 'created_at' => '2026-01-01 10:00:00']);

        $relay = $this->console('somework:cqrs:outbox:relay');

        self::assertSame(Command::FAILURE, $relay->getStatusCode());
        self::assertStringContainsString('The message is not signed, so it was not decoded', self::display($relay));
        self::assertSame([], $this->transport()->getSent());

        self::assertSame(Command::INVALID, $this->console('somework:cqrs:outbox:failed', ['--requeue' => true, '--sign' => true])->getStatusCode(), 'Only rows named one by one are signed.');
        $sign = $this->console('somework:cqrs:outbox:failed', ['--requeue' => true, '--sign' => true, 'ids' => [$forged->id]]);
        self::assertStringContainsString('Requeued 1 message(s)', self::display($sign));

        self::assertSame(Command::SUCCESS, $this->console('somework:cqrs:outbox:relay')->getStatusCode());
        self::assertCount(1, $this->transport()->getSent(), 'Signed by the operator, the row is relayed.');
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

    private static function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    private function storage(): OutboxStorage
    {
        $storage = self::getContainer()->get(OutboxStorage::class);
        self::assertInstanceOf(OutboxStorage::class, $storage);

        return $storage;
    }

    private function serializer(): SerializerInterface
    {
        $serializer = self::getContainer()->get('messenger.default_serializer');
        self::assertInstanceOf(SerializerInterface::class, $serializer);

        return $serializer;
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
