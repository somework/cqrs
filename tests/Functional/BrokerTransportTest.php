<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Functional;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use SomeWork\CqrsBundle\Bus\CommandBus;
use SomeWork\CqrsBundle\Bus\EventBus;
use SomeWork\CqrsBundle\Contract\Outbox\OutboxStorage;
use SomeWork\CqrsBundle\Stamp\OutboxStoredStamp;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\CreateTaskHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Kernel\BrokerTransportTestKernel;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskArchivedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Service\TaskRecorder;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

use function bin2hex;
use function in_array;
use function random_bytes;
use function sprintf;

/**
 * The async round trip and the outbox relay on a real message broker: CI runs this group once per
 * Messenger bridge (AMQP on RabbitMQ, Redis, Amazon SQS on LocalStack) with CQRS_TEST_TRANSPORT_DSN,
 * e.g. CQRS_TEST_TRANSPORT_DSN=redis://127.0.0.1:6379/cqrs_test vendor/bin/phpunit --group transport.
 *
 * Every message carries an id of its own: messages a failed run left in the queue are consumed
 * without failing the next run.
 */
#[Group('transport')]
#[CoversNothing]
final class BrokerTransportTest extends KernelTestCase
{
    /** Messages consumed at most while waiting for the expected one. */
    private const MAX_MESSAGES = 10;

    protected static function getKernelClass(): string
    {
        return BrokerTransportTestKernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (null === BrokerTransportTestKernel::dsn()) {
            self::markTestSkipped(sprintf('Set %s to the DSN of a message broker.', BrokerTransportTestKernel::DSN_VARIABLE));
        }

        self::bootKernel();
        self::assertSame(Command::SUCCESS, $this->console('somework:cqrs:outbox:setup')->getStatusCode());
    }

    public function test_an_async_command_goes_through_the_broker_to_the_worker(): void
    {
        $id = self::uniqueId();

        $this->commandBus()->dispatchAsync(new CreateTaskCommand($id, 'Write docs'));
        self::assertSame([], $this->recorder()->handledMessages(CreateTaskHandler::class), 'Sent to the broker, not handled inline.');

        $this->consumeUntil(fn (): bool => null !== $this->recorder()->task($id));

        self::assertSame('Write docs', $this->recorder()->task($id));
        self::assertNotSame([], $this->recorder()->metadataStamps(CreateTaskHandler::class), 'The stamps travel with the message.');
    }

    public function test_an_async_event_goes_through_the_broker_to_the_worker(): void
    {
        $id = self::uniqueId();

        // dispatchAsync() sends it at once, although TaskArchivedEvent carries #[Outbox].
        $this->eventBus()->dispatchAsync(new TaskArchivedEvent($id));
        self::assertNotContains($id, $this->recorder()->events(), 'Sent to the broker, not handled inline.');

        $this->consumeUntil(fn (): bool => in_array($id, $this->recorder()->events(), true));
    }

    public function test_the_outbox_relay_sends_a_stored_message_to_the_broker(): void
    {
        $id = self::uniqueId();

        $envelope = $this->connection()->transactional(fn () => $this->eventBus()->dispatch(new TaskArchivedEvent($id)));

        $stored = $envelope->last(OutboxStoredStamp::class);
        self::assertInstanceOf(OutboxStoredStamp::class, $stored);
        self::assertSame(['async'], $stored->transportNames);
        self::assertCount(1, $this->storage()->fetchUnpublished(10));

        $relay = $this->console('somework:cqrs:outbox:relay');
        self::assertSame(Command::SUCCESS, $relay->getStatusCode(), $relay->getDisplay());
        self::assertSame([], $this->storage()->fetchUnpublished(10), 'Sent and marked as published.');
        self::assertNotContains($id, $this->recorder()->events(), 'The relay sent it to the broker, it did not handle it.');

        $this->consumeUntil(fn (): bool => in_array($id, $this->recorder()->events(), true));
    }

    /**
     * Runs the worker one message at a time until $done, as "messenger:consume" does.
     *
     * @param \Closure(): bool $done
     */
    private function consumeUntil(\Closure $done): void
    {
        for ($consumed = 0; $consumed < self::MAX_MESSAGES && !$done(); ++$consumed) {
            $worker = $this->console('messenger:consume', ['receivers' => ['async'], '--limit' => '1', '--time-limit' => '10']);
            self::assertSame(Command::SUCCESS, $worker->getStatusCode(), $worker->getDisplay());
        }

        self::assertTrue($done(), 'The worker handled the message.');
    }

    private static function uniqueId(): string
    {
        return 'task-'.bin2hex(random_bytes(6));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function console(string $command, array $input = []): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find($command));
        $tester->execute($input);

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

    private function recorder(): TaskRecorder
    {
        $recorder = self::getContainer()->get(TaskRecorder::class);
        self::assertInstanceOf(TaskRecorder::class, $recorder);

        return $recorder;
    }
}
