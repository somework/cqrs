<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Outbox\Signing;

use DateTimeImmutable;
use SomeWork\CqrsBundle\Contract\OutboxStorage;
use SomeWork\CqrsBundle\Outbox\OutboxMessage;

/**
 * Decorates the outbox storage: signs every message it stores (outbox.signing).
 *
 * @internal
 */
final class SigningOutboxStorage implements OutboxStorage
{
    public function __construct(
        private readonly OutboxStorage $inner,
        private readonly OutboxSigner $signer,
    ) {
    }

    public function store(OutboxMessage $message): void
    {
        $this->inner->store(new OutboxMessage(
            $message->id,
            $message->body,
            $message->headers,
            $message->createdAt,
            $message->transportName,
            $message->attempts,
            $message->lastError,
            $message->claimedAt,
            $message->availableAt,
            $this->signer->sign($message),
        ));
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
}
