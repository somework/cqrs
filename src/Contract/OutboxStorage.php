<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Contract;

use DateTimeImmutable;
use SomeWork\CqrsBundle\Outbox\OutboxMessage;

/**
 * Persists messages in a transactional outbox for reliable async dispatch.
 *
 * @internal Promote to @api in a future minor release after real-world validation
 */
interface OutboxStorage
{
    /**
     * Stores a message; call it inside the database transaction of the business change.
     */
    public function store(OutboxMessage $message): void;

    /**
     * Returns unpublished messages, oldest first.
     *
     * @param int $offset number of unpublished messages to skip (the relay skips messages that failed in the current run)
     *
     * @return list<OutboxMessage>
     */
    public function fetchUnpublished(int $limit, int $offset = 0): array;

    /**
     * Marks a message as published. Marking an already published message is a no-op.
     *
     * @throws \RuntimeException when the message does not exist
     */
    public function markPublished(string $id): void;

    /**
     * Deletes messages published before the given date and returns how many were deleted.
     */
    public function purgePublished(DateTimeImmutable $publishedBefore): int;
}
