<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Command;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Command\OutboxRelayCommand;
use SomeWork\CqrsBundle\Outbox\OutboxMessage;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskCreatedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\CallbackBus;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\InMemoryOutboxStorage;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\LosingLockStore;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\RecordingBus;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\UnavailableTransport;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Stamp\MessageDecodingFailedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

use function array_map;
use function mb_check_encoding;
use function mb_strlen;
use function preg_replace;
use function sprintf;
use function str_repeat;
use function str_starts_with;
use function time;

use const SIGINT;
use const SIGTERM;

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
        self::assertStringContainsString('No outbox messages are due.', self::display($tester));
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
        self::assertStringContainsString('No outbox messages are due.', self::display($tester));
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
        $this->storage->failRecordingAttempts = true;

        $tester = $this->execute();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Stopping: the outbox storage failed (RuntimeException: Could not record an attempt to relay message "p1": RuntimeException: Database is down.)', self::display($tester));
        self::assertSame([], $this->async->getSent());
    }

    public function test_each_attempt_is_counted_before_the_message_is_sent(): void
    {
        $message = $this->store(new CreateTaskCommand('1', 'a'), 'async');
        $seenBeforeSending = null;
        $bus = new CallbackBus(function () use ($message, &$seenBeforeSending): void {
            // What a process that dies right now (fatal error, out of memory) leaves behind.
            $seenBeforeSending = [$this->storage->attempts($message->id), $this->storage->failures[$message->id]['error'] ?? null, $this->storage->fetchUnpublished(10)];
        });

        (new CommandTester(new OutboxRelayCommand($this->storage, new PhpSerializer(), $bus, $this->locks)))->execute([]);

        self::assertNotNull($seenBeforeSending);
        self::assertSame(1, $seenBeforeSending[0]);
        self::assertSame(OutboxRelayCommand::INTERRUPTED, $seenBeforeSending[1]);
        self::assertSame([], $seenBeforeSending[2], 'The next run does not start with the same message again.');
        self::assertTrue($this->storage->isPublished($message->id));
    }

    public function test_a_transport_failure_counts_against_three_times_the_maximum_number_of_attempts(): void
    {
        // The broker is down: the attempt counts, but an outage gets about a day before messages are given up.
        $this->storage->store($this->outboxMessage('m1', new CreateTaskCommand('1', 'a'), attempts: 9));
        $bus = new RecordingBus(new TransportException('Connection refused'));

        $tester = new CommandTester(new OutboxRelayCommand($this->storage, new PhpSerializer(), $bus, $this->locks));
        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Failed to relay message "m1" (attempt 10 of 30, next attempt after', self::display($tester));
        self::assertSame(10, $this->storage->attempts('m1'));
        $retryAt = $this->storage->failures['m1']['retryAt'];
        self::assertNotNull($retryAt);
        self::assertEqualsWithDelta(time() + 3600, $retryAt->getTimestamp(), 5);
    }

    public function test_a_message_whose_transport_keeps_failing_is_given_up_after_three_times_the_maximum_number_of_attempts(): void
    {
        // e.g. the broker rejects the message because it is too large.
        $this->storage->store($this->outboxMessage('bad', new CreateTaskCommand('bad', 'b'), attempts: 29));
        $this->storage->store($this->outboxMessage('good', new CreateTaskCommand('good', 'a')));
        $bus = new CallbackBus(static function (object $message): void {
            if ($message instanceof CreateTaskCommand && 'bad' === $message->id) {
                throw new TransportException('Message too large for the broker');
            }
        });

        $tester = new CommandTester(new OutboxRelayCommand($this->storage, new PhpSerializer(), $bus, $this->locks));
        $tester->execute([]);

        self::assertStringContainsString('Gave up on message "bad" after 30 attempt(s): Symfony\Component\Messenger\Exception\TransportException: Message too large for the broker', self::display($tester));
        self::assertNull($this->storage->failures['bad']['retryAt']);
        self::assertTrue($this->storage->isPublished('good'));
    }

    public function test_a_handler_that_fails_to_send_another_message_counts_against_the_maximum_number_of_attempts(): void
    {
        // The message was handled inline and its handler ran: its side effects must not repeat for a day.
        $this->storage->store($this->outboxMessage('inline', new CreateTaskCommand('1', 'a'), attempts: 9));
        $bus = new CallbackBus(static function (object $message): void {
            throw new HandlerFailedException(new Envelope($message), [new TransportException('Connection refused')]);
        });

        $tester = new CommandTester(new OutboxRelayCommand($this->storage, new PhpSerializer(), $bus, $this->locks));
        $tester->execute([]);

        self::assertStringContainsString('Gave up on message "inline" after 10 attempt(s)', self::display($tester));
        self::assertNull($this->storage->failures['inline']['retryAt']);
    }

    public function test_a_message_that_fails_on_its_own_is_given_up_after_the_last_attempt_even_if_it_is_the_oldest(): void
    {
        $this->storage->store($this->outboxMessage('bad', new CreateTaskCommand('bad', 'b'), attempts: 9));
        $this->storage->store($this->outboxMessage('good', new CreateTaskCommand('good', 'a')));
        $bus = new CallbackBus(static function (object $message): void {
            if ($message instanceof CreateTaskCommand && 'bad' === $message->id) {
                throw new \DomainException('Handler rejected the message');
            }
        });

        $tester = new CommandTester(new OutboxRelayCommand($this->storage, new PhpSerializer(), $bus, $this->locks));
        $tester->execute([]);

        self::assertStringContainsString('Gave up on message "bad" after 10 attempt(s): DomainException: Handler rejected the message', self::display($tester));
        self::assertNull($this->storage->failures['bad']['retryAt']);
    }

    public function test_a_message_whose_last_attempt_killed_the_process_is_given_up_without_trying_again(): void
    {
        $this->storage->store($this->outboxMessage('crashed', new CreateTaskCommand('1', 'a'), attempts: 10, lastError: OutboxRelayCommand::INTERRUPTED));
        $bus = new RecordingBus();

        $tester = new CommandTester(new OutboxRelayCommand($this->storage, new PhpSerializer(), $bus, $this->locks));
        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Gave up on message "crashed" after 10 attempt(s): The relay did not finish this attempt', self::display($tester));
        self::assertStringContainsString('the message may have been sent', self::display($tester));
        self::assertSame([], $bus->messageClasses());
        self::assertNull($this->storage->failures['crashed']['retryAt']);
    }

    public function test_a_message_over_a_lowered_maximum_gets_one_more_attempt_and_keeps_its_real_error(): void
    {
        // max_attempts was lowered from 10 to 3 while the message had failed 5 times.
        $this->storage->store($this->outboxMessage('m1', new CreateTaskCommand('1', 'a'), attempts: 5, lastError: 'DomainException: Handler rejected the message'));
        $bus = new RecordingBus(new \DomainException('Handler rejected the message again'));

        $tester = new CommandTester(new OutboxRelayCommand($this->storage, new PhpSerializer(), $bus, $this->locks, maxAttempts: 3));
        $tester->execute([]);

        self::assertCount(1, $bus->messageClasses());
        self::assertStringContainsString('Gave up on message "m1" after 6 attempt(s): DomainException: Handler rejected the message again', self::display($tester));
        self::assertSame('DomainException: Handler rejected the message again', $this->storage->lastError('m1'));
    }

    public function test_a_failing_storage_stops_the_run_with_exit_code_1(): void
    {
        $this->store(new CreateTaskCommand('1', 'a'), 'async');
        $this->storage->failFetching = true;

        $tester = $this->execute();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Stopping: the outbox storage failed (RuntimeException: Database is down.)', self::display($tester));
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

    public function test_a_message_that_cannot_be_marked_published_stops_the_run(): void
    {
        $message = $this->store(new CreateTaskCommand('1', 'a'), 'async');
        $this->store(new CreateTaskCommand('2', 'b'), 'async');
        $this->storage->failMarkingPublished = [$message->id];

        $tester = $this->execute();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString(sprintf('Stopping: the outbox storage failed (RuntimeException: Message "%s" was sent but could not be marked as published, so it will be sent again: RuntimeException: Cannot mark', $message->id), self::display($tester));
        self::assertCount(1, $this->async->getSent(), 'The storage failed: the run stops.');
        self::assertSame(1, $this->storage->attempts($message->id), 'The message is sent again later (at-least-once).');
        self::assertSame(OutboxRelayCommand::INTERRUPTED, $this->storage->lastError($message->id), 'It is not recorded as a failure of the message.');
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

    public function test_a_failing_transport_is_paused_for_the_rest_of_the_run(): void
    {
        for ($i = 1; $i <= 20; ++$i) {
            $this->store(new CreateTaskCommand((string) $i, 'x'), 'async');
        }
        $bus = new RecordingBus(new TransportException('Connection refused'));

        $tester = new CommandTester(new OutboxRelayCommand($this->storage, new PhpSerializer(), $bus, $this->locks));
        $tester->execute(['--limit' => '100']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Transport "async" failed 3 times in a row; its other messages wait for the next run.', self::display($tester));
        self::assertCount(3, $bus->messageClasses(), 'The backlog of a transport that is down is not walked.');
        self::assertCount(20, $this->storage->unpublishedIds());
        self::assertCount(3, $this->storage->failures, 'Only the messages that were tried are postponed.');
    }

    public function test_a_failing_transport_does_not_hold_up_the_other_transports(): void
    {
        $ext = new UnavailableTransport();
        // The messages of the transport that is down are the oldest ones.
        for ($i = 1; $i <= 5; ++$i) {
            $this->store(new CreateTaskCommand('ext-'.$i, 'x'), 'ext');
        }
        $this->store(new CreateTaskCommand('async-1', 'x'), 'async');
        $this->store(new CreateTaskCommand('async-2', 'x'), 'async');

        $tester = new CommandTester(new OutboxRelayCommand($this->storage, new PhpSerializer(), $this->bus(['ext' => $ext]), $this->locks));
        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Transport "ext" failed 3 times in a row', self::display($tester));
        self::assertSame(3, $ext->sendAttempts);
        self::assertSame(['async-1', 'async-2'], array_map(static fn (Envelope $envelope): string => self::taskId($envelope), $this->async->getSent()));
        self::assertCount(5, $this->storage->unpublishedIds());
    }

    public function test_messages_without_a_transport_name_share_one_breaker(): void
    {
        for ($i = 1; $i <= 4; ++$i) {
            $this->store(new TaskCreatedEvent((string) $i));
        }
        $this->store(new CreateTaskCommand('1', 'x'), 'async');

        $tester = new CommandTester(new OutboxRelayCommand($this->storage, new PhpSerializer(), $this->bus(['events' => new UnavailableTransport()]), $this->locks));
        $tester->execute([]);

        self::assertStringContainsString('Messages without a transport name failed to be sent 3 times in a row; the other ones wait for the next run.', self::display($tester));
        self::assertCount(3, $this->storage->failures);
        self::assertCount(1, $this->async->getSent());
    }

    public function test_a_sent_message_resets_the_consecutive_failures_of_its_transport(): void
    {
        foreach (['f1', 'f2', 'ok1', 'f3', 'f4', 'ok2'] as $id) {
            $this->store(new CreateTaskCommand($id, 'x'), 'async');
        }
        $bus = new CallbackBus(static function (object $message): void {
            if ($message instanceof CreateTaskCommand && str_starts_with($message->id, 'f')) {
                throw new TransportException('Message rejected by the broker');
            }
        });

        $tester = new CommandTester(new OutboxRelayCommand($this->storage, new PhpSerializer(), $bus, $this->locks));
        $tester->execute([]);

        self::assertStringNotContainsString('times in a row', self::display($tester));
        self::assertStringContainsString('Relayed 2 message(s).', self::display($tester));
        self::assertCount(4, $this->storage->failures);
    }

    public function test_a_message_claimed_by_another_relay_is_skipped(): void
    {
        $taken = $this->store(new CreateTaskCommand('1', 'a'), 'async');
        $this->store(new CreateTaskCommand('2', 'b'), 'async');
        $this->storage->afterFetch = function () use ($taken): void {
            // An overlapping relay claims the message between this relay's fetch and its claim.
            $this->storage->afterFetch = null;
            $this->storage->recordAttempt($taken->id, 1, OutboxRelayCommand::INTERRUPTED, new DateTimeImmutable('+1 minute'), 0);
        };

        $tester = $this->execute();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Skipped 1 message(s) that another relay claimed first.', self::display($tester));
        self::assertSame(['2'], array_map(static fn (Envelope $envelope): string => self::taskId($envelope), $this->async->getSent()));
    }

    public function test_a_signal_stops_the_run_after_the_current_message(): void
    {
        for ($i = 1; $i <= 3; ++$i) {
            $this->store(new CreateTaskCommand((string) $i, 'x'), 'async');
        }
        $relay = new class {
            public ?OutboxRelayCommand $command = null;
        };
        $bus = new CallbackBus(static function () use ($relay): void {
            // SIGTERM (15) arrives while the first message is sent.
            self::assertNotNull($relay->command);
            self::assertFalse($relay->command->handleSignal(15), 'The current message is finished first.');
        });
        $command = $relay->command = new OutboxRelayCommand($this->storage, new PhpSerializer(), $bus, $this->locks);

        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Stopped by signal 15 after 1 message(s); the remaining messages wait for the next run.', self::display($tester));
        self::assertCount(2, $this->storage->unpublishedIds());
        self::assertSame(0, $this->storage->attempts($this->storage->unpublishedIds()[0]));
        self::assertTrue($this->locks->createLock('somework:cqrs:outbox:relay')->acquire(), 'The lock is released.');

        self::assertFalse($command->handleSignal(15), 'Each run starts without a pending stop.');
        self::assertSame(128 + 15, $command->handleSignal(15), 'A second signal stops right away.');
    }

    #[RequiresPhpExtension('pcntl')]
    public function test_subscribes_to_the_termination_signals(): void
    {
        $command = new OutboxRelayCommand($this->storage, new PhpSerializer(), $this->bus(), $this->locks);

        self::assertSame([SIGTERM, SIGINT], $command->getSubscribedSignals());
    }

    public function test_warns_when_a_message_was_neither_sent_nor_handled(): void
    {
        // e.g. Messenger's DeduplicateMiddleware dropped it: nothing more to do.
        $message = $this->store(new CreateTaskCommand('1', 'a'), 'async');

        $tester = new CommandTester(new OutboxRelayCommand($this->storage, new PhpSerializer(), new MessageBus([]), $this->locks));
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('was neither sent to a transport nor handled', self::display($tester));
        self::assertTrue($this->storage->isPublished($message->id));
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

    private function outboxMessage(string $id, object $message, int $attempts = 0, ?string $lastError = null): OutboxMessage
    {
        $encoded = OutboxMessage::fromEnvelope(new Envelope($message), new PhpSerializer(), 'async');

        return new OutboxMessage($id, $encoded->body, $encoded->headers, new DateTimeImmutable(), 'async', $attempts, $lastError);
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

    /**
     * @param array<string, SenderInterface> $transports Transports replacing or added to "async" and "events"
     */
    private function bus(array $transports = []): MessageBus
    {
        $factories = ['async' => fn (): SenderInterface => $this->async, 'events' => fn (): SenderInterface => $this->events];
        foreach ($transports as $name => $transport) {
            $factories[$name] = static fn (): SenderInterface => $transport;
        }
        $senders = new SendersLocator([TaskCreatedEvent::class => ['events']], new ServiceLocator($factories));

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
