<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Contract;

use DateTimeImmutable;
use SomeWork\CqrsBundle\Outbox\OutboxMessage;

/**
 * Persists messages in a transactional outbox for reliable async dispatch.
 *
 * The relay works in claims: it fetches due messages, claims them with a token of its run
 * (counting the attempt before anything is sent, so an attempt that kills the process still
 * counts), sends them, and then marks them published, records their failure, or releases the
 * ones it did not get to. A claim that is never finished leaves claimedAt set: when the message
 * is due again, the next relay knows that the attempt was interrupted.
 *
 * Every message a storage returns must carry the id, body, headers and signature exactly as
 * they were stored.
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
     * attempted (or requeued) or past their retry time. The transports take turns, the one whose
     * next message has waited longest first (the messages without a transport name count as one
     * transport); within a transport, the messages never attempted come first, in the order they
     * were stored, then the others, in the order of their retry time.
     *
     * @param list<string|null> $excludedTransports Transports whose messages are skipped; null
     *                                              stands for messages stored without a transport name
     *
     * @return list<OutboxMessage>
     */
    public function fetchUnpublished(int $limit, array $excludedTransports = []): array;

    /**
     * Claims fetched messages for an attempt, atomically per message: a message is claimed only
     * while it is neither published nor given up, and its attempts and transport name are still
     * the fetched ones (so two relays cannot claim the same attempt). A claimed message gets the
     * token, claimedAt (now), one more attempt, and its retry time: $retryAt[<fetched attempts>].
     * It is not due before that time, so an attempt that never finishes is retried after it. A
     * run claims each message at most once (a new run uses a new token).
     *
     * @param list<OutboxMessage>           $messages As fetchUnpublished() returned them
     * @param array<int, DateTimeImmutable> $retryAt  Retry times keyed by the fetched number of attempts
     * @param string                        $token    Identifies the claims of one relay run (not empty)
     *
     * @return list<string> The ids of the claimed messages, in the order of $messages
     */
    public function claim(array $messages, array $retryAt, string $token): array;

    /**
     * Renews the claims of fetched messages that are still claimed with $token: claimedAt becomes
     * now and the retry time $retryAt[<fetched attempts>], so the claims of a long batch do not run
     * out before the relay gets to them.
     *
     * @param list<OutboxMessage>           $messages As fetchUnpublished() returned them
     * @param array<int, DateTimeImmutable> $retryAt  Retry times keyed by the fetched number of attempts
     *
     * @return list<string> The ids still claimed with $token, in the order of $messages
     */
    public function renew(array $messages, array $retryAt, string $token): array;

    /**
     * Undoes the claims of messages the relay did not attempt (it stopped, or paused their
     * transport): their attempts, retry time and claimedAt go back to the fetched values. Messages
     * no longer claimed with $token are left alone.
     *
     * @param list<OutboxMessage> $messages As fetchUnpublished() returned them
     */
    public function release(array $messages, string $token): void;

    /**
     * Marks sent messages as published and clears their claim and failure. Ids that do not exist
     * or are already published are ignored.
     *
     * @param list<string> $ids
     */
    public function markPublished(array $ids): void;

    /**
     * Records the failure of a claimed message and ends its claim: the number of attempts, the
     * error, and the retry time; with $retryAt null the message is given up and never returned by
     * fetchUnpublished() again.
     *
     * @return bool false when the message is no longer claimed with $token (e.g. published, or
     *              claimed by another relay); nothing is recorded then
     */
    public function recordFailure(string $id, string $token, int $attempts, string $error, ?DateTimeImmutable $retryAt): bool;

    /**
     * Deletes messages published before the given date and returns how many were deleted.
     */
    public function purgePublished(DateTimeImmutable $publishedBefore): int;
}
