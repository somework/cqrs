<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Command;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Command\ConsoleRelayReporter;
use SomeWork\CqrsBundle\Command\OutboxRelayCommand;
use SomeWork\CqrsBundle\Contract\Outbox\OutboxStorage;
use SomeWork\CqrsBundle\Outbox\OutboxMessage;
use SomeWork\CqrsBundle\Outbox\Relay\OutboxRelay;
use SomeWork\CqrsBundle\Outbox\Signing\OutboxSigner;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskCreatedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\CallbackBus;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\CountingLockStore;
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
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\MessageDecodingFailedStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\SentStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

use function addslashes;
use function array_map;
use function array_slice;
use function count;
use function mb_check_encoding;
use function mb_strlen;
use function preg_replace;
use function serialize;
use function sprintf;
use function str_repeat;
use function str_starts_with;
use function time;

use const SIGINT;
use const SIGTERM;

#[CoversClass(OutboxRelayCommand::class)]
#[CoversClass(ConsoleRelayReporter::class)]
#[CoversClass(OutboxRelay::class)]
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
            $this->storage->store(new OutboxMessage($id, 'not a serialized envelope', '{}', new DateTimeImmutable('2026-01-01'), 'async'));
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
        self::assertStringContainsString('Stopping: the outbox storage failed (RuntimeException: Could not claim 2 outbox message(s): RuntimeException: Database is down.)', self::display($tester));
        self::assertSame([], $this->async->getSent());
    }

    public function test_each_attempt_is_counted_before_the_message_is_sent(): void
    {
        $message = $this->store(new CreateTaskCommand('1', 'a'), 'async');
        $seenBeforeSending = null;
        $bus = new CallbackBus(function () use ($message, &$seenBeforeSending): void {
            // What a process that dies right now (fatal error, out of memory) leaves behind.
            $seenBeforeSending = [$this->storage->attempts($message->id), $this->storage->isClaimed($message->id), $this->storage->fetchUnpublished(10)];
        });

        (new CommandTester(new OutboxRelayCommand($this->storage, new PhpSerializer(), $bus, $this->locks)))->execute([]);

        self::assertNotNull($seenBeforeSending);
        self::assertSame(1, $seenBeforeSending[0]);
        self::assertTrue($seenBeforeSending[1], 'Claimed: if the process dies now, the next relay knows the attempt was interrupted.');
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
        // 30 = three times max_attempts: the budget of transport failures, which an interrupted attempt may have been.
        $this->storage->store($this->outboxMessage('crashed', new CreateTaskCommand('1', 'a'), lastError: 'RuntimeException: Allowed memory size exhausted'));
        $this->storage->interrupt('crashed', 30);
        $bus = new RecordingBus();

        $tester = new CommandTester(new OutboxRelayCommand($this->storage, new PhpSerializer(), $bus, $this->locks));
        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Gave up on message "crashed" after 30 attempt(s): The last attempt did not finish', self::display($tester));
        self::assertStringContainsString('the message may have been sent. Previous error: RuntimeException: Allowed memory size exhausted', self::display($tester));
        self::assertSame([], $bus->messageClasses());
        self::assertNull($this->storage->failures['crashed']['retryAt']);
        self::assertSame(30, $this->storage->attempts('crashed'), 'The interrupted attempt already counted.');
        self::assertStringEndsWith('Previous error: RuntimeException: Allowed memory size exhausted', (string) $this->storage->lastError('crashed'));
    }

    public function test_an_interrupted_attempt_does_not_give_up_a_message_within_the_transport_budget(): void
    {
        // The broker was down for 11 attempts, then the relay was killed during the 12th.
        $this->storage->store($this->outboxMessage('m1', new CreateTaskCommand('1', 'a'), lastError: 'Symfony\Component\Messenger\Exception\TransportException: Connection refused'));
        $this->storage->interrupt('m1', 12);
        $bus = new RecordingBus();

        $tester = new CommandTester(new OutboxRelayCommand($this->storage, new PhpSerializer(), $bus, $this->locks));
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame([CreateTaskCommand::class], $bus->messageClasses(), 'The broker is back: the message is sent.');
        self::assertTrue($this->storage->isPublished('m1'));
    }

    public function test_the_error_of_the_previous_attempt_stays_while_a_message_is_sent(): void
    {
        $this->storage->store($this->outboxMessage('m1', new CreateTaskCommand('1', 'a'), attempts: 2, lastError: 'Symfony\Component\Messenger\Exception\TransportException: Connection refused'));
        $this->storage->store($this->outboxMessage('m2', new CreateTaskCommand('2', 'b'), lastError: 'DomainException: boom'));
        $this->storage->interrupt('m2', 3);
        $whileSending = [];
        $bus = new CallbackBus(function (object $message) use (&$whileSending): void {
            self::assertInstanceOf(CreateTaskCommand::class, $message);
            // What a process killed right now leaves behind.
            $id = '1' === $message->id ? 'm1' : 'm2';
            $whileSending[$id] = $this->storage->lastError($id);
        });

        (new CommandTester(new OutboxRelayCommand($this->storage, new PhpSerializer(), $bus, $this->locks)))->execute([]);

        // A process killed right now leaves the real error of the previous attempt behind.
        self::assertSame([
            'm2' => 'DomainException: boom',
            'm1' => 'Symfony\Component\Messenger\Exception\TransportException: Connection refused',
        ], $whileSending);
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

    public function test_stored_errors_have_no_control_characters(): void
    {
        // Escape sequences in an exception message would reach the terminal of the operator.
        $message = $this->store(new CreateTaskCommand('1', 'a'), 'async');
        $bus = new RecordingBus(new \RuntimeException("declined \e]8;;http://evil.example\e\\click\e]8;;\e\\ \e[2J\nnext line \u{9B}31m"));

        $tester = new CommandTester(new OutboxRelayCommand($this->storage, new PhpSerializer(), $bus, $this->locks));
        $tester->execute([]);

        $error = $this->storage->failures[$message->id]['error'];
        self::assertSame('RuntimeException: declined  ]8;;http://evil.example \\click ]8;; \\  [2J next line  31m', $error);
        self::assertStringNotContainsString("\e", $tester->getDisplay());
    }

    public function test_stamps_of_a_dispatch_in_progress_are_not_taken_from_a_row(): void
    {
        // Serializers drop them when they encode an envelope: only a forged row holds them. A
        // ReceivedStamp would make the relay handle the message itself instead of sending it.
        $forged = new Envelope(new CreateTaskCommand('1', 'forged'), [new ReceivedStamp('async'), new SentStamp('async'), new HandledStamp('result', 'handler')]);
        $this->storage->store(new OutboxMessage('00000000-0000-7000-8000-000000000001', addslashes(serialize($forged)), '[]', new DateTimeImmutable(), 'async'));

        $this->execute();

        self::assertCount(1, $this->async->getSent());
        $sent = $this->async->getSent()[0];
        self::assertNull($sent->last(ReceivedStamp::class));
        self::assertNull($sent->last(HandledStamp::class));
    }

    public function test_a_message_for_a_transport_that_does_not_exist_is_given_up_at_once(): void
    {
        // A typo or a renamed transport fails every attempt: requeue it with the right name instead.
        $message = $this->store(new CreateTaskCommand('1', 'a'), 'async_events');
        $this->store(new CreateTaskCommand('2', 'b'), 'async');
        $transports = new ServiceLocator(['async' => fn (): SenderInterface => $this->async]);

        $tester = new CommandTester(new OutboxRelayCommand($this->storage, new PhpSerializer(), $this->bus(), $this->locks, transports: $transports));
        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(1, $this->storage->attempts($message->id));
        self::assertNull($this->storage->failures[$message->id]['retryAt']);
        self::assertStringContainsString('The transport "async_events" does not exist.', $this->storage->failures[$message->id]['error']);
        self::assertStringContainsString('--requeue --transport=<name> '.$message->id, self::display($tester));
        self::assertCount(1, $this->async->getSent(), 'The other messages are relayed.');
    }

    public function test_only_signed_messages_are_decoded(): void
    {
        $signer = new OutboxSigner('secret');
        $signed = $this->outboxMessage('signed', new CreateTaskCommand('1', 'a'));
        $this->storage->store(new OutboxMessage($signed->id, $signed->body, $signed->headers, $signed->createdAt, 'async', signature: $signer->sign($signed)));
        // Written by someone with access to the table, e.g. through an SQL injection elsewhere.
        $this->storage->store($this->outboxMessage('forged', new CreateTaskCommand('2', 'b')));
        $tampered = $this->outboxMessage('tampered', new CreateTaskCommand('3', 'c'));
        $this->storage->store(new OutboxMessage($tampered->id, $tampered->body, $tampered->headers, $tampered->createdAt, 'async', signature: $signer->sign($this->outboxMessage('tampered', new CreateTaskCommand('3', 'original')))));
        $decoded = [];
        $serializer = new class(new PhpSerializer(), $decoded) implements SerializerInterface {
            /** @param list<string> $decoded */
            public function __construct(private readonly PhpSerializer $inner, public array &$decoded)
            {
            }

            public function decode(array $encodedEnvelope): Envelope
            {
                $envelope = $this->inner->decode($encodedEnvelope);
                $message = $envelope->getMessage();
                $this->decoded[] = $message instanceof CreateTaskCommand ? $message->id : $message::class;

                return $envelope;
            }

            public function encode(Envelope $envelope): array
            {
                return $this->inner->encode($envelope);
            }
        };

        $tester = new CommandTester(new OutboxRelayCommand($this->storage, $serializer, $this->bus(), $this->locks, signer: $signer));
        $tester->execute([]);

        self::assertSame(['1'], $decoded, 'Rows without a valid signature never reach the serializer.');
        self::assertSame(['1'], array_map(static fn (Envelope $envelope): string => self::taskId($envelope), $this->async->getSent()));
        self::assertStringContainsString('Gave up on message "forged" after 1 attempt(s): The message is not signed, so it was not decoded', self::display($tester));
        self::assertStringContainsString('Gave up on message "tampered" after 1 attempt(s): The signature of the message does not match, so it was not decoded', self::display($tester));
        self::assertStringContainsString('somework:cqrs:outbox:failed --requeue --sign tampered', self::display($tester));
    }

    public function test_unsigned_messages_can_be_accepted_while_old_rows_drain(): void
    {
        $this->storage->store($this->outboxMessage('unsigned', new CreateTaskCommand('1', 'a')));
        $this->storage->store(new OutboxMessage('tampered', 'body', '{}', new DateTimeImmutable(), 'async', signature: 'v1:wrong'));

        $tester = new CommandTester(new OutboxRelayCommand($this->storage, new PhpSerializer(), $this->bus(), $this->locks, signer: new OutboxSigner('secret'), acceptUnsigned: 'true'));
        $tester->execute([]);

        self::assertCount(1, $this->async->getSent());
        self::assertStringContainsString('Gave up on message "tampered"', self::display($tester), 'A wrong signature is never accepted.');
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
        self::assertStringContainsString(sprintf('Stopping: the outbox storage failed (RuntimeException: 2 sent message(s) could not be marked as published, so they will be sent again (%s, ', $message->id), self::display($tester));
        self::assertCount(2, $this->async->getSent());
        self::assertSame(1, $this->storage->attempts($message->id), 'The message is sent again later (at-least-once).');
        self::assertTrue($this->storage->isClaimed($message->id), 'It is not recorded as a failure of the message: the next relay retries the interrupted attempt.');
        self::assertNull($this->storage->lastError($message->id));
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
        self::assertCount(17, $this->storage->released, 'The claims of the others are released.');
        self::assertSame(0, $this->storage->attempts($this->storage->released[0]));
        self::assertFalse($this->storage->isClaimed($this->storage->released[0]));
    }

    public function test_the_claims_of_a_long_batch_are_renewed_and_lost_ones_are_skipped(): void
    {
        foreach (['1', '2', '3'] as $taskId) {
            $this->store(new CreateTaskCommand($taskId, 'x'), 'async');
        }
        $ids = $this->storage->unpublishedIds();
        $time = 1000.0;
        $sent = [];
        // Each send takes 25 seconds; during the first one, another relay takes over the third message.
        $bus = new CallbackBus(function (object $message) use (&$time, &$sent, $ids): void {
            self::assertInstanceOf(CreateTaskCommand::class, $message);
            $time += 25.0;
            $sent[] = $message->id;
            if ('1' === $message->id) {
                $this->storage->interrupt($ids[2], 1, new DateTimeImmutable('+1 minute'));
            }
        });

        $tester = new CommandTester(new OutboxRelayCommand($this->storage, new PhpSerializer(), $bus, $this->locks, clock: static function () use (&$time): float {
            return $time;
        }));
        $tester->execute([]);

        self::assertSame(['1', '2'], $sent, 'The third message is not sent twice.');
        self::assertNotSame([], $this->storage->renewCalls);
        self::assertStringContainsString('Skipped 1 message(s) that another relay claimed first.', self::display($tester));
    }

    public function test_sent_messages_are_marked_published_while_other_sends_fail(): void
    {
        $this->store(new CreateTaskCommand('ok', 'x'), 'async');
        $this->store(new CreateTaskCommand('bad-1', 'x'), 'async');
        $this->store(new CreateTaskCommand('bad-2', 'x'), 'async');
        $okId = $this->storage->unpublishedIds()[0];
        $time = 1000.0;
        $publishedBeforeLastSend = null;
        $bus = new CallbackBus(function (object $message) use (&$time, &$publishedBeforeLastSend, $okId): void {
            self::assertInstanceOf(CreateTaskCommand::class, $message);
            $time += 3.0;
            if ('bad-2' === $message->id) {
                $publishedBeforeLastSend = $this->storage->isPublished($okId);
            }
            if ('ok' !== $message->id) {
                throw new TransportException('Timeout');
            }
        });

        (new CommandTester(new OutboxRelayCommand($this->storage, new PhpSerializer(), $bus, $this->locks, clock: static function () use (&$time): float {
            return $time;
        })))->execute([]);

        self::assertTrue($publishedBeforeLastSend, 'The first message is marked while later sends keep failing.');
    }

    public function test_the_claims_not_attempted_are_released_when_the_storage_fails(): void
    {
        foreach (['1', '2', '3'] as $taskId) {
            $this->store(new CreateTaskCommand($taskId, 'x'), 'async');
        }
        $bus = new CallbackBus(function (): void {
            // The database goes down: the failure cannot be recorded.
            $this->storage->failRecordingAttempts = true;

            throw new TransportException('Connection refused');
        });

        $tester = new CommandTester(new OutboxRelayCommand($this->storage, new PhpSerializer(), $bus, $this->locks));
        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        [, $second, $third] = $this->storage->unpublishedIds();
        self::assertSame([$second, $third], $this->storage->released);
        self::assertSame(0, $this->storage->attempts($third));
    }

    public function test_sent_messages_are_marked_published_in_batches(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            $this->store(new CreateTaskCommand((string) $i, 'x'), 'async');
        }
        $time = 1000.0;
        // Each message takes a second to send.
        $bus = new CallbackBus(static function () use (&$time): void {
            $time += 1.0;
        });

        $tester = new CommandTester(new OutboxRelayCommand($this->storage, new PhpSerializer(), $bus, $this->locks, clock: static function () use (&$time): float {
            return $time;
        }));
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame([2, 2, 1], array_map(count(...), $this->storage->publishCalls), 'Every 2 seconds, and at the end of the run.');
        self::assertSame([], $this->storage->unpublishedIds());
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

    public function test_a_transport_that_accepted_a_message_is_paused_only_after_ten_failures_in_a_row(): void
    {
        // Many messages are rejected by the broker (e.g. too large), but the transport is up.
        $rows = [];
        foreach (['ok1', 'r1', 'r2', 'r3', 'ok2', 'r4', 'r5', 'r6', 'r7', 'r8', 'r9', 'r10', 'r11', 'r12', 'r13', 'ok3'] as $taskId) {
            $rows[$taskId] = $this->store(new CreateTaskCommand($taskId, 'x'), 'async')->id;
        }
        $bus = new CallbackBus(static function (object $message): void {
            if ($message instanceof CreateTaskCommand && str_starts_with($message->id, 'r')) {
                throw new TransportException('Message too large');
            }
        });

        $tester = new CommandTester(new OutboxRelayCommand($this->storage, new PhpSerializer(), $bus, $this->locks));
        $tester->execute([]);

        self::assertTrue($this->storage->isPublished($rows['ok2']), 'Three rejections in a row do not pause a transport that works.');
        self::assertStringContainsString('Transport "async" failed 10 times in a row; its other messages wait for the next run.', self::display($tester));
        self::assertFalse($this->storage->isPublished($rows['ok3']));
    }

    public function test_a_transport_that_accepted_a_message_is_paused_after_three_failures_once_it_has_failed_for_ten_seconds(): void
    {
        // e.g. the broker went down during the run, and every send waits for its timeout.
        $rows = [];
        foreach (['ok', 'r1', 'r2', 'r3', 'r4'] as $taskId) {
            $rows[$taskId] = $this->store(new CreateTaskCommand($taskId, 'x'), 'async')->id;
        }
        $bus = new CallbackBus(static function (object $message): void {
            if ($message instanceof CreateTaskCommand && str_starts_with($message->id, 'r')) {
                throw new TransportException('Connection timed out');
            }
        });
        $now = 0.0;
        $clock = static function () use (&$now): float {
            return $now += 5.0;
        };

        $tester = new CommandTester(new OutboxRelayCommand($this->storage, new PhpSerializer(), $bus, $this->locks, clock: $clock));
        $tester->execute([]);

        self::assertStringContainsString('Transport "async" failed 3 times in a row; its other messages wait for the next run.', self::display($tester));
        self::assertSame(0, $this->storage->attempts($rows['r4']));
    }

    public function test_a_short_batch_does_not_end_the_run(): void
    {
        foreach (['1', '2', '3', '4', '5'] as $taskId) {
            $this->store(new CreateTaskCommand($taskId, 'x'), 'async');
        }
        // e.g. the storage left out rows that an overlapping relay claimed after they were chosen.
        $storage = new class($this->storage) implements OutboxStorage {
            public function __construct(private readonly OutboxStorage $storage)
            {
            }

            public function store(OutboxMessage $message): void
            {
                $this->storage->store($message);
            }

            public function fetchUnpublished(int $limit, array $excludedTransports = []): array
            {
                return array_slice($this->storage->fetchUnpublished($limit, $excludedTransports), 0, 2);
            }

            public function claim(array $messages, array $retryAt, string $token): array
            {
                return $this->storage->claim($messages, $retryAt, $token);
            }

            public function renew(array $messages, array $retryAt, string $token): array
            {
                return $this->storage->renew($messages, $retryAt, $token);
            }

            public function release(array $messages, string $token): void
            {
                $this->storage->release($messages, $token);
            }

            public function markPublished(array $ids): void
            {
                $this->storage->markPublished($ids);
            }

            public function recordFailure(string $id, string $token, int $attempts, string $error, ?DateTimeImmutable $retryAt): bool
            {
                return $this->storage->recordFailure($id, $token, $attempts, $error, $retryAt);
            }

            public function purgePublished(DateTimeImmutable $publishedBefore): int
            {
                return $this->storage->purgePublished($publishedBefore);
            }
        };

        $tester = new CommandTester(new OutboxRelayCommand($storage, new PhpSerializer(), $this->bus(), $this->locks));
        $tester->execute([]);

        self::assertStringContainsString('Relayed 5 message(s).', self::display($tester));
        self::assertCount(5, $this->async->getSent());
    }

    public function test_new_messages_are_relayed_before_retries(): void
    {
        // Rejected by the broker before (e.g. too large); they are due again, and older than the new messages.
        foreach (['r1', 'r2', 'r3', 'r4', 'r5'] as $id) {
            $this->storage->store($this->outboxMessage($id, new CreateTaskCommand($id, 'too large'), attempts: 1));
            $this->storage->setFailure($id, 1, 'Symfony\Component\Messenger\Exception\TransportException: Message too large', new DateTimeImmutable('-1 second'));
        }
        foreach (['n1', 'n2', 'n3'] as $id) {
            $this->storage->store($this->outboxMessage($id, new CreateTaskCommand($id, 'ok')));
        }
        $sent = [];
        $bus = new CallbackBus(static function (object $message) use (&$sent): void {
            self::assertInstanceOf(CreateTaskCommand::class, $message);
            if ('too large' === $message->name) {
                throw new TransportException('Message too large');
            }
            $sent[] = $message->id;
        });

        $tester = new CommandTester(new OutboxRelayCommand($this->storage, new PhpSerializer(), $bus, $this->locks));
        $tester->execute([]);

        self::assertSame(['n1', 'n2', 'n3'], $sent, 'The rejected messages do not hold up the new ones.');
        self::assertStringNotContainsString('times in a row', self::display($tester), 'The transport accepted the new messages: it is not paused.');
        self::assertSame(2, $this->storage->attempts('r5'));
    }

    public function test_a_signal_during_a_fetch_starts_no_further_message(): void
    {
        $this->store(new CreateTaskCommand('1', 'a'), 'async');
        $command = new OutboxRelayCommand($this->storage, new PhpSerializer(), $this->bus(), $this->locks);
        $this->storage->afterFetch = static function () use ($command): void {
            $command->handleSignal(15);
        };

        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Stopped by signal 15 after 0 message(s)', self::display($tester));
        self::assertSame([], $this->async->getSent());
        self::assertSame(0, $this->storage->attempts($this->storage->unpublishedIds()[0]));
    }

    public function test_a_failure_of_a_message_another_relay_claimed_meanwhile_is_reported(): void
    {
        $message = $this->store(new CreateTaskCommand('1', 'a'), 'async');
        $bus = new CallbackBus(function () use ($message): void {
            // An overlapping relay claims the message again while this relay still sends it.
            $this->storage->interrupt($message->id, 2, new DateTimeImmutable('+1 minute'));

            throw new \DomainException('boom');
        });

        $tester = new CommandTester(new OutboxRelayCommand($this->storage, new PhpSerializer(), $bus, $this->locks));
        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString(sprintf('Failed to relay message "%s", but another relay claimed it in the meantime: DomainException: boom', $message->id), self::display($tester));
        self::assertSame(2, $this->storage->attempts($message->id));
    }

    public function test_a_message_claimed_by_another_relay_is_skipped(): void
    {
        $taken = $this->store(new CreateTaskCommand('1', 'a'), 'async');
        $this->store(new CreateTaskCommand('2', 'b'), 'async');
        $this->storage->afterFetch = function () use ($taken): void {
            // An overlapping relay claims the message between this relay's fetch and its claim.
            $this->storage->afterFetch = null;
            $this->storage->interrupt($taken->id, 1, new DateTimeImmutable('+1 minute'));
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

    #[RequiresMethod(DeduplicateStamp::class, 'getKey')]
    public function test_a_retry_dropped_by_the_deduplication_is_not_marked_as_published(): void
    {
        // The lock is most likely held by the attempt before, which did not send the message.
        $failed = OutboxMessage::fromEnvelope(new Envelope(new CreateTaskCommand('1', 'a'), [new DeduplicateStamp('task-1')]), new PhpSerializer(), 'async');
        $this->storage->store($failed);
        $this->storage->setFailure($failed->id, 1, 'Connection refused', new DateTimeImmutable('-1 second'));
        $interrupted = OutboxMessage::fromEnvelope(new Envelope(new CreateTaskCommand('2', 'b'), [new DeduplicateStamp('task-2')]), new PhpSerializer(), 'async');
        $this->storage->store($interrupted);
        $this->storage->interrupt($interrupted->id, 1);
        $first = OutboxMessage::fromEnvelope(new Envelope(new CreateTaskCommand('3', 'c'), [new DeduplicateStamp('task-3')]), new PhpSerializer(), 'async');
        $this->storage->store($first);

        $tester = new CommandTester(new OutboxRelayCommand($this->storage, new PhpSerializer(), new MessageBus([]), $this->locks));
        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        foreach ([$failed, $interrupted] as $message) {
            self::assertFalse($this->storage->isPublished($message->id));
            self::assertSame(2, $this->storage->attempts($message->id));
            self::assertStringContainsString('deduplication dropped this retry', (string) $this->storage->lastError($message->id));
        }
        self::assertTrue($this->storage->isPublished($first->id), 'A first attempt dropped as a duplicate of another message is done.');
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

    public function test_the_lock_is_extended_every_ten_seconds(): void
    {
        // Not after every message: with Redis or a database, each extension is a round trip.
        foreach (['1', '2', '3', '4'] as $id) {
            $this->store(new CreateTaskCommand($id, 'a'), 'async');
        }
        $store = new CountingLockStore();
        $time = 1000.0;
        $clock = static function () use (&$time): float {
            return $time;
        };
        $sent = 0;
        // Each message takes 4 seconds to send.
        $bus = new CallbackBus(static function () use (&$time, &$sent): void {
            $time += 4.0;
            ++$sent;
        });

        $tester = new CommandTester(new OutboxRelayCommand($this->storage, new PhpSerializer(), $bus, new LockFactory($store), clock: $clock));
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(4, $sent);
        self::assertLessThan(4, $store->refreshes);
        self::assertGreaterThan(0, $store->refreshes);
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
