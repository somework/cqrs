<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Command;

use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use SomeWork\CqrsBundle\Contract\Command as CommandMessage;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Contract\OutboxStorage;
use SomeWork\CqrsBundle\Contract\Query;
use SomeWork\CqrsBundle\Outbox\OutboxMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\SignalRegistry\SignalRegistry;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\Exception\ExceptionInterface as LockException;
use Symfony\Component\Lock\Exception\LockReleasingException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\DelayedMessageHandlingException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\MessageDecodingFailedStamp;
use Symfony\Component\Messenger\Stamp\SentStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

use function class_exists;
use function count;
use function defined;
use function filter_var;
use function in_array;
use function json_decode;
use function max;
use function mb_scrub;
use function mb_substr;
use function microtime;
use function min;
use function register_shutdown_function;
use function sprintf;
use function str_starts_with;
use function strlen;
use function strpos;
use function substr;

use const DATE_ATOM;
use const FILTER_VALIDATE_INT;
use const JSON_THROW_ON_ERROR;
use const SIGINT;
use const SIGTERM;

/**
 * Relays unpublished outbox messages to their transports (at-least-once delivery).
 *
 * @internal
 */
#[AsCommand(
    name: 'somework:cqrs:outbox:relay',
    description: 'Relay unpublished outbox messages to their transports.',
)]
final class OutboxRelayCommand extends Command implements SignalableCommandInterface
{
    use LockableTrait;

    /** Consecutive send failures after which the other messages of that transport wait for the next run. */
    private const MAX_CONSECUTIVE_TRANSPORT_FAILURES = 3;

    /**
     * The same for a transport that accepted a message in this run: it is up, and failures are
     * more likely rejections of single messages (e.g. too large) than an outage.
     */
    private const MAX_CONSECUTIVE_FAILURES_OF_A_WORKING_TRANSPORT = 10;

    /** Seconds of consecutive failures after which even a transport that worked earlier in the run is paused (e.g. it went down and every send waits for a timeout). */
    private const MAX_FAILING_SECONDS = 10;

    /** A message whose transport fails (unreachable, or rejecting it) gets this many times max_attempts. */
    private const TRANSPORT_ATTEMPTS_FACTOR = 3;

    /** Delay before the second attempt; it doubles with every failure up to MAX_RETRY_DELAY. */
    private const RETRY_DELAY = 60;

    private const MAX_RETRY_DELAY = 3600;

    private const MAX_ERROR_LENGTH = 2000;

    /** Rows fetched at once: bodies can be large. */
    private const BATCH_SIZE = 50;

    /**
     * Stored before each attempt (followed by the previous error, if any), so an attempt the
     * process does not survive still counts.
     */
    public const INTERRUPTED = 'The relay did not finish this attempt (e.g. a PHP fatal error, running out of memory, a killed process or a lost database connection); the message may have been sent.';

    private const PREVIOUS_ERROR = ' Previous error: ';

    private const RELAYED = 'relayed';

    private const FAILED = 'failed';

    private const TRANSPORT_FAILED = 'transport_failed';

    private const CLAIMED_ELSEWHERE = 'claimed_elsewhere';

    private const STOPPED = 'stopped';

    /** The signal that asked the run to stop after the current message. */
    private ?int $stopSignal = null;

    private bool $releasesLockOnShutdown = false;

    /**
     * @param ContainerInterface|null $buses       Buses keyed by message type ("command", "query", "event"); other messages use $messageBus
     * @param int                     $maxAttempts Attempts after which a failing message is given up (three times as many when its transport fails)
     */
    public function __construct(
        private readonly OutboxStorage $outboxStorage,
        private readonly SerializerInterface $serializer,
        private readonly MessageBusInterface $messageBus,
        ?LockFactory $lockFactory = null,
        private readonly ?ContainerInterface $buses = null,
        private readonly string $lockName = 'somework:cqrs:outbox:relay',
        private readonly int $maxAttempts = 10,
        private readonly ?LoggerInterface $logger = null,
    ) {
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException(sprintf('The maximum number of attempts must be at least 1, %d given.', $maxAttempts));
        }

        parent::__construct();

        $this->lockFactory = $lockFactory;
    }

    /**
     * @return list<int>
     */
    public function getSubscribedSignals(): array
    {
        return defined('SIGTERM') && SignalRegistry::isSupported() ? [SIGTERM, SIGINT] : [];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        // A second signal stops right away, like a process without a handler.
        if (null !== $this->stopSignal) {
            return 128 + $signal;
        }

        // Finish the current message instead of leaving it half done, then stop (see relay()).
        $this->stopSignal = $signal;

        return false;
    }

    protected function configure(): void
    {
        $this->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum number of messages to process in this run', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT);

        if (false === $limit || $limit < 1) {
            $io->error('Limit must be a positive integer.');

            return self::INVALID;
        }

        // Overlapping runs (cron) would publish the same rows twice.
        try {
            if (class_exists(LockFactory::class) && !$this->lock($this->lockName)) {
                $io->note('Another outbox relay is already running.');

                return self::SUCCESS;
            }
        } catch (LockException $exception) {
            return $this->stop($io, 'the relay lock could not be acquired', $exception);
        }

        $this->releaseLockOnShutdown();

        try {
            return $this->relay($io, $limit);
        } catch (\Throwable $exception) {
            // e.g. the database is down: exit with 1 and say why, instead of the driver's error code.
            return $this->stop($io, 'the outbox storage failed', $exception);
        } finally {
            $this->stopSignal = null;

            try {
                $this->release();
            } catch (LockReleasingException $exception) {
                // The lock expires on its own; the outcome of the run matters more.
                $io->warning(sprintf('Could not release the relay lock: %s', $exception->getMessage()));
            }
        }
    }

    private function relay(SymfonyStyle $io, int $limit): int
    {
        $processed = 0;
        $relayed = 0;
        $failed = 0;
        $claimedElsewhere = 0;
        /** @var array<string, true> $seen */
        $seen = [];
        /** @var array<string, int> $consecutiveTransportFailures Keyed by transport name, "" for messages without one */
        $consecutiveTransportFailures = [];
        /** @var array<string, true> $workingTransports Transports that accepted a message in this run */
        $workingTransports = [];
        /** @var array<string, float> $failingSince When the current series of failures of a transport began */
        $failingSince = [];
        /** @var list<string|null> $pausedTransports Transports that failed too often in a row: their messages wait for the next run */
        $pausedTransports = [];

        while ($processed < $limit && null === $this->stopSignal) {
            $requested = min($limit - $processed, self::BATCH_SIZE);
            // A message is postponed when its attempt is recorded, so the next batch starts after it.
            $batch = $this->outboxStorage->fetchUnpublished($requested, $pausedTransports);
            $fresh = 0;

            foreach ($batch as $message) {
                // A storage that does not postpone attempted messages returns them again: each is tried once per run.
                if (isset($seen[$message->id]) || in_array($message->transportName, $pausedTransports, true)) {
                    continue;
                }
                $seen[$message->id] = true;
                ++$fresh;

                $started = microtime(true);
                $outcome = $this->process($message, $io);
                if (self::STOPPED === $outcome) {
                    break 2;
                }
                $key = $message->transportName ?? '';

                if (self::CLAIMED_ELSEWHERE === $outcome) {
                    ++$claimedElsewhere;
                } else {
                    ++$processed;
                }

                if (self::RELAYED === $outcome) {
                    ++$relayed;
                    $consecutiveTransportFailures[$key] = 0;
                    $workingTransports[$key] = true;
                    unset($failingSince[$key]);
                } elseif (self::FAILED === $outcome) {
                    ++$failed;
                } elseif (self::TRANSPORT_FAILED === $outcome) {
                    ++$failed;
                    $consecutiveTransportFailures[$key] = ($consecutiveTransportFailures[$key] ?? 0) + 1;

                    // The transport is probably down: do not walk its whole backlog, but keep relaying the other transports.
                    $failingSince[$key] ??= $started;
                    $failures = $consecutiveTransportFailures[$key];
                    $pause = isset($workingTransports[$key])
                        ? self::MAX_CONSECUTIVE_FAILURES_OF_A_WORKING_TRANSPORT <= $failures || (self::MAX_CONSECUTIVE_TRANSPORT_FAILURES <= $failures && microtime(true) - $failingSince[$key] >= self::MAX_FAILING_SECONDS)
                        : self::MAX_CONSECUTIVE_TRANSPORT_FAILURES <= $failures;
                    if ($pause) {
                        $pausedTransports[] = $message->transportName;
                        $this->reportPausedTransport($message->transportName, $failures, $io);
                    }
                }

                if (!$this->keepLock($io)) {
                    return self::FAILURE;
                }

                if ($processed >= $limit || null !== $this->stopSignal) {
                    break 2;
                }
            }

            // A short batch does not mean that nothing else is due: the storage leaves out the rows an
            // overlapping relay claimed after they were chosen. Only a batch without new rows ends the run.
            if (0 === $fresh) {
                break;
            }
        }

        if ($claimedElsewhere > 0) {
            $io->note(sprintf('Skipped %d message(s) that another relay claimed first.', $claimedElsewhere));
        }

        if ($relayed > 0) {
            $io->success(sprintf('Relayed %d message(s).', $relayed));
        }

        if (null !== $this->stopSignal) {
            $io->warning(sprintf('Stopped by signal %d after %d message(s); the remaining messages wait for the next run.', $this->stopSignal, $processed));
            $this->logger?->warning('The outbox relay stopped on signal {signal} after {count} message(s).', ['signal' => $this->stopSignal, 'count' => $processed]);

            return self::FAILURE;
        }

        if (0 === $processed) {
            if (0 === $claimedElsewhere) {
                $io->info('No outbox messages are due.');
            }

            return self::SUCCESS;
        }

        return 0 === $failed ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return self::RELAYED|self::FAILED|self::TRANSPORT_FAILED|self::CLAIMED_ELSEWHERE|self::STOPPED
     */
    private function process(OutboxMessage $message, SymfonyStyle $io): string
    {
        // A signal arrived after the previous message (e.g. while the batch was fetched): do not start another one.
        if (null !== $this->stopSignal) {
            return self::STOPPED;
        }

        $interrupted = null !== $message->lastError && str_starts_with($message->lastError, self::INTERRUPTED);

        // The process died during the last attempt (fatal error, out of memory, killed) and the
        // largest budget is used up: do not try again, the message may be what kills it. (Whether
        // the earlier attempts failed on the transport is unknown, so the transport budget applies.)
        if ($interrupted && $message->attempts >= self::TRANSPORT_ATTEMPTS_FACTOR * $this->maxAttempts) {
            if (!$this->recordAttempt($message, $message->attempts, $message->lastError, null, $message->attempts)) {
                return self::CLAIMED_ELSEWHERE;
            }
            $this->reportGivenUp($message, $message->attempts, $message->lastError, $io);

            return self::FAILED;
        }

        $attempt = $message->attempts + 1;

        // Claim the message and count the attempt before sending it: if the process dies during the
        // attempt, the next run waits for the retry delay instead of starting with the same message.
        if (!$this->recordAttempt($message, $attempt, $this->interruptedError($message, $interrupted), self::inSeconds($this->delayFor($attempt)), $message->attempts)) {
            // Published, or claimed by a relay that overlaps this one (e.g. a lock store that is local to one host).
            return self::CLAIMED_ELSEWHERE;
        }

        try {
            $this->send($message, $this->decode($message), $io);
        } catch (\Throwable $exception) {
            return $this->fail($message, $attempt, $exception, $io);
        }

        try {
            $this->outboxStorage->markPublished($message->id);
        } catch (\Throwable $exception) {
            // The storage fails: stop the run. The claim stays, so the message is sent again later (at least once).
            throw new \RuntimeException(sprintf('Message "%s" was sent but could not be marked as published, so it will be sent again: %s', $message->id, self::describe($exception)), 0, $exception);
        }

        return self::RELAYED;
    }

    /**
     * Records a failed attempt: the message is retried after a delay, or given up after its last attempt.
     *
     * @return self::FAILED|self::TRANSPORT_FAILED
     */
    private function fail(OutboxMessage $message, int $attempt, \Throwable $exception, SymfonyStyle $io): string
    {
        $transportFailure = self::isTransportFailure($exception);
        // A transport outage fails every message: give it about a day before giving up, instead of hours.
        $maxAttempts = $transportFailure ? self::TRANSPORT_ATTEMPTS_FACTOR * $this->maxAttempts : $this->maxAttempts;
        $retryAt = $attempt >= $maxAttempts ? null : self::inSeconds($this->delayFor($attempt));
        $error = self::describe($exception);
        $outcome = $transportFailure ? self::TRANSPORT_FAILED : self::FAILED;

        if (!$this->recordAttempt($message, $attempt, $error, $retryAt, $attempt)) {
            // Published or claimed by an overlapping relay in the meantime: its outcome counts.
            $io->warning(sprintf('Failed to relay message "%s", but another relay claimed it in the meantime: %s', $message->id, $error));
            $this->logger?->warning('Could not relay outbox message {id}, which another relay claimed in the meantime: {error}', ['id' => $message->id, 'error' => $error, 'exception' => $exception]);

            return $outcome;
        }

        if (null === $retryAt) {
            $this->reportGivenUp($message, $attempt, $error, $io, $exception);
        } else {
            $io->error(sprintf('Failed to relay message "%s" (attempt %d of %d, next attempt after %s): %s', $message->id, $attempt, $maxAttempts, $retryAt->format(DATE_ATOM), $error));
            $this->logger?->warning('Could not relay outbox message {id} (attempt {attempt} of {max_attempts}): {error}', ['id' => $message->id, 'attempt' => $attempt, 'max_attempts' => $maxAttempts, 'error' => $error, 'exception' => $exception]);
        }

        return $outcome;
    }

    /**
     * @throws \RuntimeException when the storage fails, which stops the run
     *
     * @return bool false when the message is published or was claimed by another relay
     */
    private function recordAttempt(OutboxMessage $message, int $attempts, string $error, ?DateTimeImmutable $retryAt, int $previousAttempts): bool
    {
        try {
            return $this->outboxStorage->recordAttempt($message->id, $attempts, $error, $retryAt, $previousAttempts);
        } catch (\Throwable $exception) {
            throw new \RuntimeException(sprintf('Could not record an attempt to relay message "%s": %s', $message->id, self::describe($exception)), 0, $exception);
        }
    }

    /**
     * Whether sending failed because of the transport (it cannot be reached, or it rejects the
     * message). A handler that ran inline and failed is not a transport failure, even when it
     * failed to send another message: its side effects happened.
     */
    private static function isTransportFailure(\Throwable $exception): bool
    {
        for ($current = $exception; null !== $current; $current = $current->getPrevious()) {
            if ($current instanceof HandlerFailedException || $current instanceof DelayedMessageHandlingException) {
                return false;
            }

            if ($current instanceof TransportException) {
                return true;
            }
        }

        return false;
    }

    private function reportPausedTransport(?string $transportName, int $failures, SymfonyStyle $io): void
    {
        if (null === $transportName) {
            $io->warning(sprintf('Messages without a transport name failed to be sent %d times in a row; the other ones wait for the next run.', $failures));
        } else {
            $io->warning(sprintf('Transport "%s" failed %d times in a row; its other messages wait for the next run.', $transportName, $failures));
        }

        $this->logger?->warning('The outbox relay paused transport {transport} for this run after {count} consecutive failures.', ['transport' => $transportName ?? '(routing)', 'count' => $failures]);
    }

    /**
     * Seconds to wait after attempt number $attempt failed: 1 minute, doubling up to 1 hour.
     */
    private function delayFor(int $attempt): int
    {
        return min(self::RETRY_DELAY * 2 ** min(max($attempt, 1) - 1, 30), self::MAX_RETRY_DELAY);
    }

    private static function inSeconds(int $seconds): DateTimeImmutable
    {
        return new DateTimeImmutable(sprintf('+%d seconds', $seconds));
    }

    private function reportGivenUp(OutboxMessage $message, int $attempts, string $error, SymfonyStyle $io, ?\Throwable $exception = null): void
    {
        $io->error(sprintf('Gave up on message "%s" after %d attempt(s): %s', $message->id, $attempts, $error));
        $this->logger?->error('Gave up on outbox message {id} after {attempts} attempt(s): {error}', ['id' => $message->id, 'attempts' => $attempts, 'error' => $error] + (null === $exception ? [] : ['exception' => $exception]));
    }

    /**
     * The error stored while an attempt runs. It keeps the error of the previous attempt, so the
     * cause of an outage stays visible when the process dies during the next attempt.
     */
    private function interruptedError(OutboxMessage $message, bool $interrupted): string
    {
        $previous = $interrupted ? self::previousError((string) $message->lastError) : $message->lastError;

        if (null === $previous || '' === $previous) {
            return self::INTERRUPTED;
        }

        return mb_substr(self::INTERRUPTED.self::PREVIOUS_ERROR.$previous, 0, self::MAX_ERROR_LENGTH, 'UTF-8');
    }

    /**
     * The error an interrupted attempt recorded before it, if any.
     */
    private static function previousError(string $interruptedError): ?string
    {
        $position = strpos($interruptedError, self::PREVIOUS_ERROR);

        return false === $position ? null : substr($interruptedError, $position + strlen(self::PREVIOUS_ERROR));
    }

    private function stop(SymfonyStyle $io, string $reason, \Throwable $exception): int
    {
        $io->error(sprintf('Stopping: %s (%s).', $reason, self::describe($exception)));
        $this->logger?->error('The outbox relay stopped: {reason}.', ['reason' => $reason, 'exception' => $exception]);

        return self::FAILURE;
    }

    private static function describe(\Throwable $exception): string
    {
        $description = '' === $exception->getMessage() ? $exception::class : sprintf('%s: %s', $exception::class, $exception->getMessage());

        // Stored in a text column: keep it valid UTF-8 and bounded.
        return mb_substr(mb_scrub($description, 'UTF-8'), 0, self::MAX_ERROR_LENGTH, 'UTF-8');
    }

    private function decode(OutboxMessage $message): Envelope
    {
        $envelope = $this->serializer->decode([
            'body' => $message->body,
            'headers' => json_decode($message->headers, true, 512, JSON_THROW_ON_ERROR),
        ]);

        // Since Symfony 8, serializers report decoding failures inside the envelope instead of throwing.
        $decoded = $envelope->getMessage();
        if ($decoded instanceof MessageDecodingFailedException) {
            throw $decoded;
        }
        if (null !== $envelope->last(MessageDecodingFailedStamp::class)) {
            throw new MessageDecodingFailedException(sprintf('The class of the message (%s) cannot be loaded.', $decoded::class));
        }

        if (null !== $message->transportName) {
            $envelope = $envelope->with(new TransportNamesStamp([$message->transportName]));
        }

        return $envelope;
    }

    private function send(OutboxMessage $message, Envelope $envelope, SymfonyStyle $io): void
    {
        // The bus of the message type adds the BusNameStamp workers use to pick the bus (a stored one is kept).
        $envelope = $this->busFor($envelope->getMessage())->dispatch($envelope);

        if (null !== $envelope->last(SentStamp::class)) {
            return;
        }

        $warning = null !== $envelope->last(HandledStamp::class)
            ? 'Message "%s" (%s) was not sent to any transport and was handled synchronously. Set a transport name or route the message to a transport.'
            : 'Message "%s" (%s) was neither sent to a transport nor handled (e.g. Messenger\'s deduplication dropped it as a duplicate, or it is an event without handlers); it is marked as published.';

        $io->warning(sprintf($warning, $message->id, $envelope->getMessage()::class));
        $this->logger?->warning(sprintf($warning, '{id}', '{class}'), ['id' => $message->id, 'class' => $envelope->getMessage()::class]);
    }

    private function busFor(object $message): MessageBusInterface
    {
        $type = match (true) {
            $message instanceof CommandMessage => 'command',
            $message instanceof Query => 'query',
            $message instanceof Event => 'event',
            default => null,
        };

        if (null === $type || null === $this->buses || !$this->buses->has($type)) {
            return $this->messageBus;
        }

        $bus = $this->buses->get($type);

        return $bus instanceof MessageBusInterface ? $bus : $this->messageBus;
    }

    /**
     * Extends the relay lock so it cannot expire during a long run and let a second relay in.
     */
    private function keepLock(SymfonyStyle $io): bool
    {
        if (null === $this->lock) {
            return true;
        }

        try {
            $this->lock->refresh();
        } catch (LockException $exception) {
            $this->stop($io, 'the relay lock was lost, another relay may be running', $exception);

            return false;
        }

        return true;
    }

    /**
     * A PHP fatal error (e.g. running out of memory) skips "finally" blocks and destructors, so a
     * lock store with a TTL would keep the next runs out until the lock expires.
     */
    private function releaseLockOnShutdown(): void
    {
        if ($this->releasesLockOnShutdown) {
            return;
        }

        $this->releasesLockOnShutdown = true;

        register_shutdown_function(function (): void {
            try {
                $this->release();
            } catch (\Throwable) {
                // The lock expires on its own.
            }
        });
    }
}
