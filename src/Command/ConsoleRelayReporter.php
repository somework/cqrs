<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Command;

use DateTimeImmutable;
use SomeWork\CqrsBundle\Outbox\OutboxMessage;
use SomeWork\CqrsBundle\Outbox\Relay\RelayReporter;
use Symfony\Component\Console\Style\SymfonyStyle;

use function mb_scrub;
use function preg_replace;
use function sprintf;

use const DATE_ATOM;

/**
 * Shows the outcome of each relayed message in the console.
 *
 * @internal
 */
final class ConsoleRelayReporter implements RelayReporter
{
    /**
     * @param \Closure(): bool $continueAfterMessage
     * @param \Closure(): bool $stopRequested
     */
    public function __construct(
        private readonly SymfonyStyle $io,
        private readonly \Closure $continueAfterMessage,
        private readonly \Closure $stopRequested,
    ) {
    }

    public function attemptFailed(OutboxMessage $message, int $attempt, int $maxAttempts, DateTimeImmutable $retryAt, string $error): void
    {
        $this->io->error(sprintf('Failed to relay message "%s" (attempt %d of %d, next attempt after %s): %s', self::printable($message->id), $attempt, $maxAttempts, $retryAt->format(DATE_ATOM), $error));
    }

    public function claimedElsewhereAfterFailure(OutboxMessage $message, string $error): void
    {
        $this->io->warning(sprintf('Failed to relay message "%s", but another relay claimed it in the meantime: %s', self::printable($message->id), $error));
    }

    public function gaveUp(OutboxMessage $message, int $attempts, string $error): void
    {
        $this->io->error(sprintf('Gave up on message "%s" after %d attempt(s): %s', self::printable($message->id), $attempts, $error));
    }

    public function notSent(string $warning): void
    {
        $this->io->warning($warning);
    }

    public function transportPaused(?string $transportName, int $failures, int $seconds): void
    {
        if (null === $transportName) {
            $this->io->warning(sprintf('Messages without a transport name failed to be sent %d times in a row; the other ones wait for the next run (%d seconds with --watch).', $failures, $seconds));
        } else {
            $this->io->warning(sprintf('Transport "%s" failed %d times in a row; its other messages wait for the next run (%d seconds with --watch).', $transportName, $failures, $seconds));
        }
    }

    public function continueAfterMessage(): bool
    {
        return ($this->continueAfterMessage)();
    }

    public function stopRequested(): bool
    {
        return ($this->stopRequested)();
    }

    /**
     * The id comes from the storage: a custom storage (or a row written by hand) may hold control
     * characters, which would reach the operator's terminal as escape sequences. SymfonyStyle's
     * blocks escape formatting tags themselves.
     */
    private static function printable(string $text): string
    {
        return (string) preg_replace('/[\x00-\x1F\x7F\x{80}-\x{9F}]+/u', ' ', mb_scrub($text, 'UTF-8'));
    }
}
