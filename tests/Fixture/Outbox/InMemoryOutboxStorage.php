<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Outbox;

use DateTimeImmutable;
use SomeWork\CqrsBundle\Contract\OutboxStorage;
use SomeWork\CqrsBundle\Outbox\OutboxMessage;

use function array_slice;
use function in_array;
use function sprintf;

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

    /** Whether markFailed() fails, e.g. because the database is down. */
    public bool $failMarkingFailed = false;

    /** Whether fetchUnpublished() fails, e.g. because the database is down. */
    public bool $failFetching = false;

    /** When false, failed messages are returned again right away (a storage ignoring the retry time). */
    public bool $postponeFailures = true;

    public function store(OutboxMessage $message): void
    {
        $this->messages[$message->id] = $message;
    }

    public function fetchUnpublished(int $limit): array
    {
        if ($this->failFetching) {
            throw new \RuntimeException('Database is down.');
        }

        $now = new DateTimeImmutable();
        $due = [];
        foreach ($this->messages as $id => $message) {
            $failure = $this->failures[$id] ?? null;
            if (isset($this->published[$id])) {
                continue;
            }
            if (null !== $failure && $this->postponeFailures && (null === $failure['retryAt'] || $failure['retryAt'] > $now)) {
                continue;
            }
            $due[] = $message;
        }

        return array_slice($due, 0, $limit);
    }

    public function markPublished(string $id): void
    {
        if (in_array($id, $this->failMarkingPublished, true)) {
            throw new \RuntimeException(sprintf('Cannot mark "%s" as published.', $id));
        }

        $this->published[$id] = new DateTimeImmutable();
    }

    public function markFailed(string $id, int $attempts, string $error, ?DateTimeImmutable $retryAt): void
    {
        if ($this->failMarkingFailed) {
            throw new \RuntimeException('Database is down.');
        }

        if (!isset($this->messages[$id])) {
            throw new \RuntimeException(sprintf('Unknown message "%s".', $id));
        }

        if (isset($this->published[$id])) {
            return;
        }

        $message = $this->messages[$id];
        $this->messages[$id] = new OutboxMessage($message->id, $message->body, $message->headers, $message->createdAt, $message->transportName, $attempts);
        $this->failures[$id] = ['error' => $error, 'retryAt' => $retryAt];
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
