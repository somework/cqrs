<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Command;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Command\OutboxRelayCommand;
use SomeWork\CqrsBundle\Outbox\DbalOutboxStorage;
use SomeWork\CqrsBundle\Outbox\OutboxMessage;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\TestDatabase;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\UnavailableTransport;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

use function array_count_values;
use function array_map;
use function array_values;
use function ksort;
use function preg_replace;

/**
 * Runs the relay against the DBAL storage on a real database: in-memory SQLite, or the one named
 * by CQRS_TEST_DATABASE_URL (see TestDatabase).
 */
#[Group('database')]
#[CoversClass(OutboxRelayCommand::class)]
final class OutboxRelayDbalTest extends TestCase
{
    private Connection $connection;

    private DbalOutboxStorage $storage;

    private InMemoryTransport $async;

    private UnavailableTransport $ext;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connect();
        $this->storage = new DbalOutboxStorage($this->connection);
        $this->storage->setup();
        $this->async = new InMemoryTransport();
        $this->ext = new UnavailableTransport();
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    public function test_a_transport_that_is_down_does_not_hold_up_the_others(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            $this->store('ext-'.$i, 'ext');
        }
        $this->store('async-1', 'async');
        $this->store('async-2', 'async');

        $tester = $this->relay($this->bus());

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Transport "ext" failed 3 times in a row; its other messages wait for the next run.', self::display($tester));
        self::assertSame(['async-1', 'async-2'], $this->sentTaskIds());
        self::assertSame(3, $this->ext->sendAttempts);

        $status = $this->storage->status();
        self::assertSame(3, $status['retrying']);
        self::assertSame(2, $status['due'], 'The messages of the paused transport were not touched.');
        self::assertSame(0, $status['failed']);
    }

    public function test_overlapping_relays_send_each_message_once(): void
    {
        for ($i = 1; $i <= 3; ++$i) {
            $this->store('task-'.$i, 'async');
        }

        // A second relay (e.g. on another host, with a lock store that only guards one host) runs
        // while the first one sends its first message: the first relay fetched all three rows before.
        $second = null;
        $bus = new class($this->bus(), function () use (&$second): void {
            $second ??= $this->relay($this->bus(), 'other-host');
        }) implements MessageBusInterface {
            public function __construct(private readonly MessageBusInterface $bus, private readonly \Closure $beforeFirstSend)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                ($this->beforeFirstSend)();

                return $this->bus->dispatch($message, $stamps);
            }
        };

        $first = $this->relay($bus);

        self::assertNotNull($second);
        self::assertStringContainsString('Relayed 2 message(s).', self::display($second));
        self::assertStringContainsString('Skipped 2 message(s) that another relay claimed first.', self::display($first));
        self::assertSame(Command::SUCCESS, $first->getStatusCode());
        $sent = array_count_values($this->sentTaskIds());
        ksort($sent);
        self::assertSame(['task-1' => 1, 'task-2' => 1, 'task-3' => 1], $sent, 'Each message is sent once.');
        self::assertSame([], $this->storage->fetchUnpublished(10));
    }

    public function test_warns_when_the_table_needs_the_setup_command(): void
    {
        $this->connection->executeStatement('DROP TABLE somework_cqrs_outbox');
        TestDatabase::createTableOfVersion04($this->connection);
        $this->storage = new DbalOutboxStorage($this->connection);
        $this->store('task-1', 'async');

        $tester = $this->relay($this->bus());

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), 'The table works without the index, only slower.');
        self::assertSame(['task-1'], $this->sentTaskIds());
        self::assertStringContainsString('The outbox table needs "bin/console somework:cqrs:outbox:setup": the index "idx_somework_cqrs_outbox_pending" is missing;', self::display($tester));
    }

    private function store(string $taskId, string $transportName): void
    {
        $this->storage->store(OutboxMessage::fromEnvelope(new Envelope(new CreateTaskCommand($taskId, 'x')), new PhpSerializer(), $transportName));
    }

    private function relay(MessageBusInterface $bus, string $lockName = 'relay'): CommandTester
    {
        $tester = new CommandTester(new OutboxRelayCommand($this->storage, new PhpSerializer(), $bus, new LockFactory(new InMemoryStore()), lockName: $lockName));
        $tester->execute([]);

        return $tester;
    }

    private function bus(): MessageBus
    {
        $senders = new SendersLocator([], new ServiceLocator([
            'async' => fn (): SenderInterface => $this->async,
            'ext' => fn (): SenderInterface => $this->ext,
        ]));

        return new MessageBus([new SendMessageMiddleware($senders)]);
    }

    /**
     * @return list<string>
     */
    private function sentTaskIds(): array
    {
        return array_values(array_map(static function (Envelope $envelope): string {
            $message = $envelope->getMessage();
            self::assertInstanceOf(CreateTaskCommand::class, $message);

            return $message->id;
        }, $this->async->getSent()));
    }

    private static function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }
}
