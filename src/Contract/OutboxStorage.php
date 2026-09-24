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
     * Records an attempt to publish a message: the number of attempts so far, its error, and when
     * to try again.
     *
     * The relay records each attempt before it sends the message, with an error saying that the
     * attempt did not finish, so an attempt that kills the process still counts; it records the
     * actual error when the attempt fails. A message must not be returned by {@see fetchUnpublished()}
     * before $retryAt; with $retryAt null it is given up and never returned again. Recording an
     * attempt of an already published message is a no-op.
     *
     * @param int $attempts The number of attempts, including this one
     *
     * @throws \RuntimeException when the message does not exist
     */
    public function recordAttempt(string $id, int $attempts, string $error, ?DateTimeImmutable $retryAt): void;

    /**
     * Deletes messages published before the given date and returns how many were deleted.
     */
    public function purgePublished(DateTimeImmutable $publishedBefore): int;
}
