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
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\Exception\ExceptionInterface as LockException;
use Symfony\Component\Lock\Exception\LockReleasingException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\MessageDecodingFailedStamp;
use Symfony\Component\Messenger\Stamp\SentStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

use function class_exists;
use function count;
use function filter_var;
use function json_decode;
use function max;
use function mb_scrub;
use function mb_substr;
use function min;
use function sprintf;

use const DATE_ATOM;
use const FILTER_VALIDATE_INT;
use const JSON_THROW_ON_ERROR;

/**
 * Relays unpublished outbox messages to their transports (at-least-once delivery).
 *
 * @internal
 */
#[AsCommand(
    name: 'somework:cqrs:outbox:relay',
    description: 'Relay unpublished outbox messages to their transports.',
)]
final class OutboxRelayCommand extends Command
{
    use LockableTrait;

    private const MAX_CONSECUTIVE_SEND_FAILURES = 5;

    /** Delay before the second attempt; it doubles with every failure up to MAX_RETRY_DELAY. */
    private const RETRY_DELAY = 60;

    private const MAX_RETRY_DELAY = 3600;

    private const MAX_ERROR_LENGTH = 2000;

    /** Rows fetched at once: bodies can be large. */
    private const BATCH_SIZE = 50;

    /** Stored before each attempt, so an attempt the process does not survive still counts. */
    private const INTERRUPTED = 'The relay stopped during this attempt (e.g. a PHP fatal error, running out of memory or a killed process).';

    /**
     * @param ContainerInterface|null $buses       Buses keyed by message type ("command", "query", "event"); other messages use $messageBus
     * @param int                     $maxAttempts Attempts after which a failing message is given up
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

        try {
            return $this->relay($io, $limit);
        } catch (\Throwable $exception) {
            // e.g. the database is down: exit with 1 and say why, instead of the driver's error code.
            return $this->stop($io, 'the outbox storage failed', $exception);
        } finally {
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
        $consecutiveTransportFailures = 0;
        /** @var array<string, true> $seen */
        $seen = [];
        // Whether a message reached a transport in this run: then the transport works.
        $transportWorked = false;
        /** @var list<array{OutboxMessage, int, string}> $transportFailures Decided at the end of the run */
        $transportFailures = [];
        $stoppedByOutage = false;

        while ($processed < $limit) {
            $requested = min($limit - $processed, self::BATCH_SIZE);
            // A failed message is postponed by recordAttempt(), so the next batch starts after it.
            $batch = $this->outboxStorage->fetchUnpublished($requested);
            $fresh = 0;

            foreach ($batch as $message) {
                // A storage that does not postpone failed messages returns them again: each is tried once per run.
                if (isset($seen[$message->id])) {
                    continue;
                }
                $seen[$message->id] = true;
                ++$fresh;
                ++$processed;

                if ($message->attempts >= $this->maxAttempts) {
                    // The process died during the last attempt (fatal error, out of memory): do not try again.
                    ++$failed;
                    if (!$this->record($message, $message->attempts, self::INTERRUPTED, null, $io)) {
                        return self::FAILURE;
                    }
                    $this->reportGivenUp($message, $message->attempts, self::INTERRUPTED, $io);
                } else {
                    $attempt = $message->attempts + 1;

                    // Count the attempt before sending it: if the process dies during the attempt, the
                    // next run must not start with the same message again.
                    if (!$this->record($message, $attempt, self::INTERRUPTED, self::inSeconds($this->delayFor($attempt)), $io)) {
                        return self::FAILURE;
                    }

                    [$sent, $failure] = $this->attempt($message, $io);
                    $transportWorked = $transportWorked || $sent;

                    if (null === $failure) {
                        ++$relayed;
                        $consecutiveTransportFailures = 0;
                    } elseif (!$sent && self::isTransportFailure($failure)) {
                        // The transport may be down: whether the attempt counts is decided at the end of the run.
                        ++$failed;
                        $error = self::describe($failure);
                        $transportFailures[] = [$message, $attempt, $error];
                        if (!$this->recordFailure($message, $attempt, $failure, self::inSeconds($this->delayFor($attempt)), $io)) {
                            return self::FAILURE;
                        }

                        // An outage fails every message: stop instead of walking the backlog.
                        if (++$consecutiveTransportFailures >= self::MAX_CONSECUTIVE_SEND_FAILURES) {
                            $io->error(sprintf('Stopping after %d consecutive failures to send messages.', $consecutiveTransportFailures));
                            $this->logger?->error('The outbox relay stopped after {count} consecutive failures to send messages.', ['count' => $consecutiveTransportFailures]);
                            $stoppedByOutage = true;
                        }
                    } else {
                        // The message itself is the problem (decoding, handler, serialization, routing).
                        ++$failed;
                        if (!$this->recordFailure($message, $attempt, $failure, $this->retryAt($attempt), $io)) {
                            return self::FAILURE;
                        }
                    }
                }

                if (!$this->keepLock($io)) {
                    return self::FAILURE;
                }

                if ($processed >= $limit || $stoppedByOutage) {
                    break 2;
                }
            }

            if (0 === $fresh || count($batch) < $requested) {
                break;
            }
        }

        if (!$this->settleTransportFailures($transportFailures, $transportWorked, $io)) {
            return self::FAILURE;
        }

        if (0 === $processed) {
            $io->info('No outbox messages are due.');

            return self::SUCCESS;
        }

        if ($relayed > 0) {
            $io->success(sprintf('Relayed %d message(s).', $relayed));
        }

        return 0 === $failed ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{bool, \Throwable|null} whether the message reached a transport or a handler, and the failure
     */
    private function attempt(OutboxMessage $message, SymfonyStyle $io): array
    {
        try {
            $envelope = $this->decode($message);
        } catch (\Throwable $exception) {
            return [false, $exception];
        }

        try {
            $this->send($message, $envelope, $io);
        } catch (\Throwable $exception) {
            return [false, $exception];
        }

        try {
            $this->outboxStorage->markPublished($message->id);
        } catch (\Throwable $exception) {
            // Sent, but it will be sent again (at-least-once).
            return [true, $exception];
        }

        return [true, null];
    }

    /**
     * Failures caused by an unreachable transport only count as attempts when the transport
     * accepted another message in the same run; otherwise the attempts are given back, so an
     * outage never uses up the attempts of the backlog.
     *
     * @param list<array{OutboxMessage, int, string}> $transportFailures
     *
     * @return bool false when the storage failed
     */
    private function settleTransportFailures(array $transportFailures, bool $transportWorked, SymfonyStyle $io): bool
    {
        if ([] === $transportFailures) {
            return true;
        }

        if (!$transportWorked) {
            foreach ($transportFailures as [$message, $attempt, $error]) {
                if (!$this->record($message, $attempt - 1, $error, self::inSeconds($this->delayFor($attempt)), $io)) {
                    return false;
                }
            }

            $io->warning(sprintf('No message could be sent in this run: the transport seems unavailable, so the failed attempts of %d message(s) do not count.', count($transportFailures)));
            $this->logger?->warning('No outbox message could be sent in this run: the failed attempts of {count} message(s) do not count.', ['count' => count($transportFailures)]);

            return true;
        }

        foreach ($transportFailures as [$message, $attempt, $error]) {
            if ($attempt >= $this->maxAttempts) {
                if (!$this->record($message, $attempt, $error, null, $io)) {
                    return false;
                }
                $this->reportGivenUp($message, $attempt, $error, $io);
            }
        }

        return true;
    }

    private static function isTransportFailure(\Throwable $exception): bool
    {
        for ($current = $exception; null !== $current; $current = $current->getPrevious()) {
            if ($current instanceof TransportException) {
                return true;
            }
        }

        return false;
    }

    /**
     * Seconds to wait after attempt number $attempt failed: 1 minute, doubling up to 1 hour.
     */
    private function delayFor(int $attempt): int
    {
        return min(self::RETRY_DELAY * 2 ** min(max($attempt, 1) - 1, 30), self::MAX_RETRY_DELAY);
    }

    /**
     * When to try again after attempt number $attempt failed, or null to give up.
     */
    private function retryAt(int $attempt): ?DateTimeImmutable
    {
        return $attempt >= $this->maxAttempts ? null : self::inSeconds($this->delayFor($attempt));
    }

    private static function inSeconds(int $seconds): DateTimeImmutable
    {
        return new DateTimeImmutable(sprintf('+%d seconds', $seconds));
    }

    /**
     * Stores the failure: postponed until $retryAt, or given up when it is null.
     *
     * @return bool false when the failure could not be stored, which stops the run
     */
    private function recordFailure(OutboxMessage $message, int $attempt, \Throwable $exception, ?DateTimeImmutable $retryAt, SymfonyStyle $io): bool
    {
        $error = self::describe($exception);

        if (!$this->record($message, $attempt, $error, $retryAt, $io)) {
            return false;
        }

        if (null === $retryAt) {
            $this->reportGivenUp($message, $attempt, $error, $io);
        } else {
            $io->error(sprintf('Failed to relay message "%s" (attempt %d of %d, next attempt after %s): %s', $message->id, $attempt, $this->maxAttempts, $retryAt->format(DATE_ATOM), $error));
            $this->logger?->warning('Could not relay outbox message {id} (attempt {attempt} of {max_attempts}): {error}', ['id' => $message->id, 'attempt' => $attempt, 'max_attempts' => $this->maxAttempts, 'error' => $error, 'exception' => $exception]);
        }

        return true;
    }

    private function reportGivenUp(OutboxMessage $message, int $attempts, string $error, SymfonyStyle $io): void
    {
        $io->error(sprintf('Gave up on message "%s" after %d attempt(s): %s', $message->id, $attempts, $error));
        $this->logger?->error('Gave up on outbox message {id} after {attempts} attempt(s): {error}', ['id' => $message->id, 'attempts' => $attempts, 'error' => $error]);
    }

    /**
     * @return bool false when the storage failed, which stops the run
     */
    private function record(OutboxMessage $message, int $attempt, string $error, ?DateTimeImmutable $retryAt, SymfonyStyle $io): bool
    {
        try {
            $this->outboxStorage->recordAttempt($message->id, $attempt, $error, $retryAt);
        } catch (\Throwable $storageException) {
            $this->stop($io, sprintf('the attempt to relay message "%s" could not be recorded', $message->id), $storageException);

            return false;
        }

        return true;
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

        if (null === $envelope->last(SentStamp::class)) {
            $io->warning(sprintf('Message "%s" (%s) was not sent to any transport and was handled synchronously. Set a transport name or route the message to a transport.', $message->id, $envelope->getMessage()::class));
        }
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
}
