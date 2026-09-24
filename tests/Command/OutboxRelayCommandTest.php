<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Command;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Command\OutboxRelayCommand;
use SomeWork\CqrsBundle\Outbox\OutboxMessage;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskCreatedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\InMemoryOutboxStorage;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\LosingLockStore;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\RecordingBus;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Stamp\MessageDecodingFailedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

use function array_map;
use function mb_check_encoding;
use function mb_strlen;
use function preg_replace;
use function str_repeat;
use function time;

#[CoversClass(OutboxRelayCommand::class)]
final class OutboxRelayCommandTest extends TestCase
{
    private InMemoryOutboxStorage $storage;

    private InMemoryTransport $async;

    private InMemoryTransport $events;

    private LockFactory $locks;

    /** @var list<object> */
    private array $handledInline = [];

    protected function setUp(): void
    {
        $this->storage = new InMemoryOutboxStorage();
        $this->async = new InMemoryTransport();
        $this->events = new InMemoryTransport();
        $this->locks = new LockFactory(new InMemoryStore());
    }

    public function test_reports_when_there_is_nothing_to_relay(): void
    {
        $tester = $this->execute();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('No unpublished messages found.', self::display($tester));
    }

    public function test_relays_messages_in_order_to_their_stored_transport(): void
    {
        $this->store(new CreateTaskCommand('1', 'first'), 'async');
        $this->store(new CreateTaskCommand('2', 'second'), 'async');

        $tester = $this->execute();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Relayed 2 message(s).', self::display($tester));
        self::assertSame(['1', '2'], array_map(static fn (Envelope $envelope): string => self::taskId($envelope), $this->async->getSent()));
        self::assertSame([], $this->storage->fetchUnpublished(10));
    }

    public function test_messages_without_transport_name_follow_the_routing(): void
    {
        $this->store(new TaskCreatedEvent('task-1'));

        $this->execute();

        self::assertCount(1, $this->events->getSent());
        self::assertSame([], $this->async->getSent());
    }

    public function test_warns_when_a_message_was_handled_instead_of_sent(): void
    {
        $this->store(new \stdClass());

        $tester = $this->execute();

        self::assertCount(1, $this->handledInline);
        self::assertStringContainsString('was not sent to any transport', self::display($tester));
    }

    public function test_failures_do_not_stop_the_run_and_are_retried_later(): void
    {
        $this->store(new CreateTaskCommand('1', 'ok'), 'async');
        $this->storage->store(new OutboxMessage('broken', 'not a serialized envelope', '{}', new DateTimeImmutable()));
        $this->store(new CreateTaskCommand('2', 'ok'), 'async');

        $tester = $this->execute();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Failed to relay message "broken" (attempt 1 of 10, next attempt after', self::display($tester));
        self::assertCount(2, $this->async->getSent());
        self::assertSame(['broken'], $this->storage->unpublishedIds());
        self::assertSame(1, $this->storage->attempts('broken'));
        self::assertStringStartsWith(MessageDecodingFailedException::class.': ', $this->storage->failures['broken']['error']);
    }

    /**
     * Symfony 8 serializers return decoding failures inside the envelope instead of throwing.
     *
     * @return iterable<string, array{Envelope}>
     */
    public static function envelopesReportingADecodingFailure(): iterable
    {
        yield 'wrapped exception' => [new Envelope(new MessageDecodingFailedException('Could not decode Envelope.'))];
        yield 'decoding failed stamp' => [new Envelope(new \stdClass(), [new MessageDecodingFailedStamp()])];
    }

    #[DataProvider('envelopesReportingADecodingFailure')]
    public function test_a_decoding_failure_reported_in_the_envelope_is_a_failure(Envelope $decoded): void
    {
        $this->storage->store(new OutboxMessage('undecodable', 'body', '{}', new DateTimeImmutable()));
        $serializer = self::createStub(SerializerInterface::class);
        $serializer->method('decode')->willReturn($decoded);

        $tester = new CommandTester(new OutboxRelayCommand($this->storage, $serializer, $this->bus(), $this->locks));
        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Failed to relay message "undecodable"', self::display($tester));
        self::assertFalse($this->storage->isPublished('undecodable'));
        self::assertSame([], $this->handledInline);
    }

    public function test_failing_messages_at_the_head_do_not_block_the_queue(): void
    {
        foreach (['p1', 'p2', 'p3', 'p4', 'p5', 'p6'] as $id) {
            $this->storage->store(new OutboxMessage($id, 'not a serialized envelope', '{}', new DateTimeImmutable('2026-01-01')));
        }
        $this->store(new CreateTaskCommand('1', 'ok'), 'async');
        $this->store(new CreateTaskCommand('2', 'ok'), 'async');

        $first = $this->execute(['--limit' => '6']);
        self::assertSame(Command::FAILURE, $first->getStatusCode());
        self::assertSame([], $this->async->getSent(), 'The first run only reached failing messages.');

        $second = $this->execute(['--limit' => '6']);
        self::assertSame(Command::SUCCESS, $second->getStatusCode(), 'Postponed messages are not tried again right away.');
        self::assertCount(2, $this->async->getSent());
        self::assertSame(1, $this->storage->attempts('p1'));
    }

    public function test_postponed_messages_are_not_counted_as_nothing_to_relay(): void
    {
        $this->storage->store(new OutboxMessage('broken', 'not a serialized envelope', '{}', new DateTimeImmutable()));
        $this->execute();

        $tester = $this->execute();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('No unpublished messages found.', self::display($tester));
    }

    public function test_the_retry_delay_doubles_up_to_one_hour(): void
    {
        foreach (['a' => 0, 'b' => 1, 'c' => 3, 'd' => 8] as $id => $attempts) {
            $this->storage->store(new OutboxMessage($id, 'not a serialized envelope', '{}', new DateTimeImmutable(), attempts: $attempts));
        }

        $this->execute();

        foreach (['a' => 60, 'b' => 120, 'c' => 480, 'd' => 3600] as $id => $delay) {
            $retryAt = $this->storage->failures[$id]['retryAt'];
            self::assertNotNull($retryAt, $id);
            self::assertEqualsWithDelta(time() + $delay, $retryAt->getTimestamp(), 5, $id);
        }
    }

    public function test_gives_up_after_the_maximum_number_of_attempts(): void
    {
        $this->storage->store(new OutboxMessage('broken', 'not a serialized envelope', '{}', new DateTimeImmutable(), attempts: 2));

        $tester = new CommandTester(new OutboxRelayCommand($this->storage, new PhpSerializer(), $this->bus(), $this->locks, maxAttempts: 3));
        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Gave up on message "broken" after 3 attempt(s)', self::display($tester));
        self::assertNull($this->storage->failures['broken']['retryAt']);
        self::assertSame(3, $this->storage->attempts('broken'));
    }

    public function test_stops_when_a_failure_cannot_be_recorded(): void
    {
        $this->storage->store(new OutboxMessage('p1', 'not a serialized envelope', '{}', new DateTimeImmutable('2026-01-01')));
        $this->store(new CreateTaskCommand('1', 'ok'), 'async');
        $this->storage->failMarkingFailed = true;

        $tester = $this->execute();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Stopping: the failure could not be recorded (Database is down.)', self::display($tester));
        self::assertSame([], $this->async->getSent());
    }

    public function test_a_storage_that_does_not_postpone_failures_still_terminates(): void
    {
        $this->storage->postponeFailures = false;
        $this->storage->store(new OutboxMessage('p1', 'not a serialized envelope', '{}', new DateTimeImmutable('2026-01-01')));
        $this->store(new CreateTaskCommand('1', 'ok'), 'async');

        $tester = $this->execute(['--limit' => '10']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(1, $this->storage->attempts('p1'), 'Each message is tried once per run.');
        self::assertCount(1, $this->async->getSent());
    }

    public function test_stored_errors_are_valid_utf8_and_bounded(): void
    {
        $message = $this->store(new CreateTaskCommand('1', 'a'), 'async');
        $bus = new RecordingBus(new \RuntimeException("Invalid \xB1 byte ".str_repeat('x', 5000)));

        (new CommandTester(new OutboxRelayCommand($this->storage, new PhpSerializer(), $bus, $this->locks)))->execute([]);

        $error = $this->storage->failures[$message->id]['error'];
        self::assertTrue(mb_check_encoding($error, 'UTF-8'));
        self::assertSame(2000, mb_strlen($error, 'UTF-8'));
        self::assertStringStartsWith('RuntimeException: Invalid ? byte', $error);
    }

    public function test_rejects_less_than_one_attempt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The maximum number of attempts must be at least 1, 0 given.');

        new OutboxRelayCommand($this->storage, new PhpSerializer(), $this->bus(), $this->locks, maxAttempts: 0);
    }

    public function test_limit_bounds_the_number_of_relayed_messages(): void
    {
        $this->store(new CreateTaskCommand('1', 'a'), 'async');
        $this->store(new CreateTaskCommand('2', 'b'), 'async');
        $this->store(new CreateTaskCommand('3', 'c'), 'async');

        $this->execute(['--limit' => '2']);

        self::assertCount(2, $this->async->getSent());
        self::assertCount(1, $this->storage->fetchUnpublished(10));
    }

    public function test_a_message_that_cannot_be_marked_published_is_reported(): void
    {
        $message = $this->store(new CreateTaskCommand('1', 'a'), 'async');
        $this->storage->failMarkingPublished = [$message->id];

        $tester = $this->execute();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Cannot mark', self::display($tester));
        self::assertSame(1, $this->storage->attempts($message->id), 'The message is sent again later (at-least-once).');
    }

    public function test_rejects_an_invalid_limit(): void
    {
        foreach (['0', '-5', 'abc'] as $limit) {
            $tester = $this->execute(['--limit' => $limit]);

            self::assertSame(Command::INVALID, $tester->getStatusCode());
            self::assertStringContainsString('Limit must be a positive integer.', self::display($tester));
        }
    }

    public function test_skips_when_another_relay_is_running(): void
    {
        $this->store(new CreateTaskCommand('1', 'a'), 'async');
        $lock = $this->locks->createLock('somework:cqrs:outbox:relay');
        $lock->acquire();

        $tester = $this->execute();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Another outbox relay is already running.', self::display($tester));
        self::assertSame([], $this->async->getSent());
    }

    public function test_the_lock_name_is_configurable(): void
    {
        $this->store(new CreateTaskCommand('1', 'a'), 'async');
        $held = $this->locks->createLock('app-a:outbox');
        $held->acquire();

        $blocked = new CommandTester(new OutboxRelayCommand($this->storage, new PhpSerializer(), $this->bus(), $this->locks, lockName: 'app-a:outbox'));
        $blocked->execute([]);
        self::assertStringContainsString('Another outbox relay is already running.', self::display($blocked));

        $other = new CommandTester(new OutboxRelayCommand($this->storage, new PhpSerializer(), $this->bus(), $this->locks, lockName: 'app-b:outbox'));
        $other->execute([]);
        self::assertCount(1, $this->async->getSent());
    }

    public function test_messages_are_dispatched_on_the_bus_of_their_type(): void
    {
        $this->store(new TaskCreatedEvent('1'), 'events');
        $this->store(new CreateTaskCommand('2', 'b'), 'async');
        $this->store(new \stdClass(), 'async');
        $eventBus = new RecordingBus();
        $commandBus = new RecordingBus();
        $defaultBus = new RecordingBus();

        $tester = new CommandTester(new OutboxRelayCommand(
            $this->storage,
            new PhpSerializer(),
            $defaultBus,
            $this->locks,
            new ServiceLocator(['event' => static fn (): RecordingBus => $eventBus, 'command' => static fn (): RecordingBus => $commandBus]),
        ));
        $tester->execute([]);

        self::assertSame([TaskCreatedEvent::class], $eventBus->messageClasses());
        self::assertSame([CreateTaskCommand::class], $commandBus->messageClasses());
        self::assertSame([\stdClass::class], $defaultBus->messageClasses());
    }

    public function test_stops_after_consecutive_send_failures(): void
    {
        for ($i = 1; $i <= 20; ++$i) {
            $this->store(new CreateTaskCommand((string) $i, 'x'), 'async');
        }
        $bus = new RecordingBus(new \RuntimeException('Connection refused'));

        $tester = new CommandTester(new OutboxRelayCommand($this->storage, new PhpSerializer(), $bus, $this->locks));
        $tester->execute(['--limit' => '100']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Stopping after 5 consecutive failures to send messages.', self::display($tester));
        self::assertCount(5, $bus->messageClasses());
        self::assertCount(20, $this->storage->unpublishedIds());
        self::assertCount(5, $this->storage->failures, 'Only the messages that were tried are postponed.');
    }

    public function test_stops_when_the_lock_is_lost(): void
    {
        $this->store(new CreateTaskCommand('1', 'a'), 'async');
        $this->store(new CreateTaskCommand('2', 'b'), 'async');

        $tester = new CommandTester(new OutboxRelayCommand($this->storage, new PhpSerializer(), $this->bus(), new LockFactory(new LosingLockStore())));
        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('the relay lock was lost', self::display($tester));
        self::assertCount(1, $this->async->getSent(), 'The run stops right after the lock could not be extended.');
    }

    private function store(object $message, ?string $transportName = null): OutboxMessage
    {
        $outboxMessage = OutboxMessage::fromEnvelope(new Envelope($message), new PhpSerializer(), $transportName);
        $this->storage->store($outboxMessage);

        return $outboxMessage;
    }

    /**
     * @param array<string, string> $input
     */
    private function execute(array $input = []): CommandTester
    {
        $tester = new CommandTester(new OutboxRelayCommand($this->storage, new PhpSerializer(), $this->bus(), $this->locks));
        $tester->execute($input);

        return $tester;
    }

    private function bus(): MessageBus
    {
        $senders = new SendersLocator(
            [TaskCreatedEvent::class => ['events']],
            new ServiceLocator(['async' => fn (): InMemoryTransport => $this->async, 'events' => fn (): InMemoryTransport => $this->events]),
        );

        return new MessageBus([
            new SendMessageMiddleware($senders),
            new HandleMessageMiddleware(new HandlersLocator([\stdClass::class => [function (object $message): void {
                $this->handledInline[] = $message;
            }]]), true),
        ]);
    }

    /**
     * SymfonyStyle wraps long lines; collapse whitespace so assertions do not depend on the terminal width.
     */
    private static function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    private static function taskId(Envelope $envelope): string
    {
        $message = $envelope->getMessage();
        self::assertInstanceOf(CreateTaskCommand::class, $message);

        return $message->id;
    }
}
