<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Outbox;

use DateTimeImmutable;
use SomeWork\CqrsBundle\Contract\Outbox\FailedOutboxMessages;
use SomeWork\CqrsBundle\Contract\Outbox\OutboxMonitoring;
use SomeWork\CqrsBundle\Contract\Outbox\OutboxSchema;
use SomeWork\CqrsBundle\Contract\Outbox\OutboxStorage;
use SomeWork\CqrsBundle\Outbox\FailedOutboxMessage;
use SomeWork\CqrsBundle\Outbox\OutboxMessage;
use SomeWork\CqrsBundle\Outbox\OutboxStatus;

use function array_slice;
use function count;
use function in_array;

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

    /** @var list<array{list<string>, string|null, (\Closure(OutboxMessage): string)|null}> */
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

    public function claim(array $messages, array $retryAt, string $token): array
    {
        return $this->inner->claim($messages, $retryAt, $token);
    }

    public function renew(array $messages, array $retryAt, string $token): array
    {
        return $this->inner->renew($messages, $retryAt, $token);
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

    public function setup(?\Closure $onWait = null): void
    {
        ++$this->setups;
    }

    public function pendingChanges(): array
    {
        return $this->pendingChanges;
    }

    public function fetchFailed(int $limit, array $ids = []): array
    {
        return array_slice(array_values(array_filter($this->failed, static fn (FailedOutboxMessage $message): bool => [] === $ids || in_array($message->id, $ids, true))), 0, $limit);
    }

    public function requeueFailed(array $ids = [], ?string $transportName = null, ?\Closure $sign = null): int
    {
        $this->requeued[] = [$ids, $transportName, $sign];

        return [] === $ids ? count($this->failed) : count($ids);
    }

    public function status(): OutboxStatus
    {
        return $this->status;
    }
}
