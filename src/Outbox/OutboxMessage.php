<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Outbox;

/**
 * Immutable DTO representing a message persisted in the transactional outbox.
 */
final class OutboxMessage
{
    public function __construct(
        public readonly string $id,
        public readonly string $body,
        public readonly string $headers,
        public readonly \DateTimeImmutable $createdAt,
        public readonly ?string $transportName = null,
    ) {
    }
}
