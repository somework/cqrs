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
    /** @var array<string, OutboxMessage> */
    private array $messages = [];

    /** @var array<string, DateTimeImmutable> */
    private array $published = [];

    /** @var array<string, array{error: string, retryAt: DateTimeImmutable|null}> Last failure per message */
    public array $failures = [];

    /** @var list<string> */
    public array $failMarkingPublished = [];

    /** Whether recordAttempt() fails, e.g. because the database is down. */
    public bool $failRecordingAttempts = false;

    /** Whether fetchUnpublished() fails, e.g. because the database is down. */
    public bool $failFetching = false;

    /** When false, failed messages are returned again right away (a storage ignoring the retry time). */
    public bool $postponeFailures = true;

    /** @var (\Closure(list<OutboxMessage>): void)|null Called with every fetched batch, e.g. to let another relay claim a message */
    public ?\Closure $afterFetch = null;

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
            $failure = $this->failures[$id] ?? null;
            if (isset($this->published[$id]) || in_array($message->transportName, $excludedTransports, true)) {
                continue;
            }
            $key = null === $message->transportName ? '' : '~'.$message->transportName;
            $queues[$key] ??= ['new' => [], 'retries' => []];
            if (null === $failure) {
                $queues[$key]['new'][] = [$message->createdAt, $message];
            } elseif (!$this->postponeFailures || (null !== $failure['retryAt'] && $failure['retryAt'] <= $now)) {
                $queues[$key]['retries'][] = [$failure['retryAt'] ?? $now, $message];
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

    public function markPublished(string $id): void
    {
        if (in_array($id, $this->failMarkingPublished, true)) {
            throw new \RuntimeException(sprintf('Cannot mark "%s" as published.', $id));
        }

        $this->published[$id] = new DateTimeImmutable();
        unset($this->failures[$id]);
    }

    public function recordAttempt(string $id, int $attempts, string $error, ?DateTimeImmutable $retryAt, ?int $previousAttempts = null): bool
    {
        if ($this->failRecordingAttempts) {
            throw new \RuntimeException('Database is down.');
        }

        if (!isset($this->messages[$id])) {
            throw new \RuntimeException(sprintf('Unknown message "%s".', $id));
        }

        $message = $this->messages[$id];

        $givenUp = isset($this->failures[$id]) && null === $this->failures[$id]['retryAt'];

        if (isset($this->published[$id]) || (null !== $previousAttempts && ($previousAttempts !== $message->attempts || $givenUp))) {
            return false;
        }

        $this->messages[$id] = new OutboxMessage($message->id, $message->body, $message->headers, $message->createdAt, $message->transportName, $attempts, $error);
        $this->failures[$id] = ['error' => $error, 'retryAt' => $retryAt];

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

    public function isPublished(string $id): bool
    {
        return isset($this->published[$id]);
    }

    public function attempts(string $id): int
    {
        return isset($this->messages[$id]) ? $this->messages[$id]->attempts : 0;
    }

    public function lastError(string $id): ?string
    {
        return $this->messages[$id]->lastError ?? null;
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
}
