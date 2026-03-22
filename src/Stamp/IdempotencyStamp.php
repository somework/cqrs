<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Carries an idempotency key for message deduplication.
 *
 * Consumers or middleware can inspect this stamp to skip
 * duplicate processing of the same logical operation.
 *
 * @api
 */
final class IdempotencyStamp implements StampInterface
{
    public function __construct(
        private readonly string $key,
    ) {
        if ('' === $this->key) {
            throw new \InvalidArgumentException('Idempotency key cannot be empty.');
        }
    }

    /**
     * @return non-empty-string
     */
    public function getKey(): string
    {
        return $this->key;
    }
}
