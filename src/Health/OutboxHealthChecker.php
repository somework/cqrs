<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Health;

use DateTimeImmutable;
use DateTimeZone;
use SomeWork\CqrsBundle\Contract\OutboxStorage;
use SomeWork\CqrsBundle\Outbox\DbalOutboxStorage;

use function implode;
use function intdiv;
use function sprintf;

/**
 * Reports whether the outbox relay keeps up: messages the relay gave up on, messages that failed
 * and still wait for another attempt, and due messages that have waited longer than a relay run
 * should take; and whether the table needs the setup command (e.g. its index is missing).
 *
 * @internal
 */
final class OutboxHealthChecker implements HealthChecker
{
    /** A due message older than this means that the relay does not run, or does not keep up; a failing one, that it cannot deliver. */
    private const MAX_WAIT_SECONDS = 600;

    public function __construct(
        private readonly OutboxStorage $outboxStorage,
    ) {
    }

    /** @return list<CheckResult> */
    public function check(): array
    {
        if (!$this->outboxStorage instanceof DbalOutboxStorage) {
            return [new CheckResult(CheckSeverity::OK, 'outbox', sprintf('The outbox storage (%s) is not checked', $this->outboxStorage::class))];
        }

        try {
            $status = $this->outboxStorage->status();
        } catch (\Throwable $exception) {
            return [new CheckResult(CheckSeverity::CRITICAL, 'outbox', sprintf('The outbox storage cannot be read: %s', $exception->getMessage()))];
        }

        $results = [];

        try {
            $changes = $this->outboxStorage->pendingChanges();
        } catch (\Throwable $exception) {
            $changes = [sprintf('its structure cannot be read (%s)', $exception->getMessage())];
        }

        // e.g. the index of this version, which the automatic setup leaves to the setup command.
        if ([] !== $changes) {
            $results[] = new CheckResult(CheckSeverity::WARNING, 'outbox', sprintf('The outbox table needs "bin/console somework:cqrs:outbox:setup": %s', implode('; ', $changes)));
        }

        if ($status['failed'] > 0) {
            $results[] = new CheckResult(CheckSeverity::WARNING, 'outbox', sprintf('The relay gave up on %d outbox message(s); see "somework:cqrs:outbox:failed"', $status['failed']));
        }

        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->getTimestamp();
        $oldestRetrying = $status['oldest_retrying'];
        $failingFor = null === $oldestRetrying ? 0 : $now - $oldestRetrying->getTimestamp();

        // Postponed after failed attempts, so not due: an outage of their transport, or messages that cannot be sent.
        if ($failingFor > self::MAX_WAIT_SECONDS) {
            $results[] = new CheckResult(CheckSeverity::WARNING, 'outbox', sprintf('%d outbox message(s) failed and wait for another attempt, the oldest was stored %d minute(s) ago; see the relay output or the "last_error" column', $status['retrying'], intdiv($failingFor, 60)));
        }

        $oldestDue = $status['oldest_due'];
        $waited = null === $oldestDue ? 0 : $now - $oldestDue->getTimestamp();

        if ($waited > self::MAX_WAIT_SECONDS) {
            $results[] = new CheckResult(CheckSeverity::WARNING, 'outbox', sprintf('%d outbox message(s) are due, the oldest for %d minute(s): "somework:cqrs:outbox:relay" does not run, does not keep up, or pauses their failing transport', $status['due'], intdiv($waited, 60)));
        }

        if ([] === $results) {
            $results[] = new CheckResult(CheckSeverity::OK, 'outbox', sprintf('Outbox: %d message(s) due, none waiting for long', $status['due']));
        }

        return $results;
    }
}
