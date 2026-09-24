<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Command;

use DateTimeImmutable;
use Psr\Container\ContainerInterface;
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
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\MessageDecodingFailedStamp;
use Symfony\Component\Messenger\Stamp\SentStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

use function class_exists;
use function count;
use function filter_var;
use function json_decode;
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
        if (class_exists(LockFactory::class) && !$this->lock($this->lockName)) {
            $io->note('Another outbox relay is already running.');

            return self::SUCCESS;
        }

        try {
            return $this->relay($io, $limit);
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
        $consecutiveSendFailures = 0;
        /** @var array<string, true> $seen */
        $seen = [];

        while ($processed < $limit) {
            $requested = $limit - $processed;
            // A failed message is postponed by markFailed(), so the next batch starts after it.
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

                $envelope = null;
                $failure = null;
                $sendFailed = false;

                try {
                    $envelope = $this->decode($message);
                } catch (\Throwable $exception) {
                    // Undecodable rows say nothing about the transport: they do not count as send failures.
                    $failure = $exception;
                }

                if (null !== $envelope) {
                    try {
                        $this->send($message, $envelope, $io);
                        ++$relayed;
                        $consecutiveSendFailures = 0;
                    } catch (\Throwable $exception) {
                        $failure = $exception;
                        $sendFailed = true;
                    }
                }

                if (null !== $failure) {
                    ++$failed;
                    if (!$this->recordFailure($message, $failure, $io)) {
                        return self::FAILURE;
                    }

                    // A transport or database outage fails every message: stop instead of walking the backlog.
                    if ($sendFailed && ++$consecutiveSendFailures >= self::MAX_CONSECUTIVE_SEND_FAILURES) {
                        $io->error(sprintf('Stopping after %d consecutive failures to send messages.', $consecutiveSendFailures));

                        return self::FAILURE;
                    }
                }

                if (!$this->keepLock($io)) {
                    return self::FAILURE;
                }

                if ($processed >= $limit) {
                    break 2;
                }
            }

            if (0 === $fresh || count($batch) < $requested) {
                break;
            }
        }

        if (0 === $processed) {
            $io->info('No unpublished messages found.');

            return self::SUCCESS;
        }

        if ($relayed > 0) {
            $io->success(sprintf('Relayed %d message(s).', $relayed));
        }

        return 0 === $failed ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Postpones the message with an exponential backoff, or gives up after the last attempt.
     *
     * @return bool false when the failure could not be stored, which stops the run
     */
    private function recordFailure(OutboxMessage $message, \Throwable $exception, SymfonyStyle $io): bool
    {
        $attempts = $message->attempts + 1;
        $error = self::describe($exception);
        $retryAt = null;

        if ($attempts < $this->maxAttempts) {
            $delay = min(self::RETRY_DELAY * 2 ** min($attempts - 1, 30), self::MAX_RETRY_DELAY);
            $retryAt = new DateTimeImmutable(sprintf('+%d seconds', $delay));
        }

        try {
            $this->outboxStorage->markFailed($message->id, $error, $retryAt);
        } catch (\Throwable $storageException) {
            $io->error(sprintf('Failed to relay message "%s": %s', $message->id, $error));
            $io->error(sprintf('Stopping: the failure could not be recorded (%s).', $storageException->getMessage()));

            return false;
        }

        $io->error(null === $retryAt
            ? sprintf('Gave up on message "%s" after %d attempt(s): %s', $message->id, $attempts, $error)
            : sprintf('Failed to relay message "%s" (attempt %d of %d, next attempt after %s): %s', $message->id, $attempts, $this->maxAttempts, $retryAt->format(DATE_ATOM), $error));

        return true;
    }

    private static function describe(\Throwable $exception): string
    {
        // Stored in a text column: keep it valid UTF-8 and bounded.
        return mb_substr(mb_scrub(sprintf('%s: %s', $exception::class, $exception->getMessage()), 'UTF-8'), 0, self::MAX_ERROR_LENGTH, 'UTF-8');
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

        $this->outboxStorage->markPublished($message->id);
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
            $io->error(sprintf('Stopping: the relay lock was lost (%s). Another relay may be running.', $exception->getMessage()));

            return false;
        }

        return true;
    }
}
