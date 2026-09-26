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
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\CallbackBus;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\InMemoryOutboxStorage;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\RecordingBus;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

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
        self::assertSame([], $this->storage->fetchUnpublished(10), 'The ids of the failed flush were marked by a later one.');
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

            public function transportPaused(?string $transportName, int $failures): void
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
