<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Outbox\Relay;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Outbox\OutboxMessage;
use SomeWork\CqrsBundle\Outbox\Relay\OutboxRelay;
use SomeWork\CqrsBundle\Outbox\Relay\RelayReporter;
use SomeWork\CqrsBundle\Outbox\Relay\RelayResult;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
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
