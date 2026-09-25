<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Outbox;

use DateTimeImmutable;
use SomeWork\CqrsBundle\Contract\OutboxStorage;
use SomeWork\CqrsBundle\Outbox\OutboxMessage;

/**
 * A decorator of the outbox storage, as an application registers one (e.g. to add logging).
 */
final class DecoratingOutboxStorage implements OutboxStorage
{
    public function __construct(private readonly OutboxStorage $inner)
    {
    }

    public function store(OutboxMessage $message): void
    {
        $this->inner->store($message);
    }

    public function fetchUnpublished(int $limit, array $excludedTransports = []): array
    {
        return $this->inner->fetchUnpublished($limit, $excludedTransports);
    }

    public function markPublished(string $id): void
    {
        $this->inner->markPublished($id);
    }

    public function recordAttempt(string $id, int $attempts, string $error, ?DateTimeImmutable $retryAt, ?int $previousAttempts = null): bool
    {
        return $this->inner->recordAttempt($id, $attempts, $error, $retryAt, $previousAttempts);
    }

    public function purgePublished(DateTimeImmutable $publishedBefore): int
    {
        return $this->inner->purgePublished($publishedBefore);
    }
}
