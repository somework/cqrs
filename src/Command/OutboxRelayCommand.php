<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Command;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use SomeWork\CqrsBundle\Contract\OutboxStorage;
use SomeWork\CqrsBundle\Outbox\DbalOutboxStorage;
use SomeWork\CqrsBundle\Outbox\Relay\OutboxRelay;
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
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

use function class_exists;
use function defined;
use function filter_var;
use function implode;
use function microtime;
use function register_shutdown_function;
use function sprintf;

use const FILTER_VALIDATE_INT;
use const SIGINT;
use const SIGTERM;

/**
 * Runs OutboxRelay from the console: validates the limit, holds the relay lock, stops after the
 * current message on SIGTERM or SIGINT, and reports the outcome.
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

    /**
     * Stored before each attempt (followed by the previous error, if any), so an attempt the
     * process does not survive still counts.
     */
    public const INTERRUPTED = OutboxRelay::INTERRUPTED;

    /** Seconds between two extensions of the relay lock (its TTL is 300 seconds). */
    private const LOCK_REFRESH_SECONDS = 10;

    /** When the relay lock was last extended. */
    private ?float $lockRefreshedAt = null;

    /** The signal that asked the run to stop after the current message. */
    private ?int $stopSignal = null;

    private bool $releasesLockOnShutdown = false;

    private readonly OutboxRelay $relay;

    /**
     * @param ContainerInterface|null  $buses       Buses keyed by message type ("command", "query", "event"); other messages use $messageBus
     * @param int                      $maxAttempts Attempts after which a failing message is given up (three times as many when its transport fails)
     * @param (\Closure(): float)|null $clock       Seconds since the epoch, microtime(true) by default (for tests)
     * @param DbalOutboxStorage|null   $table       The DBAL storage behind a decorated $outboxStorage, for the report of pending table changes
     * @param ContainerInterface|null  $transports  Messenger's transports by name; a message stored for another transport is given up at once
     */
    public function __construct(
        private readonly OutboxStorage $outboxStorage,
        SerializerInterface $serializer,
        MessageBusInterface $messageBus,
        ?LockFactory $lockFactory = null,
        ?ContainerInterface $buses = null,
        private readonly string $lockName = 'somework:cqrs:outbox:relay',
        int $maxAttempts = 10,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?\Closure $clock = null,
        private readonly ?DbalOutboxStorage $table = null,
        ?ContainerInterface $transports = null,
    ) {
        $this->relay = new OutboxRelay($outboxStorage, $serializer, $messageBus, $buses, $maxAttempts, $logger, $clock, $transports);

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

        // Finish the current message instead of leaving it half done, then stop (see OutboxRelay::run()).
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
            return $this->relayMessages($io, $limit);
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

    private function relayMessages(SymfonyStyle $io, int $limit): int
    {
        $result = $this->relay->run($limit, new ConsoleRelayReporter(
            $io,
            fn (): bool => $this->keepLock($io),
            fn (): bool => null !== $this->stopSignal,
        ));
        if ($result->aborted) {
            return self::FAILURE;
        }

        // The DBAL storage behind a decorated one still tells what the table needs.
        $table = $this->table ?? ($this->outboxStorage instanceof DbalOutboxStorage ? $this->outboxStorage : null);
        if (null !== $table) {
            $this->reportPendingChanges($table, $io);
        }

        if ($result->claimedElsewhere > 0) {
            $io->note(sprintf('Skipped %d message(s) that another relay claimed first.', $result->claimedElsewhere));
        }

        if ($result->relayed > 0) {
            $io->success(sprintf('Relayed %d message(s).', $result->relayed));
        }

        if (null !== $this->stopSignal) {
            $io->warning(sprintf('Stopped by signal %d after %d message(s); the remaining messages wait for the next run.', $this->stopSignal, $result->processed));
            $this->logger?->warning('The outbox relay stopped on signal {signal} after {count} message(s).', ['signal' => $this->stopSignal, 'count' => $result->processed]);

            return self::FAILURE;
        }

        if (0 === $result->processed) {
            if (0 === $result->claimedElsewhere) {
                $io->info('No outbox messages are due.');
            }

            return self::SUCCESS;
        }

        return 0 === $result->failed ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The automatic setup leaves indexes to the setup command: without them, every fetch reads the
     * whole table.
     */
    private function reportPendingChanges(DbalOutboxStorage $storage, SymfonyStyle $io): void
    {
        try {
            $changes = $storage->pendingChanges();
        } catch (\Throwable) {
            return;
        }

        if ([] !== $changes) {
            $io->warning(sprintf('The outbox table needs "bin/console somework:cqrs:outbox:setup": %s.', implode('; ', $changes)));
            $this->logger?->warning('The outbox table needs "bin/console somework:cqrs:outbox:setup": {changes}.', ['changes' => implode('; ', $changes)]);
        }
    }

    private function now(): float
    {
        return null === $this->clock ? microtime(true) : ($this->clock)();
    }

    private function stop(SymfonyStyle $io, string $reason, \Throwable $exception): int
    {
        $io->error(sprintf('Stopping: %s (%s).', $reason, OutboxRelay::describe($exception)));
        $this->logger?->error('The outbox relay stopped: {reason}.', ['reason' => $reason, 'exception' => $exception]);

        return self::FAILURE;
    }

    /**
     * Extends the relay lock so it cannot expire during a long run and let a second relay in:
     * every 10 seconds, not after every message, as that costs a round trip with a lock store on
     * the network (Redis, a database).
     */
    private function keepLock(SymfonyStyle $io): bool
    {
        if (null === $this->lock) {
            return true;
        }

        $now = $this->now();
        if (null !== $this->lockRefreshedAt && $now - $this->lockRefreshedAt < self::LOCK_REFRESH_SECONDS) {
            return true;
        }
        $this->lockRefreshedAt = $now;

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
