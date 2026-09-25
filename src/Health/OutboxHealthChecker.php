<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Health;

use DateTimeImmutable;
use DateTimeZone;
use SomeWork\CqrsBundle\Contract\Outbox\OutboxMonitoring;
use SomeWork\CqrsBundle\Contract\Outbox\OutboxSchema;
use SomeWork\CqrsBundle\Contract\Outbox\OutboxStorage;
use SomeWork\CqrsBundle\Outbox\DbalOutboxStorage;
use SomeWork\CqrsBundle\Outbox\OutboxStatus;

use function array_filter;
use function implode;
use function intdiv;
use function sprintf;
use function str_starts_with;

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

    /**
     * @param OutboxStorage $outboxStorage The storage behind any decorator (see OutboxStoragePass)
     */
    public function __construct(
        private readonly OutboxStorage $outboxStorage,
    ) {
    }

    /** @return list<CheckResult> */
    public function check(): array
    {
        $storage = $this->outboxStorage;
        if (!$storage instanceof OutboxMonitoring) {
            return [new CheckResult(CheckSeverity::OK, 'outbox', sprintf('The outbox storage (%s) is not checked: it does not implement %s', $storage::class, OutboxMonitoring::class))];
        }

        $structureRead = true;
        try {
            $changes = $storage instanceof OutboxSchema ? $storage->pendingChanges() : [];
        } catch (\Throwable $exception) {
            $changes = [sprintf('its structure cannot be read (%s)', $exception->getMessage())];
            $structureRead = false;
        }
        // e.g. the index of this version, which the automatic setup leaves to the setup command. Without
        // the columns of this version, storing a message inside a transaction fails (and rolls it back).
        $lacksColumns = [] !== array_filter($changes, static fn (string $change): bool => str_starts_with($change, 'the columns '));
        $needsSetup = [] === $changes ? null : new CheckResult(
            $lacksColumns ? CheckSeverity::CRITICAL : CheckSeverity::WARNING,
            'outbox',
            sprintf('The outbox table needs "bin/console somework:cqrs:outbox:setup": %s%s', implode('; ', $changes), $lacksColumns ? '; until then, storing a message inside a transaction fails' : ''),
        );

        try {
            $status = $storage->status();
        } catch (\Throwable $exception) {
            // A table of 0.4 (without the new columns) cannot be read: report the upgrade it needs.
            // When neither can be read (e.g. the database is down), report the error.
            if ($structureRead && null !== $needsSetup && $lacksColumns && $storage instanceof DbalOutboxStorage) {
                return [$this->withBacklog($storage, $needsSetup)];
            }

            return [new CheckResult(CheckSeverity::CRITICAL, 'outbox', sprintf('The outbox storage cannot be read: %s', $exception->getMessage()))];
        }

        $results = null === $needsSetup ? [] : [$needsSetup];

        if ($status->failed > 0) {
            $results[] = new CheckResult(CheckSeverity::WARNING, 'outbox', sprintf('The relay gave up on %s outbox message(s); see "somework:cqrs:outbox:failed"', self::count($status->failed, $status)));
        }

        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->getTimestamp();

        // Claims run out at the retry time of their attempt; the next relay run takes them over.
        $expiredFor = null === $status->claimExpiredSince ? 0 : $now - $status->claimExpiredSince->getTimestamp();
        if ($expiredFor > self::MAX_WAIT_SECONDS) {
            $results[] = new CheckResult(CheckSeverity::WARNING, 'outbox', sprintf('An outbox relay claimed messages and did not finish them (it died, or hangs on a send), and no relay has taken them over since their claim ran out %d minute(s) ago: check that "somework:cqrs:outbox:relay" runs', intdiv($expiredFor, 60)));
        }
        $oldestRetrying = $status->oldestRetrying;
        $failingFor = null === $oldestRetrying ? 0 : $now - $oldestRetrying->getTimestamp();

        // Postponed after failed attempts, so not due: an outage of their transport, or messages that cannot be sent.
        if ($failingFor > self::MAX_WAIT_SECONDS) {
            $results[] = new CheckResult(CheckSeverity::WARNING, 'outbox', sprintf('%s outbox message(s) failed and wait for another attempt, the oldest was stored %d minute(s) ago; see the relay output or the "last_error" column', self::count($status->retrying, $status), intdiv($failingFor, 60)));
        }

        $oldestDue = $status->oldestDue;
        $waited = null === $oldestDue ? 0 : $now - $oldestDue->getTimestamp();

        if ($waited > self::MAX_WAIT_SECONDS) {
            $results[] = new CheckResult(CheckSeverity::WARNING, 'outbox', sprintf('%s outbox message(s) are due, the oldest for %d minute(s): "somework:cqrs:outbox:relay" does not run, does not keep up, or pauses their failing transport', self::count($status->due, $status), intdiv($waited, 60)));
        }

        if ([] === $results) {
            $results[] = new CheckResult(CheckSeverity::OK, 'outbox', sprintf('Outbox: %s message(s) due, none waiting for long', self::count($status->due, $status)));
        }

        return $results;
    }

    private static function count(int $count, OutboxStatus $status): string
    {
        return $status->capped && $count >= OutboxStatus::COUNT_CAP ? sprintf('more than %d', OutboxStatus::COUNT_CAP) : (string) $count;
    }

    /**
     * Messages still wait on a table of 0.4 that the relay could not upgrade (it fails every run
     * until the setup command has run): critical once the oldest has waited too long.
     */
    private function withBacklog(DbalOutboxStorage $storage, CheckResult $needsSetup): CheckResult
    {
        try {
            $backlog = $storage->unpublishedBacklog();
        } catch (\Throwable) {
            return $needsSetup;
        }

        $waited = null === $backlog['oldest'] ? 0 : (new DateTimeImmutable('now', new DateTimeZone('UTC')))->getTimestamp() - $backlog['oldest']->getTimestamp();
        if ($waited <= self::MAX_WAIT_SECONDS) {
            return $needsSetup;
        }

        return new CheckResult(CheckSeverity::CRITICAL, 'outbox', sprintf('%s; %d outbox message(s) wait, the oldest for %d minute(s), and the relay cannot send them until then', $needsSetup->message, $backlog['count'], intdiv($waited, 60)));
    }
}
