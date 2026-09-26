<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Outbox\Relay;

use DateTimeImmutable;
use SomeWork\CqrsBundle\Outbox\OutboxMessage;

/**
 * What OutboxRelay tells the process that runs it (e.g. the console command), and asks it.
 *
 * The relay logs on its own; a reporter only shows the outcome of each message to an operator.
 *
 * @internal
 */
interface RelayReporter
{
    /**
     * An attempt failed; the message is retried after $retryAt.
     */
    public function attemptFailed(OutboxMessage $message, int $attempt, int $maxAttempts, DateTimeImmutable $retryAt, string $error): void;

    /**
     * An attempt failed, but another relay claimed the message in the meantime: its outcome counts.
     */
    public function claimedElsewhereAfterFailure(OutboxMessage $message, string $error): void;

    public function gaveUp(OutboxMessage $message, int $attempts, string $error): void;

    /**
     * The message was sent, but no transport took it (it was handled synchronously, or dropped).
     */
    public function notSent(string $warning): void;

    /**
     * The messages of $transportName wait for the next run after $failures consecutive failures.
     */
    public function transportPaused(?string $transportName, int $failures): void;

    /**
     * Called after every message; false aborts the run at once (e.g. the relay lock was lost).
     */
    public function continueAfterMessage(): bool;

    /**
     * Whether the run should stop before the next message (e.g. on SIGTERM).
     */
    public function stopRequested(): bool;
}
