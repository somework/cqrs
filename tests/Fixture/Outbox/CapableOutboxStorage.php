<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Outbox;

use DateTimeImmutable;
use SomeWork\CqrsBundle\Contract\Outbox\FailedOutboxMessages;
use SomeWork\CqrsBundle\Contract\Outbox\OutboxMonitoring;
use SomeWork\CqrsBundle\Contract\Outbox\OutboxSchema;
use SomeWork\CqrsBundle\Contract\OutboxStorage;
use SomeWork\CqrsBundle\Outbox\FailedOutboxMessage;
use SomeWork\CqrsBundle\Outbox\OutboxMessage;
use SomeWork\CqrsBundle\Outbox\OutboxStatus;

use function array_slice;
use function count;

/**
 * A storage of an application (not the DBAL one) that implements every capability.
 */
final class CapableOutboxStorage implements OutboxStorage, OutboxSchema, FailedOutboxMessages, OutboxMonitoring
{
    public int $setups = 0;

    /** @var list<string> */
    public array $pendingChanges = [];

    /** @var list<FailedOutboxMessage> */
    public array $failed = [];

    /** @var list<array{list<string>, string|null}> */
    public array $requeued = [];

    public OutboxStatus $status;

    public function __construct(private readonly OutboxStorage $inner = new InMemoryOutboxStorage())
    {
        $this->status = new OutboxStatus(0, null, 0, null, 0);
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

    public function setup(?\Closure $onWait = null): void
    {
        ++$this->setups;
    }

    public function pendingChanges(): array
    {
        return $this->pendingChanges;
    }

    public function fetchFailed(int $limit): array
    {
        return array_slice($this->failed, 0, $limit);
    }

    public function requeueFailed(array $ids = [], ?string $transportName = null): int
    {
        $this->requeued[] = [$ids, $transportName];

        return [] === $ids ? count($this->failed) : count($ids);
    }

    public function status(): OutboxStatus
    {
        return $this->status;
    }
}
