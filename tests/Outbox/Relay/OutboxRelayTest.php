<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Outbox\Relay;

use DateTimeImmutable;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\DeadlockException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Outbox\OutboxMessage;
use SomeWork\CqrsBundle\Outbox\Relay\OutboxRelay;
use SomeWork\CqrsBundle\Outbox\Relay\RelayReporter;
use SomeWork\CqrsBundle\Outbox\Relay\RelayResult;
use SomeWork\CqrsBundle\Outbox\Relay\RelayUnitOfWork;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\CallbackBus;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\InMemoryOutboxStorage;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\RecordingBus;
use SomeWork\CqrsBundle\Tests\Fixture\Service\RecordingLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\SentStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Contracts\Service\ResetInterface;

use function sprintf;
use function str_contains;

#[CoversClass(OutboxRelay::class)]
#[CoversClass(RelayResult::class)]
final class OutboxRelayTest extends TestCase
{
    private InMemoryOutboxStorage $storage;

    protected function setUp(): void
    {
        $this->storage = new InMemoryOutboxStorage();
        foreach (['m1', 'm2', 'm3'] as $id) {
            $encoded = OutboxMessage::fromEnvelope(new Envelope(new CreateTaskCommand($id, 'task')), new PhpSerializer(), 'async');
            $this->storage->store(new OutboxMessage($id, $encoded->body, $encoded->headers, new DateTimeImmutable(), 'async'));
        }
    }

    public function test_a_run_relays_up_to_the_limit(): void
    {
        $result = $this->relay()->run(2, $this->reporter());

        self::assertSame([2, 2, 0, 0, false], [$result->processed, $result->relayed, $result->failed, $result->claimedElsewhere, $result->aborted]);
        self::assertSame(['m3'], array_map(static fn (OutboxMessage $message): string => $message->id, $this->storage->fetchUnpublished(10)));
        self::assertTrue($this->storage->isPublished('m1'));
        self::assertTrue($this->storage->isPublished('m2'));
        self::assertFalse($this->storage->isPublished('m3'));
    }

    public function test_the_reporter_aborts_the_run_after_a_message(): void
    {
        $result = $this->relay()->run(10, $this->reporter(continueAfterMessage: false));

        self::assertTrue($result->aborted);
        self::assertSame(1, $result->relayed);
    }

    public function test_a_requested_stop_starts_no_message(): void
    {
        $result = $this->relay()->run(10, $this->reporter(stopRequested: true));

        self::assertSame(0, $result->processed);
        self::assertCount(3, $this->storage->fetchUnpublished(10));
    }

    public function test_a_retryable_failure_to_mark_as_published_is_retried_at_the_next_flush(): void
    {
        // e.g. a serialization failure while another relay overlaps: the run goes on.
        $failures = 1;
        $this->storage->beforeMarkingPublished = static function () use (&$failures): void {
            if ($failures-- > 0) {
                throw new DeadlockException(new class('could not serialize access', '40001') extends AbstractException {}, null);
            }
        };
        $time = 0.0;
        $relay = new OutboxRelay($this->storage, new PhpSerializer(), new RecordingBus(), clock: static function () use (&$time): float {
            return $time += 3.0; // every message is followed by a flush
        });

        $result = $relay->run(10, $this->reporter());

        self::assertSame(3, $result->relayed);
        // fetchUnpublished() also hides rows that are claimed but not marked, so check the marks.
        foreach (['m1', 'm2', 'm3'] as $id) {
            self::assertTrue($this->storage->isPublished($id), sprintf('"%s" was marked by a later flush.', $id));
        }
        self::assertSame([], $this->storage->unpublishedIds());
    }

    public function test_the_last_flush_of_a_run_does_not_hide_a_failure(): void
    {
        $this->storage->beforeMarkingPublished = static function (): void {
            throw new DeadlockException(new class('could not serialize access', '40001') extends AbstractException {}, null);
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('could not be marked as published, so they will be sent again');

        $this->relay()->run(10, $this->reporter());
    }

    public function test_a_message_whose_claim_was_lost_before_its_send_is_skipped(): void
    {
        $time = 1000.0;
        $sent = [];
        // The first send takes 25 seconds, so the claims are renewed before the next message;
        // meanwhile another relay took over m2, the message the relay is about to send.
        $bus = new CallbackBus(function (object $message) use (&$time, &$sent): void {
            self::assertInstanceOf(CreateTaskCommand::class, $message);
            $sent[] = $message->id;
            if ('m1' === $message->id) {
                $time += 25.0;
                $this->storage->interrupt('m2', 1, new DateTimeImmutable('+1 minute'));
            }
        });
        $relay = new OutboxRelay($this->storage, new PhpSerializer(), $bus, clock: static function () use (&$time): float {
            return $time;
        });

        $result = $relay->run(10, $this->reporter());

        self::assertSame([['m2', 'm3']], $this->storage->renewCalls);
        self::assertSame(['m1', 'm3'], $sent, 'The message another relay took over is not sent twice.');
        self::assertSame([2, 2, 0, 1, false], [$result->processed, $result->relayed, $result->failed, $result->claimedElsewhere, $result->aborted]);
        self::assertTrue($this->storage->isPublished('m1'));
        self::assertTrue($this->storage->isPublished('m3'));
        self::assertFalse($this->storage->isPublished('m2'), 'This relay does not mark a message it did not send.');
        self::assertNotContains('m2', array_merge(...$this->storage->publishCalls));
        self::assertNotContains('m2', $this->storage->released, 'The claim of the other relay is not released.');
        self::assertTrue($this->storage->isClaimed('m2'));
        self::assertSame(1, $this->storage->attempts('m2'), 'The attempt of the other relay is left as it is.');
    }

    public function test_the_maximum_number_of_attempts_must_be_positive(): void
    {
        $this->expectExceptionMessage('The maximum number of attempts must be at least 1, 0 given.');

        new OutboxRelay($this->storage, new PhpSerializer(), new RecordingBus(), maxAttempts: 0);
    }

    public function test_messages_retried_after_an_interrupted_attempt_are_marked_published_one_by_one(): void
    {
        // The process died during their last attempt, maybe because of one of them: each is marked as
        // published before the next one is sent, so a message that kills the process again is the only
        // one blamed and sent again (not every message sent before it in the run).
        foreach (['m1', 'm2', 'm3'] as $id) {
            $this->storage->interrupt($id, 1);
        }
        $publishedWhenSending = [];
        $bus = new CallbackBus(function () use (&$publishedWhenSending): void {
            $publishedWhenSending[] = [$this->storage->isPublished('m1'), $this->storage->isPublished('m2')];
        });

        $result = (new OutboxRelay($this->storage, new PhpSerializer(), $bus))->run(10, $this->reporter());

        self::assertSame(3, $result->relayed);
        self::assertSame([[false, false], [true, false], [true, true]], $publishedWhenSending);
        self::assertTrue($this->storage->isPublished('m3'));
    }

    public function test_each_dispatch_runs_in_the_unit_of_work_of_the_storage(): void
    {
        $unitOfWork = new class implements RelayUnitOfWork {
            public int $dispatches = 0;

            public function dispatchInUnitOfWork(\Closure $dispatch): mixed
            {
                ++$this->dispatches;

                return $dispatch();
            }
        };

        $result = (new OutboxRelay($this->storage, new PhpSerializer(), new RecordingBus(), unitOfWork: $unitOfWork))->run(10, $this->reporter());

        self::assertSame(3, $result->relayed);
        self::assertSame(3, $unitOfWork->dispatches);
    }

    public function test_the_services_are_reset_after_each_message_handled_in_this_process(): void
    {
        // m1 is handled inline (no transport, sync://), m2 sent to a transport, m3 fails in its handler.
        $bus = new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $envelope = Envelope::wrap($message, $stamps);

                $task = $envelope->getMessage();

                return match ($task instanceof CreateTaskCommand ? $task->id : null) {
                    'm1' => $envelope->with(new HandledStamp(null, 'handler')),
                    'm2' => $envelope->with(new SentStamp('transport')),
                    default => throw new HandlerFailedException($envelope, [new \RuntimeException('handler failed')]),
                };
            }
        };
        $resetter = new class implements ResetInterface {
            public int $resets = 0;

            public function reset(): void
            {
                ++$this->resets;
            }
        };

        $result = (new OutboxRelay($this->storage, new PhpSerializer(), $bus))->run(10, $this->reporter(), $resetter);

        self::assertSame([3, 2, 1], [$result->processed, $result->relayed, $result->failed]);
        self::assertSame(2, $resetter->resets);
    }

    public function test_a_failing_reset_does_not_fail_the_handled_message(): void
    {
        $bus = new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                return Envelope::wrap($message, $stamps)->with(new HandledStamp(null, 'handler'));
            }
        };
        $logger = new RecordingLogger();
        $resetter = new class implements ResetInterface {
            public function reset(): void
            {
                throw new \RuntimeException('reset failed');
            }
        };

        $result = (new OutboxRelay($this->storage, new PhpSerializer(), $bus, logger: $logger))->run(10, $this->reporter(), $resetter);

        self::assertSame(3, $result->relayed);
        self::assertTrue($logger->hasRecordContaining('error', 'Could not reset the services after relaying an outbox message'));
    }

    public function test_a_paused_transport_stays_paused_for_the_next_runs_of_the_same_relay(): void
    {
        // outbox:relay --watch runs every second: a broker that is down is tried again after 30
        // seconds, then 60, 120 … up to 5 minutes, instead of 3 failed sends every second.
        $this->storage->postponeFailures = false;
        $time = 1_000.0;
        $broker = new class {
            public bool $down = true;
        };
        $attempts = 0;
        $bus = new CallbackBus(static function () use ($broker, &$attempts): void {
            ++$attempts;
            if ($broker->down) {
                throw new TransportException('Connection refused');
            }
        });
        $logger = new RecordingLogger();
        $relay = new OutboxRelay($this->storage, new PhpSerializer(), $bus, logger: $logger, clock: static function () use (&$time): float {
            return $time;
        });
        $pauses = static fn (): array => array_values(array_map(
            static fn (array $record): mixed => $record['context']['seconds'] ?? null,
            array_filter($logger->records, static fn (array $record): bool => str_contains($record['message'], 'paused transport')),
        ));

        self::assertSame(3, $relay->run(10, $this->reporter())->failed);
        self::assertSame([30], $pauses());

        $time += 29;
        self::assertSame(0, $relay->run(10, $this->reporter())->processed, 'Still paused.');
        self::assertSame(3, $attempts);

        $time += 2;
        $relay->run(10, $this->reporter());
        self::assertSame([30, 60], $pauses(), 'Paused again, twice as long.');

        $time += 61;
        $broker->down = false;
        self::assertSame(3, $relay->run(10, $this->reporter())->relayed);

        // A transport that went through starts over at 30 seconds.
        foreach (['m4', 'm5', 'm6'] as $id) {
            $encoded = OutboxMessage::fromEnvelope(new Envelope(new CreateTaskCommand($id, 'task')), new PhpSerializer(), 'async');
            $this->storage->store(new OutboxMessage($id, $encoded->body, $encoded->headers, new DateTimeImmutable(), 'async'));
        }
        $broker->down = true;
        $relay->run(10, $this->reporter());
        self::assertSame([30, 60, 30], $pauses());

        // Another instance (a cron run) does not know about the pause.
        self::assertSame(3, (new OutboxRelay($this->storage, new PhpSerializer(), $bus))->run(10, $this->reporter())->processed);
    }

    private function relay(): OutboxRelay
    {
        return new OutboxRelay($this->storage, new PhpSerializer(), new RecordingBus());
    }

    private function reporter(bool $continueAfterMessage = true, bool $stopRequested = false): RelayReporter
    {
        return new class($continueAfterMessage, $stopRequested) implements RelayReporter {
            public function __construct(private readonly bool $continue, private readonly bool $stop)
            {
            }

            public function attemptFailed(OutboxMessage $message, int $attempt, int $maxAttempts, DateTimeImmutable $retryAt, string $error): void
            {
            }

            public function claimedElsewhereAfterFailure(OutboxMessage $message, string $error): void
            {
            }

            public function gaveUp(OutboxMessage $message, int $attempts, string $error): void
            {
            }

            public function notSent(string $warning): void
            {
            }

            public function transportPaused(?string $transportName, int $failures, int $seconds): void
            {
            }

            public function continueAfterMessage(): bool
            {
                return $this->continue;
            }

            public function stopRequested(): bool
            {
                return $this->stop;
            }
        };
    }
}
