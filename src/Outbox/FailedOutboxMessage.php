<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Outbox;

use DateTimeImmutable;

/**
 * A message the relay gave up on.
 *
 * @api
 */
final class FailedOutboxMessage
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $transportName,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $failedAt,
        public readonly int $attempts,
        public readonly ?string $lastError,
    ) {
    }
}
