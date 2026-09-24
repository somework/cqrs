<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Contract;

use DateTimeImmutable;
use SomeWork\CqrsBundle\Outbox\OutboxMessage;

/**
 * Persists messages in a transactional outbox for reliable async dispatch.
 *
 * @api
 */
interface OutboxStorage
{
    /**
     * Stores a message; call it inside the database transaction of the business change.
     */
    public function store(OutboxMessage $message): void;

    /**
     * Returns the messages that are due, oldest first: unpublished, not given up, and past the
     * retry time of their last failed attempt.
     *
     * @return list<OutboxMessage>
     */
    public function fetchUnpublished(int $limit): array;

    /**
     * Marks a message as published. Marking an already published message is a no-op.
     *
     * @throws \RuntimeException when the message does not exist
     */
    public function markPublished(string $id): void;

    /**
     * Records a failed attempt to publish a message and increments its attempt counter.
     *
     * A failed message must not be returned by {@see fetchUnpublished()} before $retryAt; with
     * $retryAt null the message is given up and never returned again. Recording a failure for an
     * already published message is a no-op.
     *
     * @throws \RuntimeException when the message does not exist
     */
    public function markFailed(string $id, string $error, ?DateTimeImmutable $retryAt): void;

    /**
     * Deletes messages published before the given date and returns how many were deleted.
     */
    public function purgePublished(DateTimeImmutable $publishedBefore): int;
}
