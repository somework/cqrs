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
     * Returns the messages that are due: neither published nor given up, and either never
     * attempted (or requeued) or past the retry time of their last attempt. The messages never
     * attempted come first, in the order they were stored; then the others, in the order of their
     * retry time.
     *
     * @param list<string|null> $excludedTransports Transports whose messages are skipped; null
     *                                              stands for messages stored without a transport name
     *
     * @return list<OutboxMessage>
     */
    public function fetchUnpublished(int $limit, array $excludedTransports = []): array;

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
     * before $retryAt; with $retryAt null it is given up and never returned again.
     *
     * With $previousAttempts the attempt is only recorded while the message is not given up and
     * its stored number of attempts still equals it, in one atomic step: two relays that fetched
     * the same message cannot both claim it, nor both give it up.
     *
     * @param int      $attempts         The number of attempts, including this one
     * @param int|null $previousAttempts The number of attempts the caller read, or null to record unconditionally
     *
     * @throws \RuntimeException when the message does not exist
     *
     * @return bool false when nothing was recorded: the message is already published, or (with
     *              $previousAttempts) another relay recorded an attempt or gave it up since the caller read it
     */
    public function recordAttempt(string $id, int $attempts, string $error, ?DateTimeImmutable $retryAt, ?int $previousAttempts = null): bool;

    /**
     * Deletes messages published before the given date and returns how many were deleted.
     */
    public function purgePublished(DateTimeImmutable $publishedBefore): int;
}
