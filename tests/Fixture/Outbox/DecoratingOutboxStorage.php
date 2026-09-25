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

    public function claim(array $messages, array $retryAt, string $token): array
    {
        return $this->inner->claim($messages, $retryAt, $token);
    }

    public function release(array $messages, string $token): void
    {
        $this->inner->release($messages, $token);
    }

    public function markPublished(array $ids): void
    {
        $this->inner->markPublished($ids);
    }

    public function recordFailure(string $id, string $token, int $attempts, string $error, ?DateTimeImmutable $retryAt): bool
    {
        return $this->inner->recordFailure($id, $token, $attempts, $error, $retryAt);
    }

    public function purgePublished(DateTimeImmutable $publishedBefore): int
    {
        return $this->inner->purgePublished($publishedBefore);
    }
}
