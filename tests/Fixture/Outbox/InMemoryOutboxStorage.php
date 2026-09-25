<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Outbox;

use DateTimeImmutable;
use SomeWork\CqrsBundle\Contract\OutboxStorage;
use SomeWork\CqrsBundle\Outbox\OutboxMessage;

use function array_map;
use function array_slice;
use function in_array;
use function max;
use function sprintf;
use function usort;

final class InMemoryOutboxStorage implements OutboxStorage
{
    /** @var array<string, OutboxMessage> The stored rows, with their attempts, error, claim time and retry time */
    private array $messages = [];

    /** @var array<string, string> Claim tokens */
    private array $tokens = [];

    /** @var array<string, DateTimeImmutable> */
    private array $published = [];

    /** @var array<string, true> */
    private array $givenUp = [];

    /** @var array<string, array{error: string, retryAt: DateTimeImmutable|null}> Last recorded failure per message */
    public array $failures = [];

    /** @var list<string> */
    public array $failMarkingPublished = [];

    /** Whether claim() and recordFailure() fail, e.g. because the database is down. */
    public bool $failRecordingAttempts = false;

    /** Whether fetchUnpublished() fails, e.g. because the database is down. */
    public bool $failFetching = false;

    /** When false, attempted messages are returned again right away (a storage ignoring the retry time). */
    public bool $postponeFailures = true;

    /** @var (\Closure(list<OutboxMessage>): void)|null Called with every fetched batch, e.g. to let another relay claim a message */
    public ?\Closure $afterFetch = null;

    /** @var list<list<string>> Every markPublished() call */
    public array $publishCalls = [];

    /** @var list<string> Ids of every released claim */
    public array $released = [];

    public function store(OutboxMessage $message): void
    {
        $this->messages[$message->id] = $message;
    }

    public function fetchUnpublished(int $limit, array $excludedTransports = []): array
    {
        if ($this->failFetching) {
            throw new \RuntimeException('Database is down.');
        }

        $now = new DateTimeImmutable();
        /** @var array<string, array{new: list<array{DateTimeImmutable, OutboxMessage}>, retries: list<array{DateTimeImmutable, OutboxMessage}>}> $queues */
        $queues = [];
        foreach ($this->messages as $id => $message) {
            if (isset($this->published[$id]) || isset($this->givenUp[$id]) || in_array($message->transportName, $excludedTransports, true)) {
                continue;
            }
            $key = null === $message->transportName ? '' : '~'.$message->transportName;
            $queues[$key] ??= ['new' => [], 'retries' => []];
            if (null === $message->availableAt) {
                $queues[$key]['new'][] = [$message->createdAt, $message];
            } elseif (!$this->postponeFailures || $message->availableAt <= $now) {
                $queues[$key]['retries'][] = [$message->availableAt, $message];
            }
        }

        // Per transport: new messages in the order they were stored, then retries in the order of
        // their retry time (stable sorts keep the insertion order); the transports take turns, the
        // one whose next message has waited longest first.
        $lists = [];
        foreach ($queues as $queue) {
            usort($queue['new'], static fn (array $a, array $b): int => $a[0] <=> $b[0]);
            usort($queue['retries'], static fn (array $a, array $b): int => $a[0] <=> $b[0]);
            $entries = [...$queue['new'], ...$queue['retries']];
            if ([] !== $entries) {
                $lists[] = $entries;
            }
        }
        usort($lists, static fn (array $a, array $b): int => $a[0][0] <=> $b[0][0]);
        $lists = array_map(static fn (array $entries): array => array_map(static fn (array $entry): OutboxMessage => $entry[1], $entries), $lists);

        $due = [];
        for ($position = 0; [] !== $lists && $position < max(array_map(count(...), $lists)); ++$position) {
            foreach ($lists as $list) {
                if (isset($list[$position])) {
                    $due[] = $list[$position];
                }
            }
        }

        $batch = array_slice($due, 0, $limit);

        if (null !== $this->afterFetch) {
            ($this->afterFetch)($batch);
        }

        return $batch;
    }

    public function claim(array $messages, array $retryAt, string $token): array
    {
        if ($this->failRecordingAttempts) {
            throw new \RuntimeException('Database is down.');
        }

        $claimed = [];
        foreach ($messages as $fetched) {
            $stored = $this->messages[$fetched->id] ?? null;
            if (null === $stored || isset($this->published[$fetched->id]) || isset($this->givenUp[$fetched->id])
                || $stored->attempts !== $fetched->attempts || $stored->transportName !== $fetched->transportName) {
                continue;
            }

            $this->messages[$fetched->id] = self::with($stored, attempts: $stored->attempts + 1, claimedAt: new DateTimeImmutable(), availableAt: $retryAt[$fetched->attempts]);
            $this->tokens[$fetched->id] = $token;
            $claimed[] = $fetched->id;
        }

        return $claimed;
    }

    public function release(array $messages, string $token): void
    {
        foreach ($messages as $fetched) {
            if (($this->tokens[$fetched->id] ?? null) !== $token || isset($this->published[$fetched->id]) || isset($this->givenUp[$fetched->id])) {
                continue;
            }

            $stored = $this->messages[$fetched->id];
            $this->messages[$fetched->id] = self::with($stored, attempts: $stored->attempts - 1, claimedAt: $fetched->claimedAt, availableAt: $fetched->availableAt);
            unset($this->tokens[$fetched->id]);
            $this->released[] = $fetched->id;
        }
    }

    public function markPublished(array $ids): void
    {
        foreach ($ids as $id) {
            if (in_array($id, $this->failMarkingPublished, true)) {
                throw new \RuntimeException(sprintf('Cannot mark "%s" as published.', $id));
            }
        }

        $this->publishCalls[] = $ids;
        foreach ($ids as $id) {
            if (!isset($this->messages[$id]) || isset($this->published[$id])) {
                continue;
            }
            $this->published[$id] = new DateTimeImmutable();
            $this->messages[$id] = self::with($this->messages[$id], lastError: null, claimedAt: null);
            unset($this->failures[$id], $this->tokens[$id], $this->givenUp[$id]);
        }
    }

    public function recordFailure(string $id, string $token, int $attempts, string $error, ?DateTimeImmutable $retryAt): bool
    {
        if ($this->failRecordingAttempts) {
            throw new \RuntimeException('Database is down.');
        }

        if (($this->tokens[$id] ?? null) !== $token || isset($this->published[$id])) {
            return false;
        }

        $this->setFailure($id, $attempts, $error, $retryAt);

        return true;
    }

    public function purgePublished(DateTimeImmutable $publishedBefore): int
    {
        $deleted = 0;
        foreach ($this->published as $id => $publishedAt) {
            if ($publishedAt < $publishedBefore) {
                unset($this->messages[$id], $this->published[$id]);
                ++$deleted;
            }
        }

        return $deleted;
    }

    /**
     * Puts a message into the state a failed attempt leaves (for tests), without a claim.
     */
    public function setFailure(string $id, int $attempts, string $error, ?DateTimeImmutable $retryAt): void
    {
        if (!isset($this->messages[$id])) {
            throw new \RuntimeException(sprintf('Unknown message "%s".', $id));
        }

        $this->messages[$id] = self::with($this->messages[$id], attempts: $attempts, lastError: $error, claimedAt: null, availableAt: $retryAt);
        $this->failures[$id] = ['error' => $error, 'retryAt' => $retryAt];
        unset($this->tokens[$id]);
        if (null === $retryAt) {
            $this->givenUp[$id] = true;
        }
    }

    /**
     * Puts a message into the state of an attempt the process did not finish (for tests): claimed
     * by a relay that is gone, due again at $retryAt.
     */
    public function interrupt(string $id, int $attempts, DateTimeImmutable $retryAt = new DateTimeImmutable('-1 second')): void
    {
        $this->messages[$id] = self::with($this->messages[$id], attempts: $attempts, claimedAt: new DateTimeImmutable('-1 hour'), availableAt: $retryAt);
        $this->tokens[$id] = 'gone';
    }

    public function isPublished(string $id): bool
    {
        return isset($this->published[$id]);
    }

    public function isClaimed(string $id): bool
    {
        return isset($this->tokens[$id]);
    }

    public function attempts(string $id): int
    {
        return isset($this->messages[$id]) ? $this->messages[$id]->attempts : 0;
    }

    public function lastError(string $id): ?string
    {
        return $this->messages[$id]->lastError ?? null;
    }

    public function message(string $id): OutboxMessage
    {
        return $this->messages[$id];
    }

    /**
     * Unpublished messages, failed or not.
     *
     * @return list<string>
     */
    public function unpublishedIds(): array
    {
        $ids = [];
        foreach ($this->messages as $id => $message) {
            if (!isset($this->published[$id])) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private static function with(OutboxMessage $message, ?int $attempts = null, string|false|null $lastError = false, DateTimeImmutable|false|null $claimedAt = false, DateTimeImmutable|false|null $availableAt = false): OutboxMessage
    {
        return new OutboxMessage(
            $message->id,
            $message->body,
            $message->headers,
            $message->createdAt,
            $message->transportName,
            $attempts ?? $message->attempts,
            false === $lastError ? $message->lastError : $lastError,
            false === $claimedAt ? $message->claimedAt : $claimedAt,
            false === $availableAt ? $message->availableAt : $availableAt,
            $message->signature,
        );
    }
}
